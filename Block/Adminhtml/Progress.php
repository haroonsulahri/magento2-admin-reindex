<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Block\Adminhtml;

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

    /**
     * @param Context $context
     * @param Json $json
     * @param array<mixed> $data
     */
    public function __construct(Context $context, Json $json, array $data = [])
    {
        parent::__construct($context, $data);
        $this->json = $json;
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
                    'actionId' => 'reindex_selected',
                    'initialBulkUuid' => $this->getBulkUuid(),
                    'massActionObject' => $this->getMassActionJsObjectName(),
                    'refreshUrl' => $this->getUrl('indexer/indexer/list'),
                    'statusUrl' => $this->getUrl('haroone_adminreindex/indexer/status'),
                ],
            ]
        );
    }
}
