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
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Creates one native Magento bulk operation per selected indexer.
 */
class ReindexScheduler
{
    public const TOPIC_NAME = 'haroone.adminreindex.indexer';

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

    /**
     * @param BulkManagementInterface $bulkManagement
     * @param OperationInterfaceFactory $operationFactory
     * @param IdentityGeneratorInterface $identityGenerator
     * @param SerializerInterface $serializer
     * @param UserContextInterface $userContext
     * @param IndexerRegistry $indexerRegistry
     */
    public function __construct(
        BulkManagementInterface $bulkManagement,
        OperationInterfaceFactory $operationFactory,
        IdentityGeneratorInterface $identityGenerator,
        SerializerInterface $serializer,
        UserContextInterface $userContext,
        IndexerRegistry $indexerRegistry
    ) {
        $this->bulkManagement = $bulkManagement;
        $this->operationFactory = $operationFactory;
        $this->identityGenerator = $identityGenerator;
        $this->serializer = $serializer;
        $this->userContext = $userContext;
        $this->indexerRegistry = $indexerRegistry;
    }

    /**
     * Schedule registered indexers as native bulk operations.
     *
     * @param array<mixed> $requestedIds
     * @return string
     * @throws LocalizedException
     */
    public function schedule(array $requestedIds): string
    {
        $bulkUuid = $this->identityGenerator->generateId();
        $operations = [];

        foreach ($this->normalizeIndexerIds($requestedIds) as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);
            } catch (InvalidArgumentException $exception) {
                continue;
            }

            if ((string) $indexer->getId() !== $indexerId) {
                continue;
            }

            $operations[] = $this->operationFactory->create(
                [
                    'data' => [
                        'bulk_uuid' => $bulkUuid,
                        'topic_name' => self::TOPIC_NAME,
                        'serialized_data' => $this->serializer->serialize(['indexer_id' => $indexerId]),
                        'status' => OperationInterface::STATUS_TYPE_OPEN,
                    ],
                ]
            );
        }

        if ($operations === []) {
            throw new LocalizedException(__('Please select valid indexers.'));
        }

        $scheduled = $this->bulkManagement->scheduleBulk(
            $bulkUuid,
            $operations,
            (string) __('Admin Reindex: %1 indexer(s)', count($operations)),
            $this->userContext->getUserId()
        );

        if (!$scheduled) {
            throw new LocalizedException(__('The reindex job could not be queued. Check the Magento logs.'));
        }

        return $bulkUuid;
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
