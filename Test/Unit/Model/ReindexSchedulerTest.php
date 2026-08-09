<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Test\Unit\Model;

use Haroone\AdminReindex\Model\ReindexScheduler;
use InvalidArgumentException;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory;
use Magento\AsynchronousOperations\Api\SaveMultipleOperationsInterface;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\Collection;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReindexSchedulerTest extends TestCase
{
    private const BULK_UUID = '123e4567-e89b-12d3-a456-426614174000';
    private const EXISTING_UUID = '223e4567-e89b-12d3-a456-426614174001';

    /** @var BulkManagementInterface&MockObject */
    private BulkManagementInterface $bulkManagement;

    /** @var OperationInterfaceFactory&MockObject */
    private OperationInterfaceFactory $operationFactory;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    /** @var IndexerRegistry&MockObject */
    private IndexerRegistry $indexerRegistry;

    /** @var Collection&MockObject */
    private Collection $operationCollection;

    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;

    /** @var ReindexScheduler */
    private ReindexScheduler $scheduler;

    /** @var SaveMultipleOperationsInterface&MockObject */
    private SaveMultipleOperationsInterface $saveMultipleOperations;

    /** @var OperationInterface[] */
    private array $collectionItems = [];

    /** @var bool */
    private bool $lockAcquired = true;

    protected function setUp(): void
    {
        $this->bulkManagement = $this->createMock(BulkManagementInterface::class);
        $this->operationFactory = $this->createMock(OperationInterfaceFactory::class);
        $identityGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $userContext = $this->createMock(UserContextInterface::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $this->operationCollection = $this->createMock(Collection::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->saveMultipleOperations = $this->createMock(SaveMultipleOperationsInterface::class);

        $identityGenerator->method('generateId')->willReturn(self::BULK_UUID);
        $userContext->method('getUserId')->willReturn(7);
        $collectionFactory->method('create')->willReturn($this->operationCollection);
        $this->operationCollection->method('addFieldToFilter')->willReturnSelf();
        $this->operationCollection->method('getItems')->willReturnCallback(
            fn (): array => $this->collectionItems
        );
        $this->lockManager->method('lock')->willReturnCallback(
            fn (): bool => $this->lockAcquired
        );

        $this->scheduler = new ReindexScheduler(
            $this->bulkManagement,
            $this->operationFactory,
            $identityGenerator,
            $this->serializer,
            $userContext,
            $this->indexerRegistry,
            $collectionFactory,
            $this->lockManager,
            $this->saveMultipleOperations
        );
    }

    public function testNormalizesDeduplicatesValidatesAndSchedulesOneOperationPerIndexer(): void
    {
        $indexer = $this->indexer('catalog_product_price', 'Product Price');
        $operation = $this->createMock(OperationInterface::class);

        $this->indexerRegistry->expects($this->once())
            ->method('get')
            ->with('catalog_product_price')
            ->willReturn($indexer);
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with(['indexer_id' => 'catalog_product_price', 'action' => ReindexScheduler::ACTION_REINDEX])
            ->willReturn('{"indexer_id":"catalog_product_price","action":"reindex"}');
        $this->operationFactory->expects($this->once())
            ->method('create')
            ->with(
                [
                    'data' => [
                        'operation_key' => 0,
                        'bulk_uuid' => self::BULK_UUID,
                        'topic_name' => ReindexScheduler::TOPIC_NAME,
                        'serialized_data' => '{"indexer_id":"catalog_product_price","action":"reindex"}',
                        'status' => OperationInterface::STATUS_TYPE_OPEN,
                        'result_message' => 'Queued: Product Price',
                    ],
                ]
            )
            ->willReturn($operation);
        $this->bulkManagement->expects($this->once())
            ->method('scheduleBulk')
            ->with(self::BULK_UUID, [$operation], 'Admin Reindex: 1 indexer(s)', 7)
            ->willReturn(true);
        $this->saveMultipleOperations->expects($this->once())->method('execute')->with([$operation]);
        $this->lockManager->expects($this->once())->method('unlock')->with('haroone_adminreindex_schedule');

        $result = $this->scheduler->schedule(
            ['catalog_product_price', 'catalog_product_price', 'bad id', ['nested'], '']
        );

        $this->assertSame([self::BULK_UUID], $result->getBulkUuids());
        $this->assertSame(['catalog_product_price'], $result->getIndexerIds());
        $this->assertSame(1, $result->getQueuedCount());
        $this->assertSame(0, $result->getExistingCount());
    }

    public function testResetUsesInvalidateActionMetadataAndDescription(): void
    {
        $indexer = $this->indexer('customer_grid', 'Customer Grid');
        $operation = $this->createMock(OperationInterface::class);
        $this->indexerRegistry->method('get')->willReturn($indexer);
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with(['indexer_id' => 'customer_grid', 'action' => ReindexScheduler::ACTION_RESET])
            ->willReturn('{}');
        $this->operationFactory->method('create')->willReturn($operation);
        $this->bulkManagement->expects($this->once())
            ->method('scheduleBulk')
            ->with(self::BULK_UUID, [$operation], 'Admin Reset: 1 indexer(s)', 7)
            ->willReturn(true);
        $this->saveMultipleOperations->expects($this->once())->method('execute')->with([$operation]);

        $result = $this->scheduler->schedule(['customer_grid'], ReindexScheduler::ACTION_RESET);

        $this->assertSame(1, $result->getQueuedCount());
    }

    public function testReusesExistingBulkAndQueuesOnlyMissingIndexer(): void
    {
        $existingOperation = $this->createMock(OperationInterface::class);
        $newOperation = $this->createMock(OperationInterface::class);
        $existingOperation->method('getSerializedData')->willReturn('existing-operation');
        $existingOperation->method('getBulkUuid')->willReturn(self::EXISTING_UUID);
        $this->collectionItems = [$existingOperation];
        $this->serializer->method('unserialize')->with('existing-operation')->willReturn(
            ['indexer_id' => 'customer_grid', 'action' => ReindexScheduler::ACTION_REINDEX]
        );
        $this->serializer->method('serialize')->willReturn('{}');
        $this->operationFactory->expects($this->once())->method('create')->willReturn($newOperation);
        $this->indexerRegistry->method('get')->willReturnCallback(
            fn (string $id): IndexerInterface => $this->indexer($id, $id)
        );
        $this->bulkManagement->expects($this->once())
            ->method('scheduleBulk')
            ->with(self::BULK_UUID, [$newOperation], 'Admin Reindex: 1 indexer(s)', 7)
            ->willReturn(true);
        $this->saveMultipleOperations->expects($this->once())->method('execute')->with([$newOperation]);

        $result = $this->scheduler->schedule(['customer_grid', 'catalog_product_price']);

        $this->assertSame([self::EXISTING_UUID, self::BULK_UUID], $result->getBulkUuids());
        $this->assertSame(1, $result->getQueuedCount());
        $this->assertSame(1, $result->getExistingCount());
    }

    public function testReturnsExistingProgressWithoutPublishingDuplicate(): void
    {
        $existingOperation = $this->createMock(OperationInterface::class);
        $existingOperation->method('getSerializedData')->willReturn('existing-operation');
        $existingOperation->method('getBulkUuid')->willReturn(self::EXISTING_UUID);
        $this->collectionItems = [$existingOperation];
        $this->serializer->method('unserialize')->willReturn(['indexer_id' => 'customer_grid']);
        $this->indexerRegistry->method('get')->willReturn($this->indexer('customer_grid', 'Customer Grid'));
        $this->operationFactory->expects($this->never())->method('create');
        $this->bulkManagement->expects($this->never())->method('scheduleBulk');
        $this->saveMultipleOperations->expects($this->never())->method('execute');

        $result = $this->scheduler->schedule(['customer_grid']);

        $this->assertSame([self::EXISTING_UUID], $result->getBulkUuids());
        $this->assertSame(0, $result->getQueuedCount());
        $this->assertSame(1, $result->getExistingCount());
    }

    public function testRejectsSelectionWhenNoRegisteredIndexerRemains(): void
    {
        $this->indexerRegistry->expects($this->once())
            ->method('get')
            ->with('unknown')
            ->willThrowException(new InvalidArgumentException('Unknown indexer'));
        $this->bulkManagement->expects($this->never())->method('scheduleBulk');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please select valid indexers.');
        $this->scheduler->schedule(['unknown', 'bad id']);
    }

    public function testRejectsRequestWhenSchedulingLockCannotBeAcquired(): void
    {
        $this->indexerRegistry->method('get')->willReturn($this->indexer('customer_grid', 'Customer Grid'));
        $this->lockAcquired = false;
        $this->bulkManagement->expects($this->never())->method('scheduleBulk');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Another indexer job is being scheduled.');
        $this->scheduler->schedule(['customer_grid']);
    }

    public function testReportsNativeBulkSchedulingFailure(): void
    {
        $this->indexerRegistry->method('get')->willReturn($this->indexer('customer_grid', 'Customer Grid'));
        $this->serializer->method('serialize')->willReturn('{}');
        $this->operationFactory->method('create')->willReturn($this->createMock(OperationInterface::class));
        $this->bulkManagement->method('scheduleBulk')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The indexer job could not be queued. Check the Magento logs.');
        $this->scheduler->schedule(['customer_grid']);
    }

    /**
     * @return IndexerInterface&MockObject
     */
    private function indexer(string $id, string $title): IndexerInterface
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getTitle')->willReturn($title);

        return $indexer;
    }
}
