<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Model;

/**
 * Describes newly queued work and active bulk jobs reused by the deduplication guard.
 */
class ScheduleResult
{
    /** @var string[] */
    private array $bulkUuids;

    /** @var string[] */
    private array $indexerIds;

    /** @var int */
    private int $queuedCount;

    /** @var int */
    private int $existingCount;

    /**
     * @param string[] $bulkUuids
     * @param string[] $indexerIds
     * @param int $queuedCount
     * @param int $existingCount
     */
    public function __construct(
        array $bulkUuids,
        array $indexerIds,
        int $queuedCount,
        int $existingCount
    ) {
        $this->bulkUuids = array_values($bulkUuids);
        $this->indexerIds = array_values($indexerIds);
        $this->queuedCount = $queuedCount;
        $this->existingCount = $existingCount;
    }

    /**
     * Return all new and reused bulk UUIDs required for progress tracking.
     *
     * @return string[]
     */
    public function getBulkUuids(): array
    {
        return $this->bulkUuids;
    }

    /**
     * Return the validated selected indexer IDs.
     *
     * @return string[]
     */
    public function getIndexerIds(): array
    {
        return $this->indexerIds;
    }

    /**
     * Return the number of messages published by this request.
     */
    public function getQueuedCount(): int
    {
        return $this->queuedCount;
    }

    /**
     * Return the number of selected indexers already active in the queue.
     */
    public function getExistingCount(): int
    {
        return $this->existingCount;
    }
}
