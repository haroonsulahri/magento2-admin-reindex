<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Block\Adminhtml;

use Haroone\AdminReindex\Model\ConsumerHealth;
use Haroone\AdminReindex\Model\ReindexScheduler;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Supplies the mass-action and progress URLs to the background progress modal.
 */
class Progress extends Template
{
    private const MASS_ACTION_BLOCK = 'adminhtml.indexer.grid.grid.massaction';

    /** @var Json */
    private Json $json;

    /** @var ConsumerHealth */
    private ConsumerHealth $consumerHealth;

    /** @var bool|null */
    private ?bool $workerDetected = null;

    /**
     * @param Context $context
     * @param Json $json
     * @param ConsumerHealth $consumerHealth
     * @param array<mixed> $data
     */
    public function __construct(
        Context $context,
        Json $json,
        ConsumerHealth $consumerHealth,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->json = $json;
        $this->consumerHealth = $consumerHealth;
    }

    /**
     * Return a validated bulk UUID from a non-JavaScript redirect fallback.
     *
     * @return string
     */
    public function getBulkUuid(): string
    {
        $bulkUuid = trim((string) $this->getRequest()->getParam('bulk_uuid'));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $bulkUuid) === 1
            ? $bulkUuid
            : '';
    }

    /**
     * Return validated bulk UUIDs for new and deduplicated operations.
     *
     * @return string[]
     */
    public function getBulkUuids(): array
    {
        $value = $this->getRequest()->getParam('bulk_uuids', $this->getBulkUuid());
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
     * Return validated indexer IDs from a non-JavaScript redirect fallback.
     *
     * @return string[]
     */
    public function getIndexerIds(): array
    {
        $value = $this->getRequest()->getParam('indexer_ids', '');
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $indexerIds = [];

        foreach (array_slice($values, 0, 100) as $indexerId) {
            $indexerId = trim((string) $indexerId);
            if ($indexerId !== ''
                && strlen($indexerId) <= 128
                && preg_match('/^[a-zA-Z0-9_.-]+$/', $indexerId) === 1
            ) {
                $indexerIds[$indexerId] = $indexerId;
            }
        }

        return array_values($indexerIds);
    }

    /**
     * Return the requested action for a non-JavaScript redirect fallback.
     */
    public function getOperation(): string
    {
        $operation = trim((string) $this->getRequest()->getParam('operation'));

        return in_array(
            $operation,
            [ReindexScheduler::ACTION_REINDEX, ReindexScheduler::ACTION_RESET],
            true
        ) ? $operation : ReindexScheduler::ACTION_REINDEX;
    }

    /**
     * Return the cached worker-health result for the banner and JavaScript config.
     */
    public function isWorkerDetected(): bool
    {
        if ($this->workerDetected === null) {
            $this->workerDetected = $this->consumerHealth->isWorkerDetected();
        }

        return $this->workerDetected;
    }

    /**
     * Return the worker warning displayed in Index Management and the modal.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getWorkerWarning()
    {
        return __('Background worker not detected — jobs will queue but won\'t run until the consumer is started.');
    }

    /**
     * Return the native indexer mass-action JavaScript object name.
     *
     * @return string
     */
    public function getMassActionJsObjectName(): string
    {
        $massAction = $this->getLayout()->getBlock(self::MASS_ACTION_BLOCK);

        return $massAction && method_exists($massAction, 'getJsObjectName')
            ? (string) $massAction->getJsObjectName()
            : '';
    }

    /**
     * Return the data-mage-init configuration source.
     *
     * @return string
     */
    public function getMageInit(): string
    {
        return $this->json->serialize(
            [
                'Haroone_AdminReindex/js/progress' => [
                    'actionIds' => ['reindex_selected', 'reset_selected'],
                    'initialAction' => $this->getOperation(),
                    'initialBulkUuids' => $this->getBulkUuids(),
                    'initialIndexerIds' => $this->getIndexerIds(),
                    'massActionObject' => $this->getMassActionJsObjectName(),
                    'refreshUrl' => $this->getUrl('indexer/indexer/list'),
                    'statusUrl' => $this->getUrl('haroone_adminreindex/indexer/status'),
                    'workerDetected' => $this->isWorkerDetected(),
                    'workerWarning' => (string) $this->getWorkerWarning(),
                ],
            ]
        );
    }
}
