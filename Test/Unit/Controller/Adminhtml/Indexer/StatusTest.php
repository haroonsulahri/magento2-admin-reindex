<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Test\Unit\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Controller\Adminhtml\Indexer\Status;
use Haroone\AdminReindex\Model\Consumer;
use Haroone\AdminReindex\Model\ReindexScheduler;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\Collection;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    private const BULK_UUID = '123e4567-e89b-12d3-a456-426614174000';
    private const SECOND_UUID = '223e4567-e89b-12d3-a456-426614174001';

    /** @var RequestInterface&MockObject */
    private RequestInterface $request;

    /** @var Json&MockObject */
    private Json $json;

    /** @var Collection&MockObject */
    private Collection $collection;

    /** @var SerializerInterface&MockObject */
    private SerializerInterface $serializer;

    /** @var Status */
    private Status $controller;

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, mixed> */
    private array $jsonData = [];

    /** @var int */
    private int $jsonStatus = 200;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $this->request = $this->createMock(RequestInterface::class);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $this->json = $this->createMock(Json::class);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collection = $this->createMock(Collection::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );
        $context->method('getRequest')->willReturn($this->request);
        $jsonFactory->method('create')->willReturn($this->json);
        $collectionFactory->method('create')->willReturn($this->collection);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();
        $this->json->method('setHttpResponseCode')->willReturnCallback(
            function (int $status): Json {
                $this->jsonStatus = $status;
                return $this->json;
            }
        );
        $this->json->method('setData')->willReturnCallback(
            function (array $data): Json {
                $this->jsonData = $data;
                return $this->json;
            }
        );

        $this->controller = new Status(
            $context,
            $jsonFactory,
            $collectionFactory,
            $this->serializer
        );
    }

    public function testDeclaresGetOnlyContractAndAcl(): void
    {
        $this->assertInstanceOf(HttpGetActionInterface::class, $this->controller);
        $this->assertSame('Haroone_AdminReindex::reindex', Status::ADMIN_RESOURCE);
    }

    public function testAggregatesModuleOperationsAcrossBulksAndFiltersIndexerIds(): void
    {
        $this->params = [
            'uuids' => self::BULK_UUID . ',' . self::SECOND_UUID,
            'indexer_ids' => 'customer_grid,catalog_product_price,catalogsearch_fulltext,design_config_grid',
        ];
        $operations = [
            $this->operation(
                'customer_grid',
                OperationInterface::STATUS_TYPE_COMPLETE,
                null,
                'Reindexed: Customer Grid'
            ),
            $this->operation(
                'catalog_product_price',
                OperationInterface::STATUS_TYPE_COMPLETE,
                Consumer::ERROR_CODE_SKIPPED,
                'Skipped because already running: Product Price'
            ),
            $this->operation(
                'catalogsearch_fulltext',
                OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
                Consumer::ERROR_CODE_FAILED,
                'Reindex failed: Catalog Search. Check exception.log.'
            ),
            $this->operation(
                'design_config_grid',
                OperationInterface::STATUS_TYPE_OPEN,
                null,
                'Resetting: Design Config Grid'
            ),
        ];
        $this->serializer->method('unserialize')->willReturnCallback(
            static fn (string $value): array => [
                'indexer_id' => str_replace('serialized-', '', $value),
            ]
        );
        $this->collection->method('getItems')->willReturn($operations);
        $filters = [];
        $this->collection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(
                function (string $field, array $condition) use (&$filters): Collection {
                    $filters[] = [$field, $condition];
                    return $this->collection;
                }
            );

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(
            [
                [OperationInterface::BULK_ID, ['in' => [self::BULK_UUID, self::SECOND_UUID]]],
                [OperationInterface::TOPIC_NAME, ['eq' => ReindexScheduler::TOPIC_NAME]],
            ],
            $filters
        );
        $this->assertSame(
            [
                'error' => false,
                'total' => 4,
                'finished' => 3,
                'successful' => 1,
                'skipped' => 1,
                'failed' => 1,
                'pending' => 1,
                'percent' => 75,
                'done' => false,
                'results' => [
                    ['message' => 'Reindexed: Customer Grid', 'status' => 'complete'],
                    ['message' => 'Skipped because already running: Product Price', 'status' => 'skipped'],
                    ['message' => 'Reindex failed: Catalog Search. Check exception.log.', 'status' => 'failed'],
                    ['message' => 'Resetting: Design Config Grid', 'status' => 'running'],
                ],
            ],
            $this->jsonData
        );
    }

    public function testRejectsInvalidBulkUuid(): void
    {
        $this->params = ['uuids' => 'not-a-uuid'];
        $this->collection->expects($this->never())->method('getItems');

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(400, $this->jsonStatus);
        $this->assertSame(['error' => true], $this->jsonData);
    }

    public function testReturnsNotFoundWhenNoModuleOperationMatches(): void
    {
        $this->params = ['uuid' => self::BULK_UUID];
        $this->collection->method('getItems')->willReturn([]);

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(404, $this->jsonStatus);
        $this->assertSame(['error' => true], $this->jsonData);
    }

    /**
     * @return OperationInterface&MockObject
     */
    private function operation(
        string $indexerId,
        int $status,
        ?int $errorCode,
        string $message
    ): OperationInterface {
        $operation = $this->createMock(OperationInterface::class);
        $serialized = 'serialized-' . $indexerId;
        $operation->method('getSerializedData')->willReturn($serialized);
        $operation->method('getStatus')->willReturn($status);
        $operation->method('getErrorCode')->willReturn($errorCode);
        $operation->method('getResultMessage')->willReturn($message);

        return $operation;
    }
}
