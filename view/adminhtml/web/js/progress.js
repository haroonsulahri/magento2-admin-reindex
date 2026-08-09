/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

define([
    'jquery',
    'Magento_Ui/js/modal/modal',
    'mage/translate'
], function ($, modal, $t) {
    'use strict';

    return function (config, element) {
        var $element = $(element),
            $summary = $element.find('[data-role="summary"]'),
            $detail = $element.find('[data-role="detail"]'),
            $statusLabel = $element.find('[data-role="status-label"]'),
            $progressTrack = $element.find('[data-role="progress-track"]'),
            $progressBar = $element.find('[data-role="progress-bar"]'),
            $remaining = $element.find('[data-role="remaining"]'),
            $percent = $element.find('[data-role="percent"]'),
            $total = $element.find('[data-role="total"]'),
            $successful = $element.find('[data-role="successful"]'),
            $skipped = $element.find('[data-role="skipped"]'),
            $failed = $element.find('[data-role="failed"]'),
            $resultsPanel = $element.find('[data-role="results-panel"]'),
            $results = $element.find('[data-role="results"]'),
            $note = $element.find('[data-role="note"]'),
            $close = $element.find('[data-role="close"]'),
            timer,
            pollRequest,
            submitting = false,
            stopped = true,
            refreshOnClose = false;

        modal({
            type: 'popup',
            responsive: true,
            modalClass: 'admin-reindex-modal',
            title: $t('Background indexer operation'),
            buttons: [],
            closed: function () {
                stopped = true;
                window.clearTimeout(timer);
                if (pollRequest) {
                    pollRequest.abort();
                }
                if (refreshOnClose) {
                    window.location.href = config.refreshUrl;
                }
            }
        }, $element);

        function replaceTokens(message, values) {
            Object.keys(values).forEach(function (token) {
                message = message.replace(token, values[token]);
            });

            return message;
        }

        function actionLabel(action) {
            return action === 'reset' ? $t('Reset') : $t('Reindex');
        }

        function setState(state) {
            $element.removeClass('is-submitting is-queued is-running is-complete is-error')
                .addClass('is-' + state);
        }

        function setProgress(value) {
            var progress = Math.max(0, Math.min(100, Number(value) || 0));

            $progressTrack.attr('aria-valuenow', progress);
            $progressBar.css('width', progress + '%');
            $percent.text(progress + '%');
        }

        function setMetrics(data) {
            $total.text(data.total || 0);
            $successful.text(data.successful || 0);
            $skipped.text(data.skipped || 0);
            $failed.text(data.failed || 0);
        }

        function openProgress(expectedTotal, label) {
            stopped = false;
            refreshOnClose = false;
            window.clearTimeout(timer);
            if (pollRequest) {
                pollRequest.abort();
                pollRequest = null;
            }

            setState('submitting');
            setProgress(0);
            setMetrics({total: expectedTotal});
            $statusLabel.text($t('Preparing'));
            $summary.text(replaceTokens($t('Preparing your %1 job'), {'%1': label}));
            $detail.text($t('Selected indexers are being checked and added to Magento\'s background queue.'));
            $remaining.text($t('Starting background job...'));
            $results.empty();
            $resultsPanel.prop('hidden', true);
            $close.removeClass('action-primary').addClass('action-secondary')
                .find('span').text($t('Close and keep running'));
            $note.text($t('You can close this window or leave the page. The background job will keep running.'));
            $element.removeAttr('hidden').modal('openModal');
        }

        function showQueued(workerDetected, existingCount) {
            setState('queued');
            setProgress(0);
            $statusLabel.text($t('Queued'));

            if (!workerDetected) {
                $summary.text($t('Background worker not detected'));
                $detail.text(config.workerWarning);
                $remaining.text($t('Waiting for the consumer to be started...'));
                return;
            }

            if (Number(existingCount) > 0) {
                $summary.text($t('Existing job found'));
                $detail.text($t('An active operation was reused instead of adding a duplicate queue job.'));
            } else {
                $summary.text($t('Background job queued'));
                $detail.text($t('Waiting for Magento queue consumer to start.'));
            }
            $remaining.text($t('Waiting to start...'));
        }

        function showError(message) {
            refreshOnClose = false;
            setState('error');
            setProgress(0);
            $statusLabel.text($t('Not started'));
            $summary.text($t('Indexer operation could not be started'));
            $detail.text(message);
            $remaining.text($t('No background job is running.'));
            $close.removeClass('action-primary').addClass('action-secondary')
                .find('span').text($t('Close'));
            $note.text($t('The job was not started. Review the message and try again.'));
        }

        function renderResults(results) {
            $results.empty();
            (results || []).forEach(function (result) {
                var status = ['complete', 'running', 'skipped', 'failed'].indexOf(result.status) !== -1 ?
                    result.status : 'running';

                $('<li>')
                    .addClass('is-' + status)
                    .text(result.message)
                    .appendTo($results);
            });
            $resultsPanel.prop('hidden', !results || results.length === 0);
        }

        function render(data) {
            setMetrics(data);
            setProgress(data.percent);
            renderResults(data.results);

            if (data.done) {
                refreshOnClose = true;
                setState(data.failed > 0 ? 'error' : 'complete');
                $statusLabel.text(data.failed > 0 ? $t('Completed with issues') : $t('Complete'));
                $summary.text(
                    data.failed > 0 ?
                        $t('Indexer operation completed with issues') :
                        $t('Indexer operation completed')
                );
                $detail.text(replaceTokens(
                    $t('%1 completed, %2 skipped, %3 failed.'),
                    {'%1': data.successful, '%2': data.skipped, '%3': data.failed}
                ));
                $remaining.text($t('All selected indexers have been processed.'));
                $close.removeClass('action-secondary').addClass('action-primary')
                    .find('span').text($t('Close and refresh'));
                $note.text($t('Closing this window will refresh the index list and show current statuses.'));

                return;
            }

            setState(data.finished > 0 ? 'running' : 'queued');
            $statusLabel.text(data.finished > 0 ? $t('Running') : $t('Queued'));
            $summary.text(data.finished > 0 ? $t('Processing in background') : $t('Waiting to start'));
            $detail.text(replaceTokens(
                $t('%1 of %2 selected indexers processed.'),
                {'%1': data.finished, '%2': data.total}
            ));
            $remaining.text(replaceTokens(
                $t('%1 indexer(s) remaining'),
                {'%1': data.pending}
            ));
        }

        function poll(bulkUuids, indexerIds) {
            if (stopped) {
                return;
            }

            pollRequest = $.ajax({
                url: config.statusUrl,
                type: 'GET',
                data: {
                    uuids: bulkUuids.join(','),
                    indexer_ids: indexerIds.join(',')
                },
                dataType: 'json',
                cache: false,
                showLoader: false
            }).done(function (data) {
                if (data.error) {
                    $detail.text($t('Progress is temporarily unavailable. Retrying...'));
                    timer = window.setTimeout(function () {
                        poll(bulkUuids, indexerIds);
                    }, 5000);
                    return;
                }

                render(data);
                if (!data.done) {
                    timer = window.setTimeout(function () {
                        poll(bulkUuids, indexerIds);
                    }, 2000);
                }
            }).fail(function (xhr, status) {
                if (status === 'abort' || stopped) {
                    return;
                }

                $detail.text($t('Progress is temporarily unavailable. Retrying...'));
                timer = window.setTimeout(function () {
                    poll(bulkUuids, indexerIds);
                }, 5000);
            });
        }

        function selectedIds(checkedString) {
            if (!checkedString) {
                return [];
            }

            return checkedString.split(',').map(function (value) {
                return value.trim();
            }).filter(function (value) {
                return value !== '';
            });
        }

        function submitOperation(massAction, fieldName, item) {
            var $form = $(massAction.form),
                ids = selectedIds(massAction.checkedString),
                label = item.id === 'reset_selected' ? $t('Reset') : $t('Reindex'),
                formData;

            if (submitting) {
                $element.modal('openModal');
                return;
            }

            $(massAction.formHiddens).empty()
                .append($('<input>', {
                    type: 'hidden',
                    name: fieldName,
                    value: massAction.checkedString
                }))
                .append($('<input>', {
                    type: 'hidden',
                    name: 'massaction_prepare_key',
                    value: fieldName
                }));

            if (!$form.valid()) {
                return;
            }

            openProgress(ids.length, label);
            submitting = true;
            formData = $form.serializeArray();
            formData.push({name: 'isAjax', value: '1'});

            $.ajax({
                url: item.url,
                type: 'POST',
                data: formData,
                dataType: 'json',
                showLoader: false
            }).done(function (response) {
                var bulkUuids = response.bulk_uuids || (response.bulk_uuid ? [response.bulk_uuid] : []),
                    indexerIds = response.indexer_ids || ids;

                if (!response.success || bulkUuids.length === 0) {
                    showError(response.message || $t('The indexer job could not be queued.'));
                    return;
                }

                showQueued(response.worker_detected, response.existing_count);
                poll(bulkUuids, indexerIds);
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};

                showError(
                    response.message ||
                    $t('The indexer job could not be queued. Refresh the page and try again.')
                );
            }).always(function () {
                submitting = false;
            });
        }

        function installMassActionInterceptor(attempt) {
            var massAction = config.massActionObject ? window[config.massActionObject] : null,
                actionIds = config.actionIds || [config.actionId],
                originalOnConfirm;

            if (!massAction) {
                if (attempt < 40) {
                    window.setTimeout(function () {
                        installMassActionInterceptor(attempt + 1);
                    }, 50);
                }
                return;
            }

            if (massAction.adminReindexAjaxInstalled) {
                return;
            }

            originalOnConfirm = massAction.onConfirm;
            massAction.onConfirm = function (fieldName, item) {
                if (actionIds.indexOf(item.id) === -1) {
                    originalOnConfirm.call(massAction, fieldName, item);
                    return;
                }

                submitOperation(massAction, fieldName, item);
            };
            massAction.adminReindexAjaxInstalled = true;
        }

        $close.on('click', function () {
            $element.modal('closeModal');
        });

        installMassActionInterceptor(0);

        if (config.initialBulkUuids && config.initialBulkUuids.length > 0) {
            openProgress(config.initialIndexerIds.length, actionLabel(config.initialAction));
            showQueued(config.workerDetected, 0);
            poll(config.initialBulkUuids, config.initialIndexerIds || []);
        }
    };
});
