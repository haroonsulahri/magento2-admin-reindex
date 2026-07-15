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
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReindexSchedulerTest extends TestCase
{
    private const BULK_UUID = '123e4567-e89b-12d3-a456-426614174000';

    /** @var BulkManagementInterface&MockObject */
    private BulkManagementInterface $bulkManagement;

    /** @var OperationInterfaceFactory&MockObject */
    private OperationInterfaceFactory $operationFactory;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    /** @var IndexerRegistry&MockObject */
    private IndexerRegistry $indexerRegistry;

    /** @var ReindexScheduler */
    private ReindexScheduler $scheduler;

    protected function setUp(): void
    {
        $this->bulkManagement = $this->createMock(BulkManagementInterface::class);
        $this->operationFactory = $this->createMock(OperationInterfaceFactory::class);
        $identityGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $userContext = $this->createMock(UserContextInterface::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);

        $identityGenerator->method('generateId')->willReturn(self::BULK_UUID);
        $userContext->method('getUserId')->willReturn(7);

        $this->scheduler = new ReindexScheduler(
            $this->bulkManagement,
            $this->operationFactory,
            $identityGenerator,
            $this->serializer,
            $userContext,
            $this->indexerRegistry
        );
    }

    public function testNormalizesDeduplicatesValidatesAndSchedulesOneOperationPerIndexer(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn('catalog_product_price');
        $operation = $this->createMock(OperationInterface::class);

        $this->indexerRegistry->expects($this->once())
            ->method('get')
            ->with('catalog_product_price')
            ->willReturn($indexer);
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with(['indexer_id' => 'catalog_product_price'])
            ->willReturn('{"indexer_id":"catalog_product_price"}');
        $this->operationFactory->expects($this->once())
            ->method('create')
            ->with(
                [
                    'data' => [
                        'bulk_uuid' => self::BULK_UUID,
                        'topic_name' => ReindexScheduler::TOPIC_NAME,
                        'serialized_data' => '{"indexer_id":"catalog_product_price"}',
                        'status' => OperationInterface::STATUS_TYPE_OPEN,
                    ],
                ]
            )
            ->willReturn($operation);
        $this->bulkManagement->expects($this->once())
            ->method('scheduleBulk')
            ->with(
                self::BULK_UUID,
                [$operation],
                'Admin Reindex: 1 indexer(s)',
                7
            )
            ->willReturn(true);

        $actual = $this->scheduler->schedule(
            ['catalog_product_price', 'catalog_product_price', 'bad id', ['nested'], '']
        );

        $this->assertSame(self::BULK_UUID, $actual);
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

    public function testReportsNativeBulkSchedulingFailure(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn('customer_grid');
        $operation = $this->createMock(OperationInterface::class);
        $this->indexerRegistry->method('get')->willReturn($indexer);
        $this->serializer->method('serialize')->willReturn('{}');
        $this->operationFactory->method('create')->willReturn($operation);
        $this->bulkManagement->method('scheduleBulk')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The reindex job could not be queued. Check the Magento logs.');
        $this->scheduler->schedule(['customer_grid']);
    }
}
