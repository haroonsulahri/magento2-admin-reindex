<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Test\Unit\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Controller\Adminhtml\Indexer\Reindex;
use Haroone\AdminReindex\Model\ReindexScheduler;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ReindexTest extends TestCase
{
    private const BULK_UUID = '123e4567-e89b-12d3-a456-426614174000';

    /** @var RequestInterface&MockObject */
    private RequestInterface $request;

    /** @var ManagerInterface&MockObject */
    private ManagerInterface $messageManager;

    /** @var Redirect&MockObject */
    private Redirect $redirect;

    /** @var Json&MockObject */
    private Json $json;

    /** @var ReindexScheduler&MockObject */
    private ReindexScheduler $scheduler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var Reindex */
    private Reindex $controller;

    /** @var mixed */
    private $requestedIds;

    /** @var bool */
    private bool $ajax = false;

    /** @var array<mixed> */
    private array $jsonData = [];

    /** @var int */
    private int $jsonStatus = 200;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->json = $this->createMock(Json::class);
        $this->scheduler = $this->createMock(ReindexScheduler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $jsonFactory->method('create')->willReturn($this->json);
        $this->redirect->method('setPath')->willReturnSelf();
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
        $this->request->method('getParam')->willReturnCallback(
            function (string $key, $default = null) {
                if ($key === 'indexer_ids') {
                    return $this->requestedIds;
                }
                if ($key === 'isAjax') {
                    return $this->ajax ? 1 : 0;
                }

                return $default;
            }
        );

        $context->method('getRequest')->willReturn($this->request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $this->controller = new Reindex(
            $context,
            $this->scheduler,
            $this->logger,
            $jsonFactory
        );
    }

    public function testUsesFrameworkFormKeyValidationAndDeclaresPostOnlyContractAndAcl(): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller);
        $this->assertNotInstanceOf(CsrfAwareActionInterface::class, $this->controller);
        $this->assertSame('Haroone_AdminReindex::reindex', Reindex::ADMIN_RESOURCE);
    }

    public function testRejectsMissingSelection(): void
    {
        $this->requestedIds = null;
        $this->scheduler->expects($this->never())->method('schedule');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->phrase('Please select indexers.'));
        $this->redirect->expects($this->once())
            ->method('setPath')
            ->with('indexer/indexer/list', [])
            ->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testSchedulesSelectionAndRedirectsToProgress(): void
    {
        $this->requestedIds = ['catalog_product_price'];
        $this->scheduler->expects($this->once())
            ->method('schedule')
            ->with($this->requestedIds)
            ->willReturn(self::BULK_UUID);
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with($this->phrase('Reindexing was queued in the background. You may leave this page.'));
        $this->redirect->expects($this->once())
            ->method('setPath')
            ->with('indexer/indexer/list', ['_query' => ['bulk_uuid' => self::BULK_UUID]])
            ->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testAjaxNormalizesGridSelectionAndDoesNotRedirect(): void
    {
        $this->ajax = true;
        $this->requestedIds = 'catalog_product_price, catalogsearch_fulltext';
        $this->scheduler->expects($this->once())
            ->method('schedule')
            ->with(['catalog_product_price', 'catalogsearch_fulltext'])
            ->willReturn(self::BULK_UUID);
        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->redirect->expects($this->never())->method('setPath');

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(
            ['success' => true, 'bulk_uuid' => self::BULK_UUID],
            $this->jsonData
        );
        $this->assertSame(200, $this->jsonStatus);
    }

    public function testAjaxRejectsMissingSelectionWithoutRedirect(): void
    {
        $this->ajax = true;
        $this->requestedIds = '';
        $this->scheduler->expects($this->never())->method('schedule');
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->redirect->expects($this->never())->method('setPath');

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(
            ['success' => false, 'message' => 'Please select indexers.'],
            $this->jsonData
        );
        $this->assertSame(400, $this->jsonStatus);
    }

    public function testShowsSafeLocalizedSchedulingError(): void
    {
        $this->requestedIds = ['invalid'];
        $this->scheduler->method('schedule')
            ->willThrowException(new LocalizedException(__('Please select valid indexers.')));
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with('Please select valid indexers.');
        $this->logger->expects($this->never())->method('error');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testAjaxReturnsSafeLocalizedSchedulingError(): void
    {
        $this->ajax = true;
        $this->requestedIds = ['invalid'];
        $this->scheduler->method('schedule')
            ->willThrowException(new LocalizedException(__('Please select valid indexers.')));
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(
            ['success' => false, 'message' => 'Please select valid indexers.'],
            $this->jsonData
        );
        $this->assertSame(400, $this->jsonStatus);
    }

    public function testLogsUnexpectedSchedulingFailureAndShowsSafeMessage(): void
    {
        $this->requestedIds = ['catalog_product_price'];
        $exception = new RuntimeException('Private connection details');
        $this->scheduler->method('schedule')->willThrowException($exception);
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Admin reindex scheduling failed.', ['exception' => $exception]);
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->phrase('The reindex job could not be queued. Check the Magento logs.'));

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testAjaxLogsUnexpectedFailureAndReturnsSafeMessage(): void
    {
        $this->ajax = true;
        $this->requestedIds = ['catalog_product_price'];
        $exception = new RuntimeException('Private connection details');
        $this->scheduler->method('schedule')->willThrowException($exception);
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Admin reindex scheduling failed.', ['exception' => $exception]);
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->assertSame($this->json, $this->controller->execute());
        $this->assertSame(
            [
                'success' => false,
                'message' => 'The reindex job could not be queued. Check the Magento logs.',
            ],
            $this->jsonData
        );
        $this->assertSame(500, $this->jsonStatus);
    }

    private function phrase(string $expected)
    {
        return $this->callback(static fn ($message): bool => (string) $message === $expected);
    }
}
