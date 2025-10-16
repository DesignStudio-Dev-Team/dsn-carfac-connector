<?php
namespace DSNWooPowerall;

class Product_Sync {
    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;
    /**
     * API handler instance
     *
     * @var API_Handler
     */
    private $api_handler;

    /**
     * Constructor
     *
     * @param API_Handler $api_handler
     */
    public function __construct(API_Handler $api_handler) {
        $this->api_handler = $api_handler;
        $this->logger = new \DSNWooPowerall\Logger();
    }

    /**
     * Sync products from Powerall CRM to WooCommerce
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function sync_products() {
        $this->logger->info('Starting product sync from Powerall CRM');
        // Get products from Powerall CRM
        $products = $this->api_handler->get_products();
        
        if (is_wp_error($products)) {
            $this->logger->error('Failed to fetch products: ' . $products->get_error_message());
            return $products;
        }

        // If API returns ['Data'] key, use it, else assume flat array
        $product_list = isset($products['Data']) ? $products['Data'] : $products;

        $batchSize = 50;
        $total = count($product_list);
        $this->logger->info('Total products to sync: ' . $total);
        for ($i = 0; $i < $total; $i += $batchSize) {
            $batch = array_slice($product_list, $i, $batchSize);
            $this->logger->info('Processing batch ' . (($i/$batchSize)+1) . ' (' . count($batch) . ' products)');
            foreach ($batch as $product_data) {
                $this->sync_single_product($product_data);
            }
            // Optional: pause between batches to reduce server load
            if ($i + $batchSize < $total) {
                $this->logger->info('Batch complete, sleeping 1 second before next batch...');
                sleep(1);
            }
            // Optional: extend script execution time
            if (function_exists('set_time_limit')) {
                @set_time_limit(30);
            }
        }

        $this->logger->info('Product sync completed');
        return true;
    }

    /**
     * Sync a single product from Powerall CRM to WooCommerce
     *
     * @param array $product_data Product data from Powerall CRM
     * @return int|WP_Error Product ID on success, WP_Error on failure
     */
    private function sync_single_product($product_data) {
        // Use SKU from Powerall as the unique identifier
        $sku = isset($product_data['EanCode']) ? $product_data['EanCode'] : '';
        $productName = $product_data['Description1'];
        
        if (!$sku) {
            $this->logger->warning('Product missing SKU, skipping.' . $productName );
            return false;
        }

       

        $this->logger->info('Syncing product with SKU: ' . $sku);
        $product_id = $this->get_woo_product_id_by_sku($sku);

        if (!$product_id) {
            $this->logger->warning('Product with SKU ' . $sku . ' Product Name: ' . $productName . ' not found in WooCommerce, skipping.');
            // Optionally, create the product if it doesn't exist
            // return $this->create_woo_product($product_data);
            return false;
        }

        // Update existing product
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->logger->error('Product with ID ' . $product_id . ' not found in WooCommerce.');
            return new \WP_Error('invalid_product', __('Product not found in WooCommerce.', 'dsn-woo-powerall'));
        }

        // Update only price and stock
        $old_price = $product->get_sale_price();
        $old_stock = $product->get_stock_quantity();

        $new_price = $product_data['SalesPrice'] ?? $product_data['SalesPrice'] ?? '';

        //check if price comes with VAT or not using the  SalesPriceIsIncVat boolean
        $incVat = $product_data['SalesPriceIsIncVat'] ?? $product_data['SalesPriceIsIncVat'] ?? false;

        //if is false then we add a 21% VAT to the price
        if (!$incVat) {
            $new_price = $new_price * 1.21;
        }


        //check if Old_price is not equal to salesPrice
        if ($old_price != $new_price) {
            $product->set_sale_price($new_price);
        } else {
            $this->logger->info('No changes for product SKU: ' . $sku);
            $product->set_sale_price('');
        }
        $product->set_manage_stock(true);
        // Calculate total stock from StockPerWarehouse array
        $total_stock = 0;
        if (isset($product_data['StockPerWarehouse']) && is_array($product_data['StockPerWarehouse'])) {
            foreach ($product_data['StockPerWarehouse'] as $warehouse_stock) {
                $total_stock += isset($warehouse_stock['FreeStock']) ? $warehouse_stock['FreeStock'] : 0;
                $total_stock += isset($warehouse_stock['ShelfStock']) ? $warehouse_stock['ShelfStock'] : 0;
                $total_stock += isset($warehouse_stock['EconomicalStock']) ? $warehouse_stock['EconomicalStock'] : 0;
            }
        }
        $product->set_stock_quantity($total_stock);

        // Log changes to a txt file if price or stock changed
        if ($old_price != $new_price || $old_stock != $total_stock) {
            $log_entry = sprintf(
                "[%s] SKU: %s | Old Price: %s | New Price: %s | Old Stock: %s | New Stock: %s\n",
                date('Y-m-d H:i:s'),
                $product->get_sku(),
                $old_price,
                $new_price,
                $old_stock,
                $total_stock
            );
            $log_file = dirname(__FILE__) . '/../product_changes_log.txt';
            file_put_contents($log_file, $log_entry, FILE_APPEND);
            $this->logger->info('Product updated. SKU: ' . $sku . ' | Price: ' . $old_price . '→' . $new_price . ' | Stock: ' . $old_stock . '→' . $total_stock);
        } else {
            $this->logger->info('No changes for product SKU: ' . $sku);
        }

        // Save product
        $product_id = $product->save();

        if (is_wp_error($product_id)) {
            $this->logger->error('Failed to save product SKU: ' . $sku . ' - ' . $product_id->get_error_message());
            return $product_id;
        }

        $this->logger->info('Product sync complete for SKU: ' . $sku);
        return $product_id;
    }

    /**
     * Get WooCommerce product ID by Powerall CRM product ID
     *
     * @param string $powerall_id Powerall CRM product ID
     * @return int|false Product ID if found, false otherwise
     */
    private function get_woo_product_id_by_sku($sku) {
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' 
            AND meta_value = %s 
            LIMIT 1",
            $sku
        ));

        return $product_id ? (int) $product_id : false;
    }

    /**
     * Check product stock in Powerall CRM before purchase
     *
     * @param int $product_id WooCommerce product ID
     * @param int $quantity Requested quantity
     * @return bool|WP_Error True if stock available, WP_Error if not
     */
    public function check_stock_before_purchase($product_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('invalid_product', __('Invalid product.', 'dsn-woo-powerall'));
        }

        $sku = $product->get_sku();
        if (!$sku) {
            return new \WP_Error('no_sku', __('Product does not have a SKU.', 'dsn-woo-powerall'));
        }

        $stock_data = $this->api_handler->get_product_stock($sku);
        if (is_wp_error($stock_data)) {
            return $stock_data;
        }

        if ($stock_data['quantity'] < $quantity) {
            return new \WP_Error(
                'insufficient_stock',
                sprintf(
                    __('Sorry, we do not have enough "%s" in stock. Only %d available.', 'dsn-woo-powerall'),
                    $product->get_name(),
                    $stock_data['quantity']
                )
            );
        }

        return true;
    }
} 