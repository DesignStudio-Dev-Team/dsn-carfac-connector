 (function($){
    $(document).ready(function(){
        var $btn = $('input[name="dsn_woo_powerall_cleanup"]');
        if (!$btn.length) return;

        // Insert progress UI
        var $container = $('<div class="dsn-cleanup-ui" style="margin-top:12px"></div>');
        var $barWrap = $('<div style="background:#eee; width:100%; height:16px; border-radius:3px; overflow:hidden;\"></div>');
        var $bar = $('<div style="width:0%; height:100%; background:#2ea2cc;\"></div>');
        var $status = $('<div style="margin-top:8px">Idle</div>');
        var $counts = $('<div style="margin-top:6px">Processed: <span class="processed">0</span> Updated: <span class="updated">0</span></div>');
        $barWrap.append($bar);
        $container.append($barWrap).append($status).append($counts);
        $btn.closest('form').after($container);

        $btn.on('click', function(e){
            e.preventDefault();
            if (!confirm('Are you sure you want to remove sale prices that equal regular prices?')) return;

            $btn.prop('disabled', true).val('Cleaning...');
            var batch = 100;
            var page = 1;
            var totalPages = null;
            var totalProcessed = 0;
            var totalUpdated = 0;

            function runPage() {
                $status.text('Processing page ' + page + (totalPages ? ' of ' + totalPages : ''));
                $.post(DSNWooPowerall.ajax_url, { action: 'dsn_woo_powerall_cleanup', batch: batch, page: page, nonce: DSNWooPowerall.nonce }, function(response){
                    if (!response.success) {
                        alert('Cleanup failed on page ' + page + ': ' + (response.data || 'unknown'));
                        $btn.prop('disabled', false).val('Cleanup sale prices');
                        $status.text('Failed');
                        return;
                    }
                    var data = response.data;
                    totalProcessed += parseInt(data.processed || 0, 10);
                    totalUpdated += parseInt(data.updated || 0, 10);
                    $container.find('.processed').text(totalProcessed);
                    $container.find('.updated').text(totalUpdated);

                    if (totalPages === null) totalPages = parseInt(data.total_pages || 0, 10);
                    var perc = totalPages ? Math.round((page / totalPages) * 100) : 0;
                    $bar.css('width', perc + '%');

                    if (page < totalPages) {
                        page++;
                        setTimeout(runPage, 300); // small delay between pages
                    } else {
                        $status.text('Completed');
                        $btn.prop('disabled', false).val('Cleanup sale prices');
                        $bar.css('width', '100%');
                        alert('Cleanup finished. Processed: ' + totalProcessed + ' Updated: ' + totalUpdated);
                    }
                }).fail(function(jqXHR, textStatus, errorThrown){
                    var resp = jqXHR.responseText || errorThrown || textStatus;
                    console.error('Cleanup AJAX fail:', jqXHR, textStatus, errorThrown);
                    alert('AJAX request failed on page ' + page + '. Server response: ' + resp + '\nCheck plugin log for details.');
                    $btn.prop('disabled', false).val('Cleanup sale prices');
                    $status.text('Failed');
                });
            }

            runPage();
        });
    });
})(jQuery);
