<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Model;

use InvalidArgumentException;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory;
use Magento\AsynchronousOperations\Api\SaveMultipleOperationsInterface;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Creates native Magento bulk operations for selected indexers.
 */
class ReindexScheduler
{
    public const TOPIC_NAME = 'haroone.adminreindex.indexer';
    public const ACTION_REINDEX = 'reindex';
    public const ACTION_RESET = 'reset';

    private const SCHEDULER_LOCK = 'haroone_adminreindex_schedule';

    /** @var BulkManagementInterface */
    private BulkManagementInterface $bulkManagement;

    /** @var OperationInterfaceFactory */
    private OperationInterfaceFactory $operationFactory;

    /** @var IdentityGeneratorInterface */
    private IdentityGeneratorInterface $identityGenerator;

    /** @var SerializerInterface */
    private SerializerInterface $serializer;

    /** @var UserContextInterface */
    private UserContextInterface $userContext;

    /** @var IndexerRegistry */
    private IndexerRegistry $indexerRegistry;

    /** @var CollectionFactory */
    private CollectionFactory $operationCollectionFactory;

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var SaveMultipleOperationsInterface */
    private SaveMultipleOperationsInterface $saveMultipleOperations;

    /**
     * @param BulkManagementInterface $bulkManagement
     * @param OperationInterfaceFactory $operationFactory
     * @param IdentityGeneratorInterface $identityGenerator
     * @param SerializerInterface $serializer
     * @param UserContextInterface $userContext
     * @param IndexerRegistry $indexerRegistry
     * @param CollectionFactory $operationCollectionFactory
     * @param LockManagerInterface $lockManager
     * @param SaveMultipleOperationsInterface $saveMultipleOperations
     */
    public function __construct(
        BulkManagementInterface $bulkManagement,
        OperationInterfaceFactory $operationFactory,
        IdentityGeneratorInterface $identityGenerator,
        SerializerInterface $serializer,
        UserContextInterface $userContext,
        IndexerRegistry $indexerRegistry,
        CollectionFactory $operationCollectionFactory,
        LockManagerInterface $lockManager,
        SaveMultipleOperationsInterface $saveMultipleOperations
    ) {
        $this->bulkManagement = $bulkManagement;
        $this->operationFactory = $operationFactory;
        $this->identityGenerator = $identityGenerator;
        $this->serializer = $serializer;
        $this->userContext = $userContext;
        $this->indexerRegistry = $indexerRegistry;
        $this->operationCollectionFactory = $operationCollectionFactory;
        $this->lockManager = $lockManager;
        $this->saveMultipleOperations = $saveMultipleOperations;
    }

    /**
     * Schedule registered indexers while reusing any pending module operations.
     *
     * @param array<mixed> $requestedIds
     * @param string $action
     * @return ScheduleResult
     * @throws LocalizedException
     */
    public function schedule(array $requestedIds, string $action = self::ACTION_REINDEX): ScheduleResult
    {
        if (!in_array($action, [self::ACTION_REINDEX, self::ACTION_RESET], true)) {
            throw new LocalizedException(__('The requested indexer action is not supported.'));
        }

        $indexers = $this->resolveIndexers($requestedIds);
        if ($indexers === []) {
            throw new LocalizedException(__('Please select valid indexers.'));
        }

        if (!$this->lockManager->lock(self::SCHEDULER_LOCK, 5)) {
            throw new LocalizedException(
                __('Another indexer job is being scheduled. Please wait a moment and try again.')
            );
        }

        try {
            $existingBulkByIndexer = $this->findActiveBulkByIndexer(array_keys($indexers));
            $newIndexerIds = array_values(array_diff(array_keys($indexers), array_keys($existingBulkByIndexer)));
            $bulkUuids = array_values(array_unique(array_values($existingBulkByIndexer)));

            if ($newIndexerIds !== []) {
                $bulkUuid = $this->scheduleNewBulk($newIndexerIds, $indexers, $action);
                $bulkUuids[] = $bulkUuid;
            }

            return new ScheduleResult(
                array_values(array_unique($bulkUuids)),
                array_keys($indexers),
                count($newIndexerIds),
                count($existingBulkByIndexer)
            );
        } finally {
            $this->lockManager->unlock(self::SCHEDULER_LOCK);
        }
    }

