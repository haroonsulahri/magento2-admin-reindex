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
use Magento\AsynchronousOperations\Api\BulkStatusInterface;
use Magento\AsynchronousOperations\Api\Data\BulkOperationsStatusInterface;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Api\Data\SummaryOperationStatusInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    private const BULK_UUID = '123e4567-e89b-12d3-a456-426614174000';

    /** @var RequestInterface&MockObject */
    private RequestInterface $request;

    /** @var Json&MockObject */
    private Json $jsonResult;

    /** @var BulkStatusInterface&MockObject */
    private BulkStatusInterface $bulkStatus;

    /** @var UserContextInterface&MockObject */
    private UserContextInterface $userContext;

    /** @var Status */
    private Status $controller;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->jsonResult = $this->createMock(Json::class);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $this->bulkStatus = $this->createMock(BulkStatusInterface::class);
        $this->userContext = $this->createMock(UserContextInterface::class);

        $context->method('getRequest')->willReturn($this->request);
        $jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setHttpResponseCode')->willReturnSelf();
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->request->method('getParam')->with('uuid')->willReturn(self::BULK_UUID);
        $this->userContext->method('getUserId')->willReturn(7);

        $this->controller = new Status($context, $jsonFactory, $this->bulkStatus, $this->userContext);
    }

    public function testDeclaresGetOnlyContractAndAcl(): void
    {
        $this->assertInstanceOf(HttpGetActionInterface::class, $this->controller);
        $this->assertSame('Haroone_AdminReindex::reindex', Status::ADMIN_RESOURCE);
    }

    public function testReturnsProgressCountsForCurrentAdmin(): void
    {
        $bulk = $this->createMock(BulkOperationsStatusInterface::class);
        $bulk->method('getUserId')->willReturn(7);
        $bulk->method('getOperationCount')->willReturn(4);
        $bulk->method('getOperationsList')->willReturn(
            [
                $this->operation(OperationInterface::STATUS_TYPE_COMPLETE, null, 'Reindexed: Customer Grid'),
                $this->operation(
                    OperationInterface::STATUS_TYPE_COMPLETE,
                    Consumer::ERROR_CODE_SKIPPED,
                    'Skipped because already running: Product Price'
                ),
                $this->operation(
                    OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
                    Consumer::ERROR_CODE_FAILED,
                    'Reindex failed: Catalog Search. Check exception.log.'
                ),
                $this->operation(OperationInterface::STATUS_TYPE_OPEN, null, 'Reindexing: Design Config Grid'),
            ]
        );
        $this->bulkStatus->method('getBulkShortStatus')->with(self::BULK_UUID)->willReturn($bulk);
        $this->jsonResult->expects($this->once())
            ->method('setData')
            ->with(
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
                        [
                            'message' => 'Reindexed: Customer Grid',
                            'status' => 'complete',
                        ],
                        [
                            'message' => 'Skipped because already running: Product Price',
                            'status' => 'skipped',
                        ],
                        [
                            'message' => 'Reindex failed: Catalog Search. Check exception.log.',
                            'status' => 'failed',
                        ],
                        [
                            'message' => 'Reindexing: Design Config Grid',
                            'status' => 'running',
                        ],
                    ],
                ]
            )
            ->willReturnSelf();

        $this->assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testRejectsBulkOwnedByAnotherAdmin(): void
    {
        $bulk = $this->createMock(BulkOperationsStatusInterface::class);
        $bulk->method('getUserId')->willReturn(99);
        $this->bulkStatus->method('getBulkShortStatus')->willReturn($bulk);
        $this->jsonResult->expects($this->once())->method('setHttpResponseCode')->with(403)->willReturnSelf();
        $this->jsonResult->expects($this->once())->method('setData')->with(['error' => true])->willReturnSelf();

        $this->assertSame($this->jsonResult, $this->controller->execute());
    }

    /**
     * @return SummaryOperationStatusInterface&MockObject
     */
    private function operation(int $status, ?int $errorCode, string $message): SummaryOperationStatusInterface
    {
        $operation = $this->createMock(SummaryOperationStatusInterface::class);
        $operation->method('getStatus')->willReturn($status);
        $operation->method('getErrorCode')->willReturn($errorCode);
        $operation->method('getResultMessage')->willReturn($message);

        return $operation;
    }
}
