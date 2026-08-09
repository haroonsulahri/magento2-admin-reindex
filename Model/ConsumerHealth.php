<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Model;

use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Throwable;

/**
 * Detects a dedicated consumer or a recently healthy Magento consumer cron runner.
 */
class ConsumerHealth
{
    private const PENDING_GRACE_SECONDS = 300;
    private const RECENT_ACTIVITY_SECONDS = 600;
    private const CRON_JOB_CODE = 'consumers_runner';

    /** @var LockManagerInterface */
    private LockManagerInterface $lockManager;

    /** @var ResourceConnection */
    private ResourceConnection $resourceConnection;

    /** @var DeploymentConfig */
    private DeploymentConfig $deploymentConfig;

    /** @var DateTime */
    private DateTime $dateTime;

    /**
     * @param LockManagerInterface $lockManager
     * @param ResourceConnection $resourceConnection
     * @param DeploymentConfig $deploymentConfig
     * @param DateTime $dateTime
     */
    public function __construct(
        LockManagerInterface $lockManager,
        ResourceConnection $resourceConnection,
        DeploymentConfig $deploymentConfig,
        DateTime $dateTime
    ) {
        $this->lockManager = $lockManager;
        $this->resourceConnection = $resourceConnection;
        $this->deploymentConfig = $deploymentConfig;
        $this->dateTime = $dateTime;
    }

    /**
     * Return portable worker evidence from the consumer lock, bulk backlog, and cron history.
     *
     * @return array<string, bool|int|string|null>
     */
    public function getStatus(): array
    {
        $consumerLocked = $this->isConsumerLocked();
        $activity = $this->getQueueActivity();
        $pendingStale = $activity['pending_count'] > 0
            && !$this->isRecent($activity['oldest_pending_at'], self::PENDING_GRACE_SECONDS);
        $recentlyProcessed = $this->isRecent(
            $activity['last_processed_at'],
            self::RECENT_ACTIVITY_SECONDS
        );
        $cronRunnerRecent = $this->isCronRunnerRecent();

        $detected = $consumerLocked
            || (!$pendingStale && ($recentlyProcessed || $cronRunnerRecent));

        return [
            'detected' => $detected,
            'consumer_locked' => $consumerLocked,
            'cron_runner_recent' => $cronRunnerRecent,
            'pending_count' => $activity['pending_count'],
            'oldest_pending_at' => $activity['oldest_pending_at'],
            'last_processed_at' => $activity['last_processed_at'],
        ];
    }

    /**
     * Return whether a dedicated consumer or a healthy cron runner was detected.
     */
    public function isWorkerDetected(): bool
    {
        return (bool) $this->getStatus()['detected'];
    }

    /**
     * Check the lock held by Magento's single-thread consumer command.
     */
    private function isConsumerLocked(): bool
    {
        try {
            // Magento's StartConsumerCommand uses this exact MD5 key for its single-thread lock.
            // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative
            return $this->lockManager->isLocked(md5(ReindexScheduler::TOPIC_NAME));
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Read pending-operation age and the last processing timestamp.
     *
     * @return array{pending_count: int, oldest_pending_at: string|null, last_processed_at: string|null}
     */
    private function getQueueActivity(): array
    {
        $activity = [
            'pending_count' => 0,
            'oldest_pending_at' => null,
            'last_processed_at' => null,
        ];

        try {
            $connection = $this->resourceConnection->getConnection();
            $operationTable = $this->resourceConnection->getTableName('magento_operation');
            $bulkTable = $this->resourceConnection->getTableName('magento_bulk');
            $pendingSelect = $connection->select()
                ->from(
                    ['operation' => $operationTable],
                    ['pending_count' => 'COUNT(*)', 'oldest_pending_at' => 'MIN(bulk.start_time)']
                )
                ->join(
                    ['bulk' => $bulkTable],
                    'bulk.uuid = operation.bulk_uuid',
                    []
                )
                ->where('operation.topic_name = ?', ReindexScheduler::TOPIC_NAME)
                ->where('operation.status = ?', OperationInterface::STATUS_TYPE_OPEN);
            $pending = $connection->fetchRow($pendingSelect);

            if (is_array($pending)) {
                $activity['pending_count'] = (int) ($pending['pending_count'] ?? 0);
                $activity['oldest_pending_at'] = $this->nullableString($pending['oldest_pending_at'] ?? null);
            }

            $lastProcessedSelect = $connection->select()
                ->from(['operation' => $operationTable], ['last_processed_at' => 'MAX(started_at)'])
                ->where('operation.topic_name = ?', ReindexScheduler::TOPIC_NAME);
            $activity['last_processed_at'] = $this->nullableString(
                $connection->fetchOne($lastProcessedSelect)
            );
        } catch (Throwable $exception) {
            return $activity;
        }

        return $activity;
    }

    /**
     * Check whether Magento's consumer runner cron executed recently.
     */
    private function isCronRunnerRecent(): bool
    {
        if (!$this->isCronRunnerEnabledForConsumer()) {
            return false;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $cronScheduleTable = $this->resourceConnection->getTableName('cron_schedule');
            $select = $connection->select()
                ->from(
                    ['schedule' => $cronScheduleTable],
                    ['executed_at', 'finished_at']
                )
                ->where('schedule.job_code = ?', self::CRON_JOB_CODE)
                ->where('schedule.status IN (?)', ['running', 'success'])
                ->order('schedule.scheduled_at DESC')
                ->limit(1);
            $row = $connection->fetchRow($select);
            if (!is_array($row)) {
                return false;
            }

            $timestamp = $this->nullableString($row['finished_at'] ?? null)
                ?: $this->nullableString($row['executed_at'] ?? null);

            return $this->isRecent($timestamp, self::RECENT_ACTIVITY_SECONDS);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Check deployment configuration before trusting consumers_runner history.
     */
    private function isCronRunnerEnabledForConsumer(): bool
    {
        if (!(bool) $this->deploymentConfig->get('cron_consumers_runner/cron_run', true)) {
            return false;
        }

        $consumers = $this->deploymentConfig->get('cron_consumers_runner/consumers', []);
        if (!is_array($consumers) || $consumers === []) {
            return true;
        }

        return in_array(ReindexScheduler::TOPIC_NAME, $consumers, true);
    }

    /**
     * Compare a stored UTC timestamp with the current GMT time.
     *
     * @param string|null $timestamp
     * @param int $maximumAge
     * @return bool
     */
    private function isRecent(?string $timestamp, int $maximumAge): bool
    {
        if ($timestamp === null) {
            return false;
        }

        $timestampValue = (int) $this->dateTime->gmtTimestamp($timestamp);
        $age = $this->dateTime->gmtTimestamp() - $timestampValue;

        return $timestampValue > 0 && $age >= 0 && $age <= $maximumAge;
    }

    /**
     * Normalize database scalar results to nullable non-empty strings.
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullableString($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