    /**
     * Create and publish operations for indexers not covered by active jobs.
     *
     * @param string[] $indexerIds
     * @param IndexerInterface[] $indexers
     * @param string $action
     * @return string
     * @throws LocalizedException
     */
    private function scheduleNewBulk(array $indexerIds, array $indexers, string $action): string
    {
        $bulkUuid = $this->identityGenerator->generateId();
        $operations = [];

        foreach ($indexerIds as $operationKey => $indexerId) {
            $label = trim((string) $indexers[$indexerId]->getTitle()) ?: $indexerId;
            $operations[] = $this->operationFactory->create(
                [
                    'data' => [
                        OperationInterface::ID => $operationKey,
                        'bulk_uuid' => $bulkUuid,
                        'topic_name' => self::TOPIC_NAME,
                        'serialized_data' => $this->serializer->serialize(
                            [
                                'indexer_id' => $indexerId,
                                'action' => $action,
                            ]
                        ),
                        'status' => OperationInterface::STATUS_TYPE_OPEN,
                        'result_message' => (string) __('Queued: %1', $label),
                    ],
                ]
            );
        }

        $description = $action === self::ACTION_RESET
            ? (string) __('Admin Reset: %1 indexer(s)', count($operations))
            : (string) __('Admin Reindex: %1 indexer(s)', count($operations));

        $scheduled = $this->bulkManagement->scheduleBulk(
            $bulkUuid,
            $operations,
            $description,
            $this->userContext->getUserId()
        );

        if (!$scheduled) {
            throw new LocalizedException(__('The indexer job could not be queued. Check the Magento logs.'));
        }

        $this->saveMultipleOperations->execute($operations);

        return $bulkUuid;
    }

    /**
     * Return one active module bulk per requested indexer.
     *
     * @param string[] $indexerIds
     * @return array<string, string>
     */
    private function findActiveBulkByIndexer(array $indexerIds): array
    {
        $requested = array_fill_keys($indexerIds, true);
        $active = [];
        $collection = $this->operationCollectionFactory->create();
        $collection->addFieldToFilter(OperationInterface::TOPIC_NAME, ['eq' => self::TOPIC_NAME]);
        $collection->addFieldToFilter(
            OperationInterface::STATUS,
            ['eq' => OperationInterface::STATUS_TYPE_OPEN]
        );

        foreach ($collection->getItems() as $operation) {
            try {
                $data = $this->serializer->unserialize((string) $operation->getSerializedData());
            } catch (InvalidArgumentException $exception) {
                continue;
            }

            $indexerId = is_array($data) ? trim((string) ($data['indexer_id'] ?? '')) : '';
            $bulkUuid = trim((string) $operation->getBulkUuid());
            if (isset($requested[$indexerId]) && $bulkUuid !== '' && !isset($active[$indexerId])) {
                $active[$indexerId] = $bulkUuid;
            }
        }

        return $active;
    }

    /**
     * Resolve, normalize, and deduplicate submitted indexer IDs.
     *
     * @param array<mixed> $requestedIds
     * @return array<string, IndexerInterface>
     */
    private function resolveIndexers(array $requestedIds): array
    {
        $indexers = [];

        foreach ($this->normalizeIndexerIds($requestedIds) as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);
            } catch (InvalidArgumentException $exception) {
                continue;
            }

            if ((string) $indexer->getId() === $indexerId) {
                $indexers[$indexerId] = $indexer;
            }
        }

        return $indexers;
    }

    /**
     * Normalize and deduplicate submitted indexer IDs.
     *
     * @param array<mixed> $requestedIds
     * @return string[]
     */
    private function normalizeIndexerIds(array $requestedIds): array
    {
        $indexerIds = [];

        foreach ($requestedIds as $requestedId) {
            if (!is_string($requestedId) && !is_int($requestedId)) {
                continue;
            }

            $indexerId = trim((string) $requestedId);
            if ($indexerId === ''
                || strlen($indexerId) > 128
                || preg_match('/^[a-zA-Z0-9_.-]+$/', $indexerId) !== 1
            ) {
                continue;
            }

            $indexerIds[$indexerId] = $indexerId;
        }

        return array_values($indexerIds);
    }
}
