<?php
namespace DSNCarfac;

class Test {
    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * API Handler instance
     *
     * @var API_Handler
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        $this->api = new API_Handler();
    }

    /**
     * Run all tests
     */
    public function run_all_tests() {
        $this->test_api_connection();
        $this->test_extended_view_probe();
        $this->test_specific_sku('42280112937');
        $productCode = $this->test_product_sync();
        if ($productCode) {
            $this->test_order_sync($productCode);
        }
    }

    /**
     * Probe what extra fields Carfac returns when extendedView=true. We're
     * looking for an EAN/barcode field on the part record — if it exists, the
     * Woo SKUs (which look like EAN-13) could potentially be matched against
     * it instead of PartName.
     */
    public function test_extended_view_probe() {
        $this->logger->info('--- test_extended_view_probe START ---');

        $provider_response = $this->api->post('Part/GetParts', [
            'partIdList' => null,
            'partNameList' => null,
            'brandId' => null,
            'description' => null,
            'descriptionDutch' => null,
            'descriptionFrench' => null,
            'descriptionGerman' => null,
            'descriptionEnglish' => null,
            'lastSellingDate' => null,
            'extendedView' => true,
            'visibleOnWebshop' => null,
            'webshopGroupLinkId' => null,
            'paging' => ['startAtRecord' => 0, 'numberOfRecords' => 1],
            'sorting' => null,
            'dateModified' => null,
            'datePriceModified' => null,
            'dateStockModified' => null,
            'dateLinksModified' => null,
            'dateFilesModified' => null,
        ]);

        if (is_wp_error($provider_response)) {
            $this->logger->error('test_extended_view_probe failed: ' . $provider_response->get_error_message());
            $this->logger->info('--- test_extended_view_probe END ---');
            return;
        }

        $parts = is_array($provider_response) ? $provider_response : [];
        if (empty($parts) || !isset($parts[0])) {
            $this->logger->warning('test_extended_view_probe: response was empty.');
            $this->logger->info('--- test_extended_view_probe END ---');
            return;
        }

        $first = $parts[0];
        $keys = is_array($first) ? array_keys($first) : [];
        $this->logger->info('test_extended_view_probe: extendedView=true returned ' . count($keys) . ' fields per part.');
        $this->logger->info('test_extended_view_probe: field names: ' . implode(', ', $keys));

        // Highlight any field that looks like it might hold a barcode/EAN.
        $ean_candidates = [];
        foreach ($keys as $k) {
            $lower = strtolower($k);
            if (strpos($lower, 'ean') !== false
                || strpos($lower, 'gtin') !== false
                || strpos($lower, 'barcode') !== false
                || strpos($lower, 'code') !== false) {
                $ean_candidates[$k] = $first[$k];
            }
        }

        if (!empty($ean_candidates)) {
            $this->logger->info('test_extended_view_probe: potential EAN/barcode fields: ' . wp_json_encode($ean_candidates));
        } else {
            $this->logger->warning('test_extended_view_probe: no EAN/barcode-looking fields found in extendedView response.');
        }

        $this->logger->info('--- test_extended_view_probe END ---');
    }

    /**
     * Probe Carfac for a single SKU and log the raw response. The provider's
     * request() already logs the request body and the truncated raw response;
     * this helper just makes the call and logs whether the SKU appeared in
     * the parsed list.
     */
    public function test_specific_sku($sku) {
        $sku = is_scalar($sku) ? trim((string) $sku) : '';
        if ($sku === '') {
            $this->logger->warning('test_specific_sku: empty SKU; skipping.');
            return;
        }

        $this->logger->info('--- test_specific_sku START — SKU=' . $sku . ' ---');
        $parts = $this->api->get_parts_by_skus([$sku]);
        if (is_wp_error($parts)) {
            $this->logger->error('test_specific_sku failed: ' . $parts->get_error_message());
            $this->logger->info('--- test_specific_sku END ---');
            return;
        }

        $count = is_array($parts) ? count($parts) : 0;
        $this->logger->info(sprintf('test_specific_sku: Carfac returned %d normalized record(s) for PartName=%s.', $count, $sku));

        if ($count > 0) {
            // Dump the first record's full normalized shape (truncated).
            $first = $parts[0];
            $dump = wp_json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($dump === false) {
                $dump = print_r($first, true);
            }
            $this->logger->info('test_specific_sku: first normalized record: ' . substr((string) $dump, 0, 4000));
        }

        $this->logger->info('--- test_specific_sku END ---');
    }

    /**
     * Test API connection — lightweight: forces a User/Login then POSTs one
     * minimal Part/GetParts request (paging.numberOfRecords=1, full schema).
     */
    public function test_api_connection() {
        $this->logger->info('Starting API connection test');

        $username = get_option('DSN_CARFAC_carfac_username');
        $password = get_option('DSN_CARFAC_carfac_password');
        $dealer = get_option('DSN_CARFAC_carfac_dealer_code');
        if (empty($username) || empty($password) || empty($dealer)) {
            $this->logger->error('Carfac credentials not configured (username/password/DealerCode missing)');
            return false;
        }

        $result = $this->api->test_connection();
        if (empty($result['ok'])) {
            $this->logger->error('API connection test failed: ' . $result['message']);
            return false;
        }

        $this->logger->info('API connection test successful — ' . $result['message']);
        if (!empty($result['sample']) && is_array($result['sample'])) {
            $this->logger->info('Sample part: ' . json_encode([
                'PartId'       => $result['sample']['PartId'] ?? null,
                'PartName'     => $result['sample']['PartName'] ?? null,
                'SellingPrice' => $result['sample']['SellingPrice'] ?? $result['sample']['SalesPrice'] ?? null,
            ]));
        }
        return true;
    }

    /**
     * Test product sync by sampling up to 25 Woo products with a
     * dss_syndified.article_id and looking those article_ids up in Carfac
     * via Part/GetParts partNameList — same code path the live sync uses.
     */
    public function test_product_sync() {
        $this->logger->info('Starting product sync test');

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'dss_syndified'
            AND pm.meta_value <> ''
            AND p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            ORDER BY pm.meta_id ASC
            LIMIT 25"
        );

        $pairs = [];
        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row->meta_value, true);
            if (!is_array($decoded)) {
                $maybe = @unserialize((string) $row->meta_value);
                if (is_array($maybe)) {
                    $decoded = $maybe;
                }
            }
            if (!is_array($decoded)) {
                continue;
            }
            $article_id = $decoded['article_id']
                ?? $decoded['articleId']
                ?? $decoded['ArticleId']
                ?? null;
            if ($article_id === null || $article_id === '') {
                continue;
            }
            $article_id = is_scalar($article_id) ? trim((string) $article_id) : '';
            if ($article_id === '') {
                continue;
            }
            $pairs[] = ['article_id' => $article_id, 'post_id' => (int) $row->post_id];
        }

        if (empty($pairs)) {
            $this->logger->warning('Product sync test: no Woo products with dss_syndified.article_id found.');
            return false;
        }

        $article_ids = array_column($pairs, 'article_id');
        $this->logger->info(sprintf(
            'Product sync test: looking up %d article_ids in Carfac (sample: %s)',
            count($article_ids),
            implode(', ', array_slice($article_ids, 0, 5)) . (count($article_ids) > 5 ? ', ...' : '')
        ));

        $parts = $this->api->get_parts_by_skus($article_ids);
        if (is_wp_error($parts)) {
            $this->logger->error('Product sync test failed: ' . $parts->get_error_message());
            return false;
        }

        $byPartName = [];
        foreach ((array) $parts as $part) {
            $name = $part['CarfacPartName'] ?? $part['PartName'] ?? '';
            if ($name !== '') {
                $byPartName[$name] = $part;
            }
        }

        $matched = 0;
        $missing = [];
        foreach ($pairs as $pair) {
            $aid = $pair['article_id'];
            if (isset($byPartName[$aid])) {
                $matched++;
            } else {
                $missing[] = $aid . ' (post_id=' . $pair['post_id'] . ')';
            }
        }

        $this->logger->info(sprintf(
            'Product sync test: %d/%d article_ids matched a Carfac PartName.',
            $matched,
            count($pairs)
        ));

        if (!empty($missing)) {
            $this->logger->warning(sprintf(
                'Product sync test: article_ids with NO Carfac PartName match (sample): %s%s',
                implode(', ', array_slice($missing, 0, 10)),
                count($missing) > 10 ? ', ...' : ''
            ));
        }

        if (!empty($byPartName)) {
            $sample = reset($byPartName);
            $this->logger->info('Product sync test: sample matched part: ' . json_encode([
                'PartId'       => $sample['PartId'] ?? null,
                'PartName'     => $sample['CarfacPartName'] ?? $sample['PartName'] ?? null,
                'SellingPrice' => $sample['SalesPrice'] ?? $sample['SellingPrice'] ?? null,
                'TotalStock'   => $sample['TotalStock'] ?? null,
            ]));
        }

        return $article_ids[0];
    }

    /**
     * Test order synchronization
     */
    public function test_order_sync($productCode) {
        $this->logger->info('Starting order sync test');

        // Create test order data this is how WooCommerce will bring out the data
        $order_data = array(
            'external_id' => 'TEST-' . time(),
            'customer' => array(
                'email' => 'testds@designstudio.com',
                'first_name' => 'Test',
                'last_name' => 'User',
            ),
            'items' => array(
                array(
                    'product_id' => $productCode,
                    'quantity' => 1,
                    'unit_price' => 0.00,
                    'price' => 0.00,
                    'name' => 'Test product',
                )
            ),
            'shipping' => array(
                'address' => array('street' => '123 Test Street', 'city' => 'Test City', 'postcode' => '12345', 'country' => 'NL'),
                'first_name' => 'Test',
                'last_name' => 'User'
            ),
            'billing_address' => array('address_1' => '123 Test Street', 'city' => 'Test City', 'postcode' => '12345', 'country' => 'NL')
        );

        // First try to create the order on Carfac
        $this->logger->info('Creating test order on Carfac');
        $simulation = $this->api->create_order($order_data);
        
        if (is_wp_error($simulation)) {
            $this->logger->error('Order sync test failed: ' . $simulation->get_error_message());
            return false;
        }

        $this->logger->info('Order creation test successful: ' . json_encode($simulation));
        return true;
    }
}
 
