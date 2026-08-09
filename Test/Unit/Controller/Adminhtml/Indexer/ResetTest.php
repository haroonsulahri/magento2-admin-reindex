<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Test\Unit\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Controller\Adminhtml\Indexer\Reset;
use Haroone\AdminReindex\Model\ConsumerHealth;
use Haroone\AdminReindex\Model\ReindexScheduler;
use Haroone\AdminReindex\Model\ScheduleResult;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResetTest extends TestCase
{
    private const BULK_UUID = '123e4567-e89b-12d3-a456-426614174000';

    public function testSchedulesResetThroughSamePostAclAndQueuePath(): void
    {
        $context = $this->createMock(Context::class);
        $request = $this->createMock(RequestInterface::class);
        $scheduler = $this->createMock(ReindexScheduler::class);
        $logger = $this->createMock(LoggerInterface::class);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $json = $this->createMock(Json::class);
        $health = $this->createMock(ConsumerHealth::class);
        $jsonData = [];

        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) {
                return [
                    'indexer_ids' => ['customer_grid'],
                    'isAjax' => 1,
                ][$key] ?? $default;
            }
        );
        $context->method('getRequest')->willReturn($request);
        $jsonFactory->method('create')->willReturn($json);
        $json->method('setData')->willReturnCallback(
            static function (array $data) use (&$jsonData, $json): Json {
                $jsonData = $data;
                return $json;
            }
        );
        $health->method('isWorkerDetected')->willReturn(true);
        $scheduler->expects($this->once())
            ->method('schedule')
            ->with(['customer_grid'], ReindexScheduler::ACTION_RESET)
            ->willReturn(
                new ScheduleResult([self::BULK_UUID], ['customer_grid'], 1, 0)
            );

        $controller = new Reset($context, $scheduler, $logger, $jsonFactory, $health);

        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertSame('Haroone_AdminReindex::reindex', Reset::ADMIN_RESOURCE);
        $this->assertSame($json, $controller->execute());
        $this->assertSame(ReindexScheduler::ACTION_RESET, $jsonData['action']);
        $this->assertSame('Reset', $jsonData['action_label']);
    }
}
