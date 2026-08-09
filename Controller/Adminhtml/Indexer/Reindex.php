<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Model\ConsumerHealth;
use Haroone\AdminReindex\Model\ReindexScheduler;
use Haroone\AdminReindex\Model\ScheduleResult;
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

    /** @var ConsumerHealth */
    private ConsumerHealth $consumerHealth;

    /**
     * @param Context $context
     * @param ReindexScheduler $scheduler
     * @param LoggerInterface $logger
     * @param JsonFactory $resultJsonFactory
     * @param ConsumerHealth $consumerHealth
     */
    public function __construct(
        Context $context,
        ReindexScheduler $scheduler,
        LoggerInterface $logger,
        JsonFactory $resultJsonFactory,
        ConsumerHealth $consumerHealth
    ) {
        parent::__construct($context);
        $this->scheduler = $scheduler;
        $this->logger = $logger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->consumerHealth = $consumerHealth;
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
            $workerDetected = $this->consumerHealth->isWorkerDetected();
            $scheduleResult = $this->scheduler->schedule($requestedIds, $this->getAction());
            if ($this->isAjaxRequest()) {
                return $this->resultJsonFactory->create()->setData(
                    [
                        'success' => true,
                        'bulk_uuid' => $scheduleResult->getBulkUuids()[0] ?? '',
                        'bulk_uuids' => $scheduleResult->getBulkUuids(),
                        'indexer_ids' => $scheduleResult->getIndexerIds(),
                        'queued_count' => $scheduleResult->getQueuedCount(),
                        'existing_count' => $scheduleResult->getExistingCount(),
                        'worker_detected' => $workerDetected,
                        'action' => $this->getAction(),
                        'action_label' => $this->getActionLabel(),
                    ]
                );
            }

            if (!$workerDetected) {
                $this->messageManager->addWarningMessage($this->getWorkerWarning());
            }
            $this->messageManager->addSuccessMessage($this->getSuccessMessage($scheduleResult));

            return $this->redirectToIndexManagement($scheduleResult);
        } catch (LocalizedException $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Admin indexer operation scheduling failed.',
                ['action' => $this->getAction(), 'exception' => $exception]
            );

            return $this->errorResponse(
                __('The indexer job could not be queued. Check the Magento logs.'),
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
     * @param ScheduleResult|null $scheduleResult
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function redirectToIndexManagement(?ScheduleResult $scheduleResult = null)
    {
        $arguments = [];
        if ($scheduleResult !== null) {
            $arguments = [
                '_query' => [
                    'bulk_uuids' => implode(',', $scheduleResult->getBulkUuids()),
                    'indexer_ids' => implode(',', $scheduleResult->getIndexerIds()),
                    'operation' => $this->getAction(),
                ],
            ];
        }

        return $this->resultRedirectFactory->create()->setPath('indexer/indexer/list', $arguments);
    }

    /**
     * Return the queue action encoded in each operation.
     */
    protected function getAction(): string
    {
        return ReindexScheduler::ACTION_REINDEX;
    }

    /**
     * Return the translated operation label used by the modal.
     */
    protected function getActionLabel(): string
    {
        return (string) __('Reindex');
    }

    /**
     * Build the success message for new and deduplicated operations.
     *
     * @param ScheduleResult $scheduleResult
     * @return \Magento\Framework\Phrase
     */
    protected function getSuccessMessage(ScheduleResult $scheduleResult)
    {
        if ($scheduleResult->getQueuedCount() === 0) {
            return __('This indexer operation is already queued. Showing its current progress.');
        }

        if ($scheduleResult->getExistingCount() > 0) {
            return __(
                '%1 indexer(s) queued; %2 already had an active operation.',
                $scheduleResult->getQueuedCount(),
                $scheduleResult->getExistingCount()
            );
        }

        return __('Reindexing was queued in the background. You may leave this page.');
    }

    /**
     * Return the worker warning shared with the Index Management banner.
     *
     * @return \Magento\Framework\Phrase
     */
    private function getWorkerWarning()
    {
        return __('Background worker not detected — jobs will queue but won\'t run until the consumer is started.');
    }
}
