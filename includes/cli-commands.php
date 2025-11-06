<?php
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('dsnwoo cleanup-sale-prices', function($args, $assoc_args) {
        $batch = isset($assoc_args['batch']) ? (int) $assoc_args['batch'] : 100;
        require_once __DIR__ . '/class-api-handler.php';
        require_once __DIR__ . '/class-product-sync.php';
        $api_handler = new \DSNWooPowerall\API_Handler();
        $sync = new \DSNWooPowerall\Product_Sync($api_handler);
        $result = $sync->cleanup_remove_equal_sale_prices($batch);
        if (is_array($result)) {
            WP_CLI::success(sprintf('Processed: %d Updated: %d', $result['processed'], $result['updated']));
        } else {
            WP_CLI::error('Cleanup failed or returned unexpected result.');
        }
    });
}
