<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Model\ReindexScheduler;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Schedules the indexers selected on Magento's Index Management grid.
 */
class Reindex extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Haroone_AdminReindex::reindex';

    /** @var ReindexScheduler */
    private ReindexScheduler $scheduler;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var JsonFactory */
    private JsonFactory $resultJsonFactory;

    /**
     * @param Context $context
     * @param ReindexScheduler $scheduler
     * @param LoggerInterface $logger
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        ReindexScheduler $scheduler,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->scheduler = $scheduler;
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Queue the requested indexers and return JSON or redirect to Index Management.
     *
     * @return Json|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $requestedIds = $this->getRequestedIds();
        if ($requestedIds === []) {
            return $this->errorResponse(__('Please select indexers.'), 400);
        }

        try {
            $bulkUuid = $this->scheduler->schedule($requestedIds);
            if ($this->isAjaxRequest()) {
                return $this->resultJsonFactory->create()->setData(
                    [
                        'success' => true,
                        'bulk_uuid' => $bulkUuid,
                    ]
                );
            }

            $this->messageManager->addSuccessMessage(
                __('Reindexing was queued in the background. You may leave this page.')
            );
            return $this->redirectToIndexManagement($bulkUuid);
        } catch (LocalizedException $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Admin reindex scheduling failed.',
                ['exception' => $exception]
            );

            return $this->errorResponse(
                __('The reindex job could not be queued. Check the Magento logs.'),
                500
            );
        }
    }

    /**
     * Normalize the grid's array or comma-separated selection.
     *
     * @return array<mixed>
     */
    private function getRequestedIds(): array
    {
        $requestedIds = $this->getRequest()->getParam('indexer_ids');
        if (is_array($requestedIds)) {
            return $requestedIds;
        }

        if (is_string($requestedIds)) {
            return array_values(array_filter(array_map('trim', explode(',', $requestedIds)), 'strlen'));
        }

        return [];
    }

    /**
     * Return an AJAX error without adding a flash message, or keep the redirect fallback.
     *
     * @param mixed $message
     * @param int $httpStatus
     * @return Json|\Magento\Framework\Controller\Result\Redirect
     */
    private function errorResponse($message, int $httpStatus)
    {
        if ($this->isAjaxRequest()) {
            return $this->resultJsonFactory->create()
                ->setHttpResponseCode($httpStatus)
                ->setData(
                    [
                        'success' => false,
                        'message' => (string) $message,
                    ]
                );
        }

        $this->messageManager->addErrorMessage($message);
        return $this->redirectToIndexManagement();
    }

    /**
     * The module's JavaScript sends this flag while Magento still validates POST and form key.
     *
     * @return bool
     */
    private function isAjaxRequest(): bool
    {
        return (bool) $this->getRequest()->getParam('isAjax');
    }

    /**
     * Build the redirect back to Index Management.
     *
     * @param string|null $bulkUuid
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function redirectToIndexManagement(?string $bulkUuid = null)
    {
        $arguments = $bulkUuid === null ? [] : ['_query' => ['bulk_uuid' => $bulkUuid]];

        return $this->resultRedirectFactory->create()->setPath('indexer/indexer/list', $arguments);
    }
}
