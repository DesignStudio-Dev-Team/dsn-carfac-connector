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

       

        $this->logger->info('Syncing product with SKU: ' . $sku . ' and ProductCode: ' . $product_data['ProductCode']);
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
        //if is false then we add a 21% VAT to the price
        if ($product_data['SalesPriceIsIncVat'] == false) {
            $new_price = $new_price * 1.21;

            // Make the new price be to 2 decimals safely (coerce to float first)
            if ($new_price !== '' && $new_price !== null) {
                $new_price = round(floatval($new_price), 2);
            }
        }


        // Make sure this product doesn't have promotional pricing coming from the syndified console
        // If a console JSON exists for this product and contains an 'spp' promotional price, skip setting the sale price.
        $has_console_promo = false;
        $console_id = get_post_meta($product_id, 'console_id', true);
       

        if ($console_id) {
            // Build the expected path (as per your note)
            $console_path = trailingslashit( WP_CONTENT_DIR ) . 'plugins/syndified/website-content/json/Product/' . $console_id . '.json';
            if (file_exists($console_path) && is_readable($console_path)) {
                $this->logger->info('Found console JSON for product SKU ' . $sku . ': ' . $console_path);
                $json = file_get_contents($console_path);
                $data = json_decode($json, true);
                if (is_array($data)) {
                    // Check common places for 'spp' promotional price
                    if (isset($data['spp']) && $data['spp'] !== '' && $data['spp'] !== null) {
                        $has_console_promo = true;
                        $this->logger->info('Console promo (spp) detected for SKU ' . $sku . ' (console id: ' . $console_id . ')');
                    } elseif (isset($data['prices']) && isset($data['prices']['spp']) && $data['prices']['spp'] !== '') {
                        $has_console_promo = true;
                        $this->logger->info('Console promo (prices.spp) detected for SKU ' . $sku . ' (console id: ' . $console_id . ')');
                    }
                } else {
                    $this->logger->warning('Console JSON for SKU ' . $sku . ' could not be decoded: ' . $console_path);
                }
            } else {
                $this->logger->info('No console JSON file found for SKU ' . $sku . ' at expected path: ' . $console_path);
            }
        } else {
            $this->logger->info('No console id found in product data for SKU ' . $sku . ', continuing with sale price logic');
        }


        // Make sure the price is not the same as the old one (numeric comparison)
        $old_price_num = $old_price !== '' ? floatval($old_price) : null;
        $new_price_num = $new_price !== '' ? floatval($new_price) : null;
        if ($has_console_promo) {
            // Console promo exists — should not overwrite it with Powerall Price.
            $this->logger->info('Console promo present; skipping sale price comparison for SKU ' . $sku);
        } else {
            if ($old_price_num !== null && $new_price_num !== null && abs($old_price_num - $new_price_num) < 0.0001) {
                $product->set_sale_price('');
            } else {
                $product->set_sale_price($new_price);
            }
        }

        $product->set_manage_stock(true);

        // Calculate total stock from StockPerWarehouse array
        $total_stock = 0;
        if (isset($product_data['StockPerWarehouse']) && is_array($product_data['StockPerWarehouse'])) {
            foreach ($product_data['StockPerWarehouse'] as $warehouse_stock) {
                $total_stock += isset($warehouse_stock['FreeStock']) ? $warehouse_stock['FreeStock'] : 0;
            }
        }
        $product->set_stock_quantity($total_stock);


        
        // Log changes to a txt file if price or stock changed
                if ($old_price != $new_price || $old_stock != $total_stock) {
            $product_code = isset($product_data['ProductCode']) ? $product_data['ProductCode'] : ($product->get_sku() ?: 'N/A');
            $product_name = isset($product_data['Description1']) ? $product_data['Description1'] : ($product->get_name() ?: 'N/A');
            $log_entry = sprintf(
                "[%s] ProductCode: %s | Name: %s | SKU: %s | Old Price: %s | New Price: %s | Old Stock: %s | New Stock: %s\n",
                date('Y-m-d H:i:s'),
                $product_code,
                $product_name,
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

    /**
     * Iterate all WooCommerce products and remove sale price when equal to regular price.
     * Processes products in batches to avoid timeouts.
     *
     * @param int $batch_size Number of products to process per batch
     * @return array Summary of changes: ['processed' => int, 'updated' => int]
     */
    public function cleanup_remove_equal_sale_prices($batch_size = 100) {
        $this->logger->info('Starting cleanup: remove equal sale prices');
        $paged = 1;
        $updated = 0;
        $processed = 0;

        do {
            $args = array(
                'post_type' => array('product', 'product_variation'),
                'posts_per_page' => $batch_size,
                'paged' => $paged,
                'fields' => 'ids',
            );

            $query = new \WP_Query($args);
            $ids = $query->posts;
            $count = count($ids);
            if ($count === 0) {
                break;
            }

            foreach ($ids as $pid) {
                $processed++;
                $prod = wc_get_product($pid);
                if (!$prod) {
                    continue;
                }

                // For variable products, iterate variations
                if ($prod->is_type('variable')) {
                    foreach ($prod->get_children() as $var_id) {
                        $variation = wc_get_product($var_id);
                        if (!$variation) continue;
                        $regular = $variation->get_regular_price();
                        $sale = $variation->get_sale_price();

                        // Round the sale price safely to 2 decimals (coerce to float first)
                        if ($sale !== '' && $sale !== null) {
                            $sale = round(floatval($sale), 2);
                        }


                        $regular_num = $regular !== '' ? floatval($regular) : null;
                        $sale_num = $sale !== '' ? floatval($sale) : null;
                        if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                            $variation->set_sale_price('');
                            $variation->save();
                            $updated++;
                            $this->logger->info('Removed sale price for variation ID ' . $var_id . ' (regular == sale)');
                        }
                    }
                } else {
                    $regular = $prod->get_regular_price();
                    $sale = $prod->get_sale_price();
                    // Round the sale price safely to 2 decimals (coerce to float first)
                    if ($sale !== '' && $sale !== null) {
                        $sale = round(floatval($sale), 2);
                    }

                    $regular_num = $regular !== '' ? floatval($regular) : null;
                    $sale_num = $sale !== '' ? floatval($sale) : null;
                    if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                        $prod->set_sale_price('');
                        $prod->save();
                        $updated++;
                        $this->logger->info('Removed sale price for product ID ' . $pid . ' (regular == sale)');
                    }
                }
            }

            // Free memory and advance
            wp_reset_postdata();
            $paged++;
            if (function_exists('set_time_limit')) {
                @set_time_limit(30);
            }
        } while ($count === $batch_size);

        $this->logger->info('Cleanup completed. Processed: ' . $processed . ' Updated: ' . $updated);
        return array('processed' => $processed, 'updated' => $updated);
    }

    /**
     * Process a single page of products and remove sale prices equal to regular price.
     * Returns counts for the page.
     *
     * @param int $paged
     * @param int $batch_size
     * @return array ['processed' => int, 'updated' => int]
     */
    public function cleanup_remove_equal_sale_prices_page($paged = 1, $batch_size = 100) {
        $updated = 0;
        $processed = 0;

        $args = array(
            'post_type' => array('product', 'product_variation'),
            'posts_per_page' => $batch_size,
            'paged' => $paged,
            'fields' => 'ids',
            'no_found_rows' => true,
        );

        $query = new \WP_Query($args);
        $ids = $query->posts;

        foreach ($ids as $pid) {
            $processed++;
            $prod = wc_get_product($pid);
            if (!$prod) continue;

            if ($prod->is_type('variable')) {
                foreach ($prod->get_children() as $var_id) {
                    $variation = wc_get_product($var_id);
                    if (!$variation) continue;
                    $regular = $variation->get_regular_price();
                    $sale = $variation->get_sale_price();

                        // Round the sale price safely to 2 decimals (coerce to float first)
                        if ($sale !== '' && $sale !== null) {
                            $sale = round(floatval($sale), 2);
                        }

                    $regular_num = $regular !== '' ? floatval($regular) : null;
                    $sale_num = $sale !== '' ? floatval($sale) : null;
                    if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                        $variation->set_sale_price('');
                        $variation->save();
                        $updated++;
                        $this->logger->info('Removed sale price for variation ID ' . $var_id . ' (regular == sale)');
                    }
                }
            } else {
                $regular = $prod->get_regular_price();
                $sale = $prod->get_sale_price();

                        // Round the sale price safely to 2 decimals (coerce to float first)
                        if ($sale !== '' && $sale !== null) {
                            $sale = round(floatval($sale), 2);
                        }

                $regular_num = $regular !== '' ? floatval($regular) : null;
                $sale_num = $sale !== '' ? floatval($sale) : null;
                if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                    $prod->set_sale_price('');
                    $prod->save();
                    $updated++;
                    $this->logger->info('Removed sale price for product ID ' . $pid . ' (regular == sale)');
                }
            }
        }

        wp_reset_postdata();
        return array('processed' => $processed, 'updated' => $updated);
    }
} 