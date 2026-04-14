(function($){
    $(document).ready(function(){
        var settings = window.DSNWooPowerallSync;
        var $app = $('#dsn-woo-powerall-sync-app');

        if (!$app.length || !settings) {
            return;
        }

        var $startButton = $('#dsn-woo-powerall-start-sync');
        var $progressBar = $app.find('.dsn-sync-progress-bar');
        var $status = $app.find('.dsn-sync-status');
        var $lastMessage = $app.find('.dsn-sync-last-message');
        var $runId = $app.find('.dsn-sync-run-id');
        var $processed = $app.find('.dsn-sync-processed');
        var $total = $app.find('.dsn-sync-total');
        var $updated = $app.find('.dsn-sync-updated');
        var $synced = $app.find('.dsn-sync-synced');
        var $skipped = $app.find('.dsn-sync-skipped');
        var $failed = $app.find('.dsn-sync-failed');
        var $currentBatch = $app.find('.dsn-sync-current-batch');
        var $totalBatches = $app.find('.dsn-sync-total-batches');
        var $batchSize = $app.find('.dsn-sync-batch-size');
        var $delay = $app.find('.dsn-sync-delay');
        var $lastProduct = $app.find('.dsn-sync-last-product');
        var $startedAt = $app.find('.dsn-sync-started-at');
        var $completedAt = $app.find('.dsn-sync-completed-at');
        var $errorsWrap = $app.find('.dsn-sync-errors');
        var $errorsList = $errorsWrap.find('ul');

        var state = normalizeState(settings.state || {});
        var requestInFlight = false;
        var queuedBatchTimer = null;

        function normalizeState(input) {
            return $.extend({
                run_id: '',
                status: 'idle',
                started_at: '',
                completed_at: '',
                batch_size: 0,
                delay_seconds: 0,
                total_products: 0,
                processed: 0,
                updated: 0,
                synced: 0,
                skipped: 0,
                failed: 0,
                current_batch: 0,
                total_batches: 0,
                progress_percentage: 0,
                last_message: 'Manual sync has not been started yet.',
                last_sku: '',
                last_product_name: '',
                recent_errors: []
            }, input || {});
        }

        function render() {
            var progress = Math.max(0, Math.min(100, parseFloat(state.progress_percentage || 0)));
            var lastProductText = '-';

            $progressBar.css('width', progress + '%');
            $status.text(buildStatusText());
            $lastMessage.text(state.last_message || 'Manual sync has not been started yet.');
            $runId.text(state.run_id || '-');
            $processed.text(state.processed || 0);
            $total.text(state.total_products || 0);
            $updated.text(state.updated || 0);
            $synced.text(state.synced || 0);
            $skipped.text(state.skipped || 0);
            $failed.text(state.failed || 0);
            $currentBatch.text(state.current_batch || 0);
            $totalBatches.text(state.total_batches || 0);
            $batchSize.text(state.batch_size || 0);
            $delay.text(state.delay_seconds || 0);
            $startedAt.text(state.started_at || '-');
            $completedAt.text(state.completed_at || '-');

            if (state.last_product_name || state.last_sku) {
                lastProductText = (state.last_product_name || 'Unknown product') + (state.last_sku ? ' (' + state.last_sku + ')' : '');
            }
            $lastProduct.text(lastProductText);

            renderErrors();

            if (requestInFlight) {
                $startButton.text('Sync Running...').prop('disabled', true);
            } else if (queuedBatchTimer !== null) {
                $startButton.text('Waiting for Next Batch...').prop('disabled', true);
            } else if (state.status === 'completed') {
                $startButton.text('Start New Sync').prop('disabled', false);
            } else if (state.status === 'running' && state.run_id) {
                $startButton.text('Resume Sync').prop('disabled', false);
            } else {
                $startButton.text('Start Sync').prop('disabled', false);
            }
        }

        function renderErrors() {
            $errorsList.empty();

            if (!state.recent_errors || !state.recent_errors.length) {
                $errorsWrap.hide();
                return;
            }

            $.each(state.recent_errors, function(_, message){
                $('<li />').text(message).appendTo($errorsList);
            });

            $errorsWrap.show();
        }

        function buildStatusText() {
            if (requestInFlight) {
                return 'Running';
            }

            if (queuedBatchTimer !== null) {
                return 'Waiting for next batch';
            }

            if (state.status === 'running') {
                return 'Ready to resume';
            }

            if (state.status === 'completed') {
                return 'Completed';
            }

            if (state.failed > 0 && !state.run_id) {
                return 'Failed';
            }

            return 'Idle';
        }

        function getAjaxErrorMessage(response, fallback) {
            if (response && response.data && response.data.message) {
                return response.data.message;
            }

            return fallback;
        }

        function handleError(message) {
            if (queuedBatchTimer !== null) {
                window.clearTimeout(queuedBatchTimer);
                queuedBatchTimer = null;
            }

            requestInFlight = false;
            state = normalizeState($.extend({}, state, {
                status: 'idle',
                last_message: message
            }));
            render();
            window.alert(message);
        }

        function scheduleNextBatch() {
            if (state.status !== 'running' || !state.run_id) {
                requestInFlight = false;
                render();
                return;
            }

            var delayMs = Math.max(0, parseInt(state.delay_seconds || 0, 10) * 1000);
            queuedBatchTimer = window.setTimeout(function(){
                queuedBatchTimer = null;
                processNextBatch();
            }, delayMs);
            render();
        }

        function processNextBatch() {
            if (!state.run_id) {
                handleError('No sync run is available to process.');
                return;
            }

            if (queuedBatchTimer !== null) {
                window.clearTimeout(queuedBatchTimer);
                queuedBatchTimer = null;
            }

            requestInFlight = true;
            render();

            $.post(settings.ajax_url, {
                action: 'dsn_woo_powerall_process_sync_batch',
                nonce: settings.nonce,
                run_id: state.run_id
            }).done(function(response){
                if (!response || !response.success) {
                    handleError(getAjaxErrorMessage(response, 'The product sync batch failed.'));
                    return;
                }

                state = normalizeState(response.data || {});
                requestInFlight = false;
                render();

                if (state.status === 'running') {
                    scheduleNextBatch();
                }
            }).fail(function(jqXHR){
                var response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
                handleError(getAjaxErrorMessage(response, 'The product sync request failed.'));
            });
        }

        function startSync() {
            if (queuedBatchTimer !== null) {
                window.clearTimeout(queuedBatchTimer);
                queuedBatchTimer = null;
            }

            requestInFlight = true;
            render();

            $.post(settings.ajax_url, {
                action: 'dsn_woo_powerall_start_sync',
                nonce: settings.nonce
            }).done(function(response){
                if (!response || !response.success) {
                    handleError(getAjaxErrorMessage(response, 'Unable to start the product sync.'));
                    return;
                }

                state = normalizeState(response.data || {});
                requestInFlight = false;

                if (window.history && window.history.replaceState && settings.progress_url) {
                    window.history.replaceState({}, document.title, settings.progress_url);
                }

                render();

                if (state.status === 'running') {
                    scheduleNextBatch();
                }
            }).fail(function(jqXHR){
                var response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
                handleError(getAjaxErrorMessage(response, 'Unable to start the product sync.'));
            });
        }

        $startButton.on('click', function(event){
            event.preventDefault();

            if (requestInFlight) {
                return;
            }

            if (state.status === 'running' && state.run_id) {
                processNextBatch();
                return;
            }

            startSync();
        });

        render();

        if (settings.auto_start) {
            if (state.status === 'running' && state.run_id) {
                processNextBatch();
            } else {
                startSync();
            }
        }
    });
})(jQuery);
