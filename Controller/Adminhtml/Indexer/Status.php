<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Model\Consumer;
use Haroone\AdminReindex\Model\ReindexScheduler;
use InvalidArgumentException;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Returns progress for one or more module-owned bulk operations.
 */
class Status extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Haroone_AdminReindex::reindex';

    /** @var JsonFactory */
    private JsonFactory $resultJsonFactory;

    /** @var CollectionFactory */
    private CollectionFactory $operationCollectionFactory;

    /** @var SerializerInterface */
    private SerializerInterface $serializer;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CollectionFactory $operationCollectionFactory
     * @param SerializerInterface $serializer
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CollectionFactory $operationCollectionFactory,
        SerializerInterface $serializer
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->operationCollectionFactory = $operationCollectionFactory;
        $this->serializer = $serializer;
    }

    /**
     * Return progress for module operations selected by the authorized admin.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $bulkUuids = $this->getBulkUuids();
        if ($bulkUuids === []) {
            return $result->setHttpResponseCode(400)->setData(['error' => true]);
        }

        $requestedIndexerIds = $this->getIndexerIds();
        $requestedIndexerMap = array_fill_keys($requestedIndexerIds, true);
        $collection = $this->operationCollectionFactory->create();
        $collection->addFieldToFilter(OperationInterface::BULK_ID, ['in' => $bulkUuids]);
        $collection->addFieldToFilter(
            OperationInterface::TOPIC_NAME,
            ['eq' => ReindexScheduler::TOPIC_NAME]
        );
        $collection->setOrder('id', 'DESC');

        $operationsByIndexer = [];
        foreach ($collection->getItems() as $operation) {
            try {
                $data = $this->serializer->unserialize((string) $operation->getSerializedData());
            } catch (InvalidArgumentException $exception) {
                continue;
            }

            $indexerId = is_array($data) ? trim((string) ($data['indexer_id'] ?? '')) : '';
            if (!$this->isValidIndexerId($indexerId)
                || ($requestedIndexerMap !== [] && !isset($requestedIndexerMap[$indexerId]))
                || isset($operationsByIndexer[$indexerId])
            ) {
                continue;
            }

            $operationsByIndexer[$indexerId] = $operation;
        }

        if ($operationsByIndexer === []) {
            return $result->setHttpResponseCode(404)->setData(['error' => true]);
        }

        if ($requestedIndexerIds !== []) {
            $operationsByIndexer = $this->sortByRequestedIds($operationsByIndexer, $requestedIndexerIds);
        }

        return $result->setData($this->buildProgress($operationsByIndexer));
    }

    /**
     * Convert operation entities into the modal's aggregate progress response.
     *
     * @param OperationInterface[] $operationsByIndexer
     * @return array<string, int|bool|array<int, array<string, string>>>
     */
    private function buildProgress(array $operationsByIndexer): array
    {
        $successful = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        foreach ($operationsByIndexer as $indexerId => $operation) {
            $status = (int) $operation->getStatus();
            $resultStatus = 'running';
            if ($status === OperationInterface::STATUS_TYPE_COMPLETE) {
                if ((int) $operation->getErrorCode() === Consumer::ERROR_CODE_SKIPPED) {
                    ++$skipped;
                    $resultStatus = 'skipped';
                } else {
                    ++$successful;
                    $resultStatus = 'complete';
                }
            } elseif ($status === OperationInterface::STATUS_TYPE_RETRIABLY_FAILED
                || $status === OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED
                || $status === OperationInterface::STATUS_TYPE_REJECTED
            ) {
                ++$failed;
                $resultStatus = 'failed';
            }

            $message = trim((string) $operation->getResultMessage());
            $results[] = [
                'message' => $message !== '' ? $message : (string) __('Queued: %1', $indexerId),
                'status' => $resultStatus,
            ];
        }

        $total = count($operationsByIndexer);
        $finished = $successful + $skipped + $failed;
        $pending = max(0, $total - $finished);

        return [
            'error' => false,
            'total' => $total,
            'finished' => $finished,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => $failed,
            'pending' => $pending,
            'percent' => $total > 0 ? (int) floor(($finished / $total) * 100) : 100,
            'done' => $pending === 0,
            'results' => $results,
        ];
    }

    /**
     * Normalize up to 100 requested bulk UUIDs.
     *
     * @return string[]
     */
    private function getBulkUuids(): array
    {
        $value = $this->getRequest()->getParam('uuids', $this->getRequest()->getParam('uuid', ''));
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $bulkUuids = [];

        foreach (array_slice($values, 0, 100) as $bulkUuid) {
            $bulkUuid = trim((string) $bulkUuid);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $bulkUuid) === 1) {
                $bulkUuids[$bulkUuid] = $bulkUuid;
            }
        }

        return array_values($bulkUuids);
    }

    /**
     * Normalize up to 100 requested indexer IDs.
     *
     * @return string[]
     */
    private function getIndexerIds(): array
    {
        $value = $this->getRequest()->getParam('indexer_ids', '');
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $indexerIds = [];

        foreach (array_slice($values, 0, 100) as $indexerId) {
            $indexerId = trim((string) $indexerId);
            if ($this->isValidIndexerId($indexerId)) {
                $indexerIds[$indexerId] = $indexerId;
            }
        }

        return array_values($indexerIds);
    }

    /**
     * Validate an indexer identifier before using it as an array key.
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

    /**
     * Preserve the selected grid order in the progress response.
     *
     * @param OperationInterface[] $operationsByIndexer
     * @param string[] $requestedIndexerIds
     * @return OperationInterface[]
     */
    private function sortByRequestedIds(array $operationsByIndexer, array $requestedIndexerIds): array
    {
        $sorted = [];
        foreach ($requestedIndexerIds as $indexerId) {
            if (isset($operationsByIndexer[$indexerId])) {
                $sorted[$indexerId] = $operationsByIndexer[$indexerId];
            }
        }

        return $sorted;
    }
}
