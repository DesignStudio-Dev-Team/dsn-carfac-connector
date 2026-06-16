(function($) {
    'use strict';

    var config = window.dsnCarfacSync || {};
    var pollTimer = null;
    var logPollTimer = null;
    var stopRequested = false;
    var logOffset = parseInt(config.logOffset, 10) || 0;
    var lastFinishedAt = 0;

    var stats = {
        updated: 0,
        skipped: 0,
        errors: 0,
        processed: 0,
        total: 0
    };

    $(document).ready(function() {
        $('#dsn-carfac-sync-btn').on('click', startSync);
        $('#dsn-carfac-stop-btn').on('click', stopSync);

        // Resume display if a background sync is already running when the page loads.
        checkExistingRun();
    });

    function checkExistingRun() {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dsn_carfac_sync_progress',
                nonce: config.nonce
            },
            success: function(response) {
                if (!response.success || !response.data) return;
                var state = response.data;

                if (state.running) {
                    showProgressUI();
                    setButtonsRunning();
                    logMessage(config.i18n.resumed);
                    applyState(state);
                    startLogPolling();
                    startPolling();
                } else if (state.finished_at && state.total > 0) {
                    // Show last completed run summary.
                    showProgressUI();
                    applyState(state);
                    if (state.error) {
                        setStatus('❌ ' + config.i18n.failed.replace('%s', state.error));
                        $('#dsn-sync-bar').css('background', '#dc3232');
                    } else {
                        setStatus('✅ ' + state.message);
                        $('#dsn-sync-bar').css('background', '#46b450');
                        setProgress(100);
                    }
                    lastFinishedAt = parseInt(state.finished_at, 10) || 0;
                }
            }
        });
    }

    function startSync() {
        if (pollTimer) return;
        stopRequested = false;

        resetUI();
        showProgressUI();
        setButtonsRunning();
        setStatus(config.i18n.starting);
        logMessage('Requesting background sync...');

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dsn_carfac_sync_fetch',
                nonce: config.nonce
            },
            success: function(response) {
                if (!response.success) {
                    var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    syncFailed(msg);
                    return;
                }

                var state = response.data;
                applyState(state);
                logMessage(state.message || 'Background sync queued.');

                if (!state.running && state.total === 0) {
                    syncComplete(state);
                    return;
                }

                startLogPolling();
                startPolling();
            },
            error: function(xhr, status, error) {
                syncFailed(getAjaxErrorMessage(xhr, error || config.i18n.error));
            }
        });
    }

    function stopSync() {
        if (stopRequested) return;
        stopRequested = true;

        $('#dsn-carfac-stop-btn').prop('disabled', true);
        setStatus(config.i18n.stopping);
        logMessage('Stop requested. The background worker will halt at the next checkpoint.');

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dsn_carfac_sync_cleanup',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    applyState(response.data);
                }
            }
        });
    }

    function startPolling() {
        stopPolling();
        var interval = parseInt(config.pollIntervalMs, 10) || 4000;
        pollTimer = setInterval(pollProgress, interval);
        pollProgress();
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function pollProgress() {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dsn_carfac_sync_progress',
                nonce: config.nonce
            },
            success: function(response) {
                if (!response.success || !response.data) return;

                var state = response.data;
                applyState(state);

                if (!state.running) {
                    stopPolling();
                    stopLogPolling();
                    if (state.error) {
                        syncFailed(state.error);
                    } else {
                        syncComplete(state);
                    }
                }
            }
        });
    }

    function applyState(state) {
        stats.total = parseInt(state.total, 10) || stats.total || 0;
        stats.processed = parseInt(state.processed, 10) || 0;
        stats.updated = parseInt(state.updated, 10) || 0;
        stats.skipped = parseInt(state.skipped, 10) || 0;
        stats.errors = parseInt(state.errors, 10) || 0;

        updateStatsUI();
        updateProgressBarFromStats();

        var statusText = state.message || (state.running ? config.i18n.running : '');
        if (state.current) {
            statusText += ' — ' + state.current;
        }
        if (statusText) setStatus(statusText);
    }

    function syncComplete(state) {
        stopPolling();
        stopLogPolling();
        var msg = (state && state.message) ? state.message : config.i18n.complete;
        setStatus('✅ ' + msg);
        setProgress(100);
        $('#dsn-sync-bar').css('background', '#46b450');
        logMessage('Sync finished. Updated: ' + stats.updated + ', Skipped: ' + stats.skipped + ', Errors: ' + stats.errors + ', Total: ' + stats.processed + ' / ' + stats.total);
        setButtonsIdle();
    }

    function syncFailed(message) {
        stopPolling();
        stopLogPolling();
        var text = config.i18n.failed.replace('%s', message);
        setStatus('❌ ' + text);
        logMessage('FAILED: ' + message);
        $('#dsn-sync-bar').css('background', '#dc3232');
        setButtonsIdle();
    }

    function resetUI() {
        stats = { updated: 0, skipped: 0, errors: 0, processed: 0, total: 0 };
        stopRequested = false;
        logOffset = parseInt(config.logOffset, 10) || 0;
        stopPolling();
        stopLogPolling();
        setProgress(0);
        $('#dsn-sync-bar').css('background', '#0073aa');
        $('#dsn-sync-status').text('');
        $('#dsn-sync-log').empty().hide();
        updateStatsUI();
    }

    function showProgressUI() {
        $('#dsn-carfac-sync-progress').show();
    }

    function setButtonsRunning() {
        $('#dsn-carfac-sync-btn').prop('disabled', true).text('Sync running in background...');
        $('#dsn-carfac-stop-btn').show().prop('disabled', false);
    }

    function setButtonsIdle() {
        $('#dsn-carfac-sync-btn').prop('disabled', false).text('Sync Products Now');
        $('#dsn-carfac-stop-btn').hide().prop('disabled', false);
    }

    function setStatus(text) {
        $('#dsn-sync-status').text(text);
    }

    function setProgress(percent) {
        $('#dsn-sync-bar').css('width', percent + '%');
        $('#dsn-sync-percent').text(percent + '%');
    }

    function updateStatsUI() {
        $('#dsn-stat-updated').text(stats.updated);
        $('#dsn-stat-skipped').text(stats.skipped);
        $('#dsn-stat-errors').text(stats.errors);
        $('#dsn-stat-processed').text(stats.processed);
        $('#dsn-stat-total').text(stats.total);
    }

    function updateProgressBarFromStats() {
        if (stats.total > 0) {
            setProgress(Math.min(100, Math.round((stats.processed / stats.total) * 100)));
        }
    }

    function startLogPolling() {
        stopLogPolling();
        pollLogTail();
        logPollTimer = setInterval(pollLogTail, 3000);
    }

    function stopLogPolling() {
        if (logPollTimer) {
            clearInterval(logPollTimer);
            logPollTimer = null;
        }
    }

    function pollLogTail() {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dsn_carfac_sync_log_tail',
                nonce: config.nonce,
                offset: logOffset
            },
            success: function(response) {
                if (!response.success || !response.data) return;

                logOffset = parseInt(response.data.offset, 10) || logOffset;
                if (response.data.lines && response.data.lines.length) {
                    for (var i = 0; i < response.data.lines.length; i++) {
                        appendLogLine(response.data.lines[i]);
                    }
                }
            }
        });
    }

    function logMessage(msg) {
        var time = new Date().toLocaleTimeString();
        appendLogLine('[' + time + '] ' + msg);
    }

    function appendLogLine(msg) {
        var $log = $('#dsn-sync-log');
        $log.show();
        $log.append('<div>' + escapeHtml(msg) + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function getAjaxErrorMessage(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        if (xhr && xhr.responseText) {
            return xhr.responseText.substring(0, 500);
        }
        return fallback;
    }

})(jQuery);
