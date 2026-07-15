<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Controller\Adminhtml\Indexer;

use Haroone\AdminReindex\Model\Consumer;
use Magento\AsynchronousOperations\Api\BulkStatusInterface;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Returns progress for a reindex bulk owned by the current admin user.
 */
class Status extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Haroone_AdminReindex::reindex';

    /** @var JsonFactory */
    private JsonFactory $resultJsonFactory;

    /** @var BulkStatusInterface */
    private BulkStatusInterface $bulkStatus;

    /** @var UserContextInterface */
    private UserContextInterface $userContext;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param BulkStatusInterface $bulkStatus
     * @param UserContextInterface $userContext
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        BulkStatusInterface $bulkStatus,
        UserContextInterface $userContext
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->bulkStatus = $bulkStatus;
        $this->userContext = $userContext;
    }

    /**
     * Return the current user's progress for one reindex bulk.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $bulkUuid = trim((string) $this->getRequest()->getParam('uuid'));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $bulkUuid) !== 1) {
            return $result->setHttpResponseCode(400)->setData(['error' => true]);
        }

        try {
            $bulk = $this->bulkStatus->getBulkShortStatus($bulkUuid);
        } catch (NoSuchEntityException $exception) {
            return $result->setHttpResponseCode(404)->setData(['error' => true]);
        }

        if ((int) $bulk->getUserId() !== (int) $this->userContext->getUserId()) {
            return $result->setHttpResponseCode(403)->setData(['error' => true]);
        }

        $successful = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        foreach ($bulk->getOperationsList() as $operation) {
            $status = (int) $operation->getStatus();
            $resultStatus = 'running';
            if ($status === OperationInterface::STATUS_TYPE_COMPLETE) {
                if ((int) $operation->getErrorCode() === Consumer::ERROR_CODE_SKIPPED) {
                    ++$skipped;
                    $resultStatus = 'skipped';
                } else {
                    ++$successful;
                    $resultStatus = 'complete';
                }
            } elseif ($status === OperationInterface::STATUS_TYPE_RETRIABLY_FAILED
                || $status === OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED
                || $status === OperationInterface::STATUS_TYPE_REJECTED
            ) {
                ++$failed;
                $resultStatus = 'failed';
            }

            $message = trim((string) $operation->getResultMessage());
            if ($message !== '') {
                $results[] = [
                    'message' => $message,
                    'status' => $resultStatus,
                ];
            }
        }

        $total = (int) $bulk->getOperationCount();
        $finished = $successful + $skipped + $failed;
        $pending = max(0, $total - $finished);

        return $result->setData(
            [
                'error' => false,
                'total' => $total,
                'finished' => $finished,
                'successful' => $successful,
                'skipped' => $skipped,
                'failed' => $failed,
                'pending' => $pending,
                'percent' => $total > 0 ? (int) floor(($finished / $total) * 100) : 100,
                'done' => $pending === 0,
                'results' => $results,
            ]
        );
    }
}
