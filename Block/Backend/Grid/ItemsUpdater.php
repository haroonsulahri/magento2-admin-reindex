<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Block\Backend\Grid;

use Magento\Indexer\Block\Backend\Grid\ItemsUpdater as CoreItemsUpdater;

/**
 * Applies core restrictions and replaces native invalidate with background Reindex and Reset.
 */
class ItemsUpdater extends CoreItemsUpdater
{
    private const ACL_RESOURCE = 'Haroone_AdminReindex::reindex';

    /**
     * @inheritDoc
     */
    public function update($argument)
    {
        $argument = parent::update($argument);

        if (is_array($argument)) {
            unset($argument['invalidate_index']);

            if (!$this->authorization->isAllowed(self::ACL_RESOURCE)) {
                unset($argument['reindex_selected'], $argument['reset_selected']);
            }
        }

        return $argument;
    }
}
