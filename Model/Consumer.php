<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Model;

use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\Framework\Bulk\OperationManagementInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\EntityManager;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Processes queued indexer operations sequentially and records native bulk progress.
 */
class Consumer
{
    public const ERROR_CODE_SKIPPED = 1001;
    public const ERROR_CODE_FAILED = 1002;

    private const LOCK_NAME = 'haroone_adminreindex';

    /** @var SerializerInterface */
    private SerializerInterface $serializer;

    /** @var IndexerRegistry */
    private IndexerRegistry $indexerRegistry;

    /** @var EntityManager */
    private EntityManager $entityManager;

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var OperationManagementInterface */
    private OperationManagementInterface $operationManagement;

    /** @var ResourceConnection */
    private ResourceConnection $resourceConnection;

    /** @var DateTime */
    private DateTime $dateTime;

    /**
     * @param SerializerInterface $serializer
     * @param IndexerRegistry $indexerRegistry
     * @param EntityManager $entityManager
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     * @param OperationManagementInterface $operationManagement
     * @param ResourceConnection $resourceConnection
     * @param DateTime $dateTime
     */
    public function __construct(
        SerializerInterface $serializer,
        IndexerRegistry $indexerRegistry,
        EntityManager $entityManager,
        LockManagerInterface $lockManager,
        LoggerInterface $logger,
        OperationManagementInterface $operationManagement,
        ResourceConnection $resourceConnection,
        DateTime $dateTime
    ) {
        $this->serializer = $serializer;
        $this->indexerRegistry = $indexerRegistry;
        $this->entityManager = $entityManager;
        $this->lockManager = $lockManager;
        $this->logger = $logger;
        $this->operationManagement = $operationManagement;
        $this->resourceConnection = $resourceConnection;
        $this->dateTime = $dateTime;
    }

    /**
     * Process one queued indexer action and update its bulk operation.
     *
     * @param OperationInterface $operation
     * @return void
     */
    public function process(OperationInterface $operation): void
    {
        $indexerId = '';
        $action = ReindexScheduler::ACTION_REINDEX;
        $label = (string) __('Unknown indexer');
        $locked = false;
        $status = OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;
        $errorCode = self::ERROR_CODE_FAILED;
        $message = (string) __('Indexer operation failed. Check exception.log.');

        try {
            $data = $this->serializer->unserialize((string) $operation->getSerializedData());
            $indexerId = is_array($data) ? trim((string) ($data['indexer_id'] ?? '')) : '';
            $action = is_array($data)
                ? trim((string) ($data['action'] ?? ReindexScheduler::ACTION_REINDEX))
                : '';
            if (!$this->isValidIndexerId($indexerId)) {
                throw new RuntimeException('The queued indexer ID is invalid.');
            }
            if (!in_array($action, [ReindexScheduler::ACTION_REINDEX, ReindexScheduler::ACTION_RESET], true)) {
                throw new RuntimeException('The queued indexer action is invalid.');
            }

            $indexer = $this->indexerRegistry->get($indexerId);
            $label = trim((string) $indexer->getTitle()) ?: $indexerId;
            $locked = $this->lockManager->lock(self::LOCK_NAME);
            if (!$locked) {
                throw new RuntimeException('The reindex worker lock could not be acquired.');
            }

            $this->markOperationStarted($operation);

            if ($indexer->isWorking()) {
                $status = OperationInterface::STATUS_TYPE_COMPLETE;
                $errorCode = self::ERROR_CODE_SKIPPED;
                $message = (string) __('Skipped because already running: %1', $label);
            } else {
                if ($action === ReindexScheduler::ACTION_RESET) {
                    $this->updateOperation(
                        $operation,
                        OperationInterface::STATUS_TYPE_OPEN,
                        null,
                        (string) __('Resetting: %1', $label)
                    );
                    $indexer->invalidate();
                    $message = (string) __('Reset to invalid: %1', $label);
                } else {
                    $this->updateOperation(
                        $operation,
                        OperationInterface::STATUS_TYPE_OPEN,
                        null,
                        (string) __('Reindexing: %1', $label)
                    );
                    $indexer->reindexAll();
                    $message = (string) __('Reindexed: %1', $label);
                }

                $status = OperationInterface::STATUS_TYPE_COMPLETE;
                $errorCode = null;
            }
        } catch (Throwable $exception) {
            $this->logger->error(
                'Background admin reindex failed.',
                [
                    'indexer_id' => $indexerId,
                    'action' => $action,
                    'exception' => $exception,
                ]
            );
            $message = $action === ReindexScheduler::ACTION_RESET
                ? (string) __('Reset failed: %1. Check exception.log.', $label)
                : (string) __('Reindex failed: %1. Check exception.log.', $label);
        } finally {
            if ($locked) {
                $this->lockManager->unlock(self::LOCK_NAME);
            }

            $this->updateOperation($operation, $status, $errorCode, $message);
        }
    }

    /**
     * Persist operation progress through Magento's public bulk API.
     *
     * @param OperationInterface $operation
     * @param int $status
     * @param int|null $errorCode
     * @param string $message
     * @return void
     * @throws RuntimeException
     */
    private function updateOperation(
        OperationInterface $operation,
        int $status,
        ?int $errorCode,
        string $message
    ): void {
        $operation->setStatus($status)
            ->setErrorCode($errorCode)
            ->setResultMessage($message);

        if ($operation->getId() === null) {
            $this->entityManager->save($operation);
            return;
        }

        $updated = $this->operationManagement->changeOperationStatus(
            $operation->getBulkUuid(),
            $operation->getId(),
            $status,
            $errorCode,
            $message,
            $operation->getSerializedData(),
            $operation->getResultSerializedData()
        );
        if (!$updated) {
            throw new RuntimeException('The bulk operation status could not be updated.');
        }
    }

    /**
     * Record processing activity for worker-health checks.
     *
     * @param OperationInterface $operation
     * @return void
     */
    private function markOperationStarted(OperationInterface $operation): void
    {
        if ($operation->getId() === null) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('magento_operation'),
            ['started_at' => $connection->formatDate($this->dateTime->gmtTimestamp())],
            [
                'bulk_uuid = ?' => $operation->getBulkUuid(),
                'operation_key = ?' => $operation->getId(),
            ]
        );
    }

    /**
     * Validate the queued identifier before resolving an indexer.
     *
     * @param string $indexerId
     * @return bool
     */
    private function isValidIndexerId(string $indexerId): bool
    {
        return $indexerId !== ''
            && strlen($indexerId) <= 128
            && preg_match('/^[a-zA-Z0-9_.-]+$/', $indexerId) === 1;
    }
}
