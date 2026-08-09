<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Model\ReindexScheduler;
use Haroone\AdminReindex\Model\ScheduleResult;

/**
 * Schedules background indexer status resets from Index Management.
 */
class Reset extends Reindex
{
    /**
     * Return the reset queue action.
     */
    protected function getAction(): string
    {
        return ReindexScheduler::ACTION_RESET;
    }

    /**
     * Return the translated Reset label.
     */
    protected function getActionLabel(): string
    {
        return (string) __('Reset');
    }

    /**
     * Build the Reset success message for new and deduplicated operations.
     *
     * @param ScheduleResult $scheduleResult
     * @return \Magento\Framework\Phrase
     */
    protected function getSuccessMessage(ScheduleResult $scheduleResult)
    {
        if ($scheduleResult->getQueuedCount() === 0) {
            return __('This indexer operation is already queued. Showing its current progress.');
        }

        if ($scheduleResult->getExistingCount() > 0) {
            return __(
                '%1 reset(s) queued; %2 indexer(s) already had an active operation.',
                $scheduleResult->getQueuedCount(),
                $scheduleResult->getExistingCount()
            );
        }

        return __('Indexer reset was queued in the background. You may leave this page.');
    }
}
