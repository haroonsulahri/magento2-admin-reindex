<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Test\Unit\Model;

use Haroone\AdminReindex\Model\Consumer;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\Framework\EntityManager\EntityManager;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ConsumerTest extends TestCase
{
    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    /** @var IndexerRegistry&MockObject */
    private IndexerRegistry $indexerRegistry;

    /** @var EntityManager&MockObject */
    private EntityManager $entityManager;

    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var Consumer */
    private Consumer $consumer;

    /** @var OperationInterface&MockObject */
    private OperationInterface $operation;

    /** @var array<int, array<int, int|string|null>> */
    private array $operationUpdates = [];

    /** @var int|null */
    private ?int $currentStatus = null;

    /** @var int|null */
    private ?int $currentErrorCode = null;

    /** @var string|null */
    private ?string $currentMessage = null;

    protected function setUp(): void
    {
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->operation = $this->createMock(OperationInterface::class);

        $this->operation->method('getSerializedData')->willReturn('{"indexer_id":"customer_grid"}');
        $this->operation->method('setStatus')->willReturnCallback(function (int $status) {
            $this->currentStatus = $status;
            return $this->operation;
        });
        $this->operation->method('setErrorCode')->willReturnCallback(function (?int $errorCode) {
            $this->currentErrorCode = $errorCode;
            return $this->operation;
        });
        $this->operation->method('setResultMessage')->willReturnCallback(function (string $message) {
            $this->currentMessage = $message;
            return $this->operation;
        });
        $this->entityManager->method('save')->willReturnCallback(function ($operation) {
            $this->assertSame($this->operation, $operation);
            $this->operationUpdates[] = [
                $this->currentStatus,
                $this->currentErrorCode,
                $this->currentMessage,
            ];

            return $operation;
        });

        $this->consumer = new Consumer(
            $this->serializer,
            $this->indexerRegistry,
            $this->entityManager,
            $this->lockManager,
            $this->logger
        );
    }

    public function testReindexesAndPersistsRunningThenSuccessfulStatus(): void
    {
        $indexer = $this->createIndexer('Customer Grid', false);
        $this->serializer->method('unserialize')->willReturn(['indexer_id' => 'customer_grid']);
        $this->indexerRegistry->method('get')->with('customer_grid')->willReturn($indexer);
        $this->lockManager->expects($this->once())->method('lock')->with('haroone_adminreindex')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock')->with('haroone_adminreindex')->willReturn(true);
        $indexer->expects($this->once())->method('reindexAll');
        $this->logger->expects($this->never())->method('error');

        $this->consumer->process($this->operation);

        $this->assertSame(
            [
                $this->expectedUpdate(OperationInterface::STATUS_TYPE_OPEN, null, 'Reindexing: Customer Grid'),
                $this->expectedUpdate(OperationInterface::STATUS_TYPE_COMPLETE, null, 'Reindexed: Customer Grid'),
            ],
            $this->operationUpdates
        );
    }

    public function testSkipsWorkingIndexerAndReleasesLock(): void
    {
        $indexer = $this->createIndexer('Customer Grid', true);
        $this->serializer->method('unserialize')->willReturn(['indexer_id' => 'customer_grid']);
        $this->indexerRegistry->method('get')->willReturn($indexer);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock')->willReturn(true);
        $indexer->expects($this->never())->method('reindexAll');

        $this->consumer->process($this->operation);

        $this->assertSame(
            [
                $this->expectedUpdate(
                    OperationInterface::STATUS_TYPE_COMPLETE,
                    Consumer::ERROR_CODE_SKIPPED,
                    'Skipped because already running: Customer Grid'
                ),
            ],
            $this->operationUpdates
        );
    }

    public function testLogsFailurePersistsItAndReturnsForNextQueueMessage(): void
    {
        $indexer = $this->createIndexer('Customer Grid', false);
        $exception = new RuntimeException('Private database details');
        $this->serializer->method('unserialize')->willReturn(['indexer_id' => 'customer_grid']);
        $this->indexerRegistry->method('get')->willReturn($indexer);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock')->willReturn(true);
        $indexer->method('reindexAll')->willThrowException($exception);
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Background admin reindex failed.',
                $this->callback(
                    static fn (array $context): bool => $context['indexer_id'] === 'customer_grid'
                        && $context['exception'] === $exception
                )
            );

        $this->consumer->process($this->operation);

        $this->assertSame(
            [
                $this->expectedUpdate(OperationInterface::STATUS_TYPE_OPEN, null, 'Reindexing: Customer Grid'),
                $this->expectedUpdate(
                    OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
                    Consumer::ERROR_CODE_FAILED,
                    'Reindex failed: Customer Grid. Check exception.log.'
                ),
            ],
            $this->operationUpdates
        );
    }

    /**
     * @param int $status
     * @param int|null $errorCode
     * @param string $message
     * @return array<int, int|string|null>
     */
    private function expectedUpdate(int $status, ?int $errorCode, string $message): array
    {
        return [
            $status,
            $errorCode,
            $message,
        ];
    }

    /**
     * @return IndexerInterface&MockObject
     */
    private function createIndexer(string $title, bool $working): IndexerInterface
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getTitle')->willReturn($title);
        $indexer->method('isWorking')->willReturn($working);

        return $indexer;
    }
}
