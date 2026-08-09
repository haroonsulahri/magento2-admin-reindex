<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Test\Unit\Model;

use Haroone\AdminReindex\Model\ConsumerHealth;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsumerHealthTest extends TestCase
{
    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;

    /** @var AdapterInterface&MockObject */
    private AdapterInterface $connection;

    /** @var DeploymentConfig&MockObject */
    private DeploymentConfig $deploymentConfig;

    /** @var ConsumerHealth */
    private ConsumerHealth $consumerHealth;

    protected function setUp(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $dateTime = $this->createMock(DateTime::class);

        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn (string $table): string => $table
        );
        $this->connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->deploymentConfig->method('get')->willReturnCallback(
            static function (string $path, $default = null) {
                return $default;
            }
        );
        $dateTime->method('gmtTimestamp')->willReturnCallback(
            static function ($value = null): int {
                if ($value === null) {
                    return 1000;
                }

                return $value === 'recent' ? 950 : 100;
            }
        );

        $this->consumerHealth = new ConsumerHealth(
            $this->lockManager,
            $resourceConnection,
            $this->deploymentConfig,
            $dateTime
        );
    }

    public function testDedicatedConsumerLockIsAuthoritativeEvenWithOldBacklog(): void
    {
        $this->lockManager->method('isLocked')->willReturn(true);
        $this->setActivity(2, 'old', 'old', false);

        $status = $this->consumerHealth->getStatus();

        $this->assertTrue($status['detected']);
        $this->assertTrue($status['consumer_locked']);
        $this->assertSame(2, $status['pending_count']);
    }

    public function testRecentConsumerCronCountsAsHealthyWhenQueueIsNotStale(): void
    {
        $this->lockManager->method('isLocked')->willReturn(false);
        $this->setActivity(0, null, null, ['finished_at' => 'recent', 'executed_at' => null]);

        $status = $this->consumerHealth->getStatus();

        $this->assertTrue($status['detected']);
        $this->assertTrue($status['cron_runner_recent']);
    }

    public function testStaleBacklogOverridesRecentCronHistory(): void
    {
        $this->lockManager->method('isLocked')->willReturn(false);
        $this->setActivity(1, 'old', null, ['finished_at' => 'recent', 'executed_at' => null]);

        $status = $this->consumerHealth->getStatus();

        $this->assertFalse($status['detected']);
        $this->assertSame('old', $status['oldest_pending_at']);
    }

    public function testMissingLockActivityAndCronReportsWorkerNotDetected(): void
    {
        $this->lockManager->method('isLocked')->willReturn(false);
        $this->setActivity(0, null, null, false);

        $this->assertFalse($this->consumerHealth->isWorkerDetected());
    }

    /**
     * @param int $pendingCount
     * @param string|null $oldestPendingAt
     * @param string|null $lastProcessedAt
     * @param array<string, string|null>|false $cronRow
     */
    private function setActivity(
        int $pendingCount,
        ?string $oldestPendingAt,
        ?string $lastProcessedAt,
        $cronRow
    ): void {
        $this->connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            [
                'pending_count' => $pendingCount,
                'oldest_pending_at' => $oldestPendingAt,
            ],
            $cronRow
        );
        $this->connection->method('fetchOne')->willReturn($lastProcessedAt);
    }
}
