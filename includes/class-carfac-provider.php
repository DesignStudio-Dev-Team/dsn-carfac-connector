<?php
namespace DSNCarfac;

/**
 * Adapter for Carfac ERP provider
 */
class Carfac_Provider implements API_Provider_Interface {
    private $logger;
    private $api_base_url = 'https://cloud.carfac.com/standard/api/';
    private $jwt_option_key = 'dsn_carfac_jwt';
    private $jwt_expiry_option_key = 'dsn_carfac_jwt_expires_at';
    private $jwt_legacy_transient_key = 'dsn_carfac_jwt';
    private $jwt_ttl = 10800; // 3 hours in seconds

    public function __construct() {
        $this->logger = new Logger();
    }

    private function get_cached_jwt() {
        $jwt = get_option($this->jwt_option_key, '');
        $expires_at = (int) get_option($this->jwt_expiry_option_key, 0);

        if (is_string($jwt) && $jwt !== '' && $expires_at > time()) {
            return $jwt;
        }

        // Legacy transient fallback (for upgrades from earlier versions).
        $legacy = get_transient($this->jwt_legacy_transient_key);
        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        return '';
    }

    private function store_jwt($jwt) {
        update_option($this->jwt_option_key, (string) $jwt, false);
        update_option($this->jwt_expiry_option_key, time() + $this->jwt_ttl, false);
    }

    private function forget_jwt() {
        delete_option($this->jwt_option_key);
        delete_option($this->jwt_expiry_option_key);
        delete_transient($this->jwt_legacy_transient_key);
    }

    /**
     * Get Carfac login credentials from current settings, with legacy fallbacks.
     *
     * @return array{username:string,password:string,dealer:string}
     */
    private function get_credentials() {
        $username = get_option('DSN_CARFAC_carfac_username');
        $password = get_option('DSN_CARFAC_carfac_password');
        $dealer = get_option('DSN_CARFAC_carfac_dealer_code');

        if (empty($username) || empty($password)) {
            $username = get_option('DSN_CARFAC_tenant_name');
            $password = get_option('DSN_CARFAC_token');
        }

        return [
            'username' => is_string($username) ? trim($username) : '',
            'password' => is_string($password) ? trim($password) : '',
            'dealer' => is_string($dealer) ? trim($dealer) : $dealer,
        ];
    }

    /**
     * Ensure we have a valid JWT for Carfac; caches the token in wp_options so
     * it survives object-cache eviction.
     *
     * @param bool $force_refresh If true, ignore any cached token and re-login.
     * @return string|WP_Error JWT token string or WP_Error on failure.
     */
    private function ensure_jwt($force_refresh = false) {
        if (!$force_refresh) {
            $cached = $this->get_cached_jwt();
            if ($cached !== '') {
                return $cached;
            }
        }

        $credentials = $this->get_credentials();
        $username = $credentials['username'];
        $password = $credentials['password'];
        $dealer = $credentials['dealer'];

        $this->logger->info(sprintf(
            'Carfac login: username=%s | password=%s | dealer=%s',
            $username !== '' ? '[set]' : '[MISSING]',
            $password !== '' ? '[set]' : '[MISSING]',
            $dealer !== '' ? $dealer : '[MISSING]'
        ));

        if (empty($username) || empty($password) || empty($dealer)) {
            return new \WP_Error('carfac_credentials_missing', __('Carfac credentials (Username, Password, DealerCode) are required.', 'dsn-carfac'));
        }

        $login_payload = [
            'Username' => $username,
            'Password' => $password,
            'DealerCode' => (int) $dealer,
        ];

        $login_url = trailingslashit($this->api_base_url) . 'User/Login';
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($login_payload),
            'timeout' => 15,
        ];

        $this->logger->info('Carfac: Requesting JWT via User/Login');
        $resp = wp_remote_request($login_url, $args);
        if (is_wp_error($resp)) {
            $this->logger->error('Carfac login request failed: ' . $resp->get_error_message());
            return $resp;
        }

        $status_code = wp_remote_retrieve_response_code($resp);
        $body = trim(wp_remote_retrieve_body($resp));
        if ($status_code < 200 || $status_code >= 300) {
            $this->logger->error('Carfac login failed with HTTP ' . $status_code . ': ' . substr($body, 0, 300));
            return new \WP_Error('carfac_login_failed', sprintf(__('Carfac login failed (HTTP %d): %s', 'dsn-carfac'), $status_code, $body));
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $jwt = $decoded['token'] ?? $decoded['Token'] ?? $decoded['jwt'] ?? $decoded['JWT'] ?? $decoded['CarfacApiJWT'] ?? null;
        } else {
            $jwt = is_string($decoded) && $decoded !== '' ? $decoded : $body;
        }

        if (empty($jwt) || !is_string($jwt)) {
            $this->logger->error('Carfac login: no JWT in response. Body preview: ' . substr($body, 0, 300));
            return new \WP_Error('no_jwt', 'No JWT returned from Carfac login');
        }

        $this->store_jwt($jwt);
        $this->logger->info(sprintf('Carfac login: JWT acquired (length=%d), cached for %d seconds.', strlen($jwt), $this->jwt_ttl));
        return $jwt;
    }

    private function request($method, $endpoint, $params = [], $data = []) {
        $credentials = $this->get_credentials();
        if (empty($credentials['username']) || empty($credentials['password']) || empty($credentials['dealer'])) {
            return new \WP_Error('api_credentials_missing', __('Carfac username, password, and dealer code are required.', 'dsn-carfac'));
        }

        $url = trailingslashit($this->api_base_url) . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        // First attempt with the cached JWT; on 401/403 we refresh and retry once.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $jwt = $this->ensure_jwt($attempt > 0);
            if (is_wp_error($jwt)) {
                return $jwt;
            }

            $args = [
                'method' => $method,
                'headers' => [
                    'CarfacApiJWT' => $jwt,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json, text/plain',
                ],
                'timeout' => 45,
            ];
            if (!empty($data)) {
                $args['body'] = json_encode($data);
            }

            // Log the outgoing request body (truncated) so we can verify the
            // request shape — partNameList, paging.numberOfRecords, etc.
            $body_preview = isset($args['body']) ? substr((string) $args['body'], 0, 800) : '(no body)';
            $this->logger->info(sprintf('Carfac %s %s body: %s', $method, $endpoint, $body_preview));

            $resp = wp_remote_request($url, $args);
            if (is_wp_error($resp)) {
                return $resp;
            }

            $status_code = (int) wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);

            // Log the raw response body (truncated) so we can see exactly what
            // Carfac returned — request body was logged just above, so request
            // and response now sit next to each other in the log.
            $resp_preview = substr((string) $body, 0, 2000);
            $this->logger->info(sprintf(
                'Carfac %s %s response HTTP %d: %s%s',
                $method,
                $endpoint,
                $status_code,
                $resp_preview,
                strlen((string) $body) > 2000 ? ' [truncated]' : ''
            ));

            // Auth expired/invalid → drop cached JWT, retry once with a fresh one.
            if (($status_code === 401 || $status_code === 403) && $attempt === 0) {
                $this->logger->warning(sprintf(
                    'Carfac %s %s returned HTTP %d; refreshing JWT and retrying once.',
                    $method,
                    $endpoint,
                    $status_code
                ));
                $this->forget_jwt();
                continue;
            }

            if ($status_code < 200 || $status_code >= 300) {
                $preview = trim(wp_strip_all_tags((string) $body));
                $preview = substr($preview, 0, 300);
                $this->logger->error(sprintf(
                    'Carfac %s %s HTTP %d: %s',
                    $method,
                    $endpoint,
                    $status_code,
                    $preview
                ));
                return new \WP_Error(
                    'carfac_http_error',
                    sprintf('Carfac %s returned HTTP %d: %s', $endpoint, $status_code, $preview)
                );
            }

            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $preview = trim(wp_strip_all_tags((string) $body));
                $preview = substr($preview, 0, 300);
                return new \WP_Error(
                    'invalid_response',
                    sprintf('Invalid JSON response from %s HTTP %d: %s', $endpoint, $status_code, $preview)
                );
            }
            return $decoded;
        }

        return new \WP_Error('carfac_auth_failed', 'Carfac authentication failed after JWT refresh attempt.');
    }

    public function get(string $endpoint, array $params = []) {
        return $this->request('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $data = []) {
        return $this->request('POST', $endpoint, [], $data);
    }

    public function put(string $endpoint, array $data = []) {
        return $this->request('PUT', $endpoint, [], $data);
    }

    private function extract_products_from_response($response) {
        if (!is_array($response)) {
            return [];
        }

        foreach (['Data', 'data', 'Products', 'products', 'ProductList', 'productList', 'Items', 'items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }

        if (isset($response['ProductCode']) || isset($response['productCode']) || isset($response['ProductId']) || isset($response['productId'])) {
            return [$response];
        }

        return array_values($response) === $response ? $response : [];
    }

    private function extract_total_count_from_response($response) {
        if (!is_array($response)) {
            return null;
        }

        foreach ([
            'TotalRecords',
            'totalRecords',
            'TotalRecordCount',
            'totalRecordCount',
            'TotalCount',
            'totalCount',
            'RecordCount',
            'recordCount',
            'Count',
            'count',
            'Total',
            'total',
        ] as $key) {
            if (isset($response[$key]) && is_numeric($response[$key])) {
                return (int) $response[$key];
            }
        }

        foreach (['Paging', 'paging', 'Pagination', 'pagination'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $total = $this->extract_total_count_from_response($response[$key]);
                if ($total !== null) {
                    return $total;
                }
            }
        }

        return null;
    }

    private function get_first_row_value(array $row, array $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private function normalize_field_name($name) {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $name));
    }

    private function get_named_custom_field_value(array $row, array $names) {
        $customFields = $row['CustomFields'] ?? $row['customFields'] ?? null;
        if (!is_array($customFields)) {
            return null;
        }

        $targets = array_map([$this, 'normalize_field_name'], $names);
        foreach ($customFields as $key => $field) {
            if (!is_array($field)) {
                if (is_string($key) && in_array($this->normalize_field_name($key), $targets, true) && $field !== '') {
                    return $field;
                }
                continue;
            }

            $fieldName = $this->get_first_row_value($field, [
                'Name', 'name',
                'FieldName', 'fieldName',
                'Label', 'label',
                'Caption', 'caption',
                'Description', 'description',
                'Key', 'key',
                'Code', 'code',
            ]);

            if (($fieldName === null || $fieldName === '') && is_string($key)) {
                $fieldName = $key;
            }

            if ($fieldName === null || !in_array($this->normalize_field_name($fieldName), $targets, true)) {
                continue;
            }

            return $this->get_first_row_value($field, [
                'Value', 'value',
                'FieldValue', 'fieldValue',
                'Text', 'text',
                'NumberValue', 'numberValue',
                'DecimalValue', 'decimalValue',
                'Amount', 'amount',
            ]);
        }

        return null;
    }

    private function get_carfac_sale_price(array $row) {
        $salePrice = $this->get_first_row_value($row, [
            'TijdelijkePrijs', 'tijdelijkePrijs',
            'Tijdelijke Prijs', 'tijdelijke prijs',
            'TijdelijkePrijsInclVat', 'tijdelijkePrijsInclVat',
            'Tijdelijke Prijs Incl VAT', 'Tijdelijke Prijs incl. VAT',
            'TijdelijkePrijsInclBtw', 'tijdelijkePrijsInclBtw',
            'Tijdelijke Prijs incl. btw',
            'TemporaryPrice', 'temporaryPrice',
            'TemporarySalePrice', 'temporarySalePrice',
            'TemporarySalesPrice', 'temporarySalesPrice',
            'TemporarySellingPrice', 'temporarySellingPrice',
            'TemporarySellingPriceInclVat', 'temporarySellingPriceInclVat',
            'TemporarySellingPriceInclBtw', 'temporarySellingPriceInclBtw',
            'TijdelijkeVerkoopprijs', 'tijdelijkeVerkoopprijs',
            'PromoPrice', 'promoPrice',
            'PromotionPrice', 'promotionPrice',
            'PromotionalPrice', 'promotionalPrice',
            'ActionPrice', 'actionPrice',
            'ActiePrijs', 'actiePrijs',
            'Actieprijs', 'actieprijs',
            'Aanbiedingsprijs', 'aanbiedingsprijs',
            'SalePrice', 'salePrice',
        ]);

        if ($salePrice !== null) {
            return $salePrice;
        }

        return $this->get_named_custom_field_value($row, [
            'Tijdelijke Prijs',
            'Tijdelijke Prijs incl. VAT',
            'Tijdelijke prijs incl. btw',
            'Temporary Price',
            'Temporary Sale Price',
            'Temporary Sales Price',
            'Temporary Selling Price',
            'Temporary Selling Price incl. VAT',
            'Temporary Selling Price incl. btw',
            'Tijdelijke Verkoopprijs',
            'Sale Price',
            'Promo Price',
            'Promotion Price',
            'Promotional Price',
            'Actieprijs',
            'Actie Prijs',
            'Aanbiedingsprijs',
        ]);
    }

    private function normalize_product(array $product) {
        $stockPerWarehouse = $product['StockPerWarehouse'] ?? $product['stockPerWarehouse'] ?? [];
        $totalStock = $product['TotalStock'] ?? $product['totalStock'] ?? null;

        if ($totalStock === null && is_array($stockPerWarehouse)) {
            $totalStock = 0;
            foreach ($stockPerWarehouse as $warehouseStock) {
                $totalStock += (int) ($warehouseStock['FreeStock'] ?? $warehouseStock['freeStock'] ?? 0);
                $totalStock += (int) ($warehouseStock['ShelfStock'] ?? $warehouseStock['shelfStock'] ?? 0);
                $totalStock += (int) ($warehouseStock['EconomicalStock'] ?? $warehouseStock['economicalStock'] ?? 0);
            }
        }

        return array_merge($product, [
            'CarfacSource' => 'products',
            'CarfacProductId' => (string) ($product['ProductId'] ?? $product['productId'] ?? ''),
            'CarfacProductCode' => (string) ($product['ProductCode'] ?? $product['productCode'] ?? ''),
            'CarfacPartId' => (string) ($product['PartId'] ?? $product['partId'] ?? ''),
            'ProductCode' => (string) ($product['ProductCode'] ?? $product['productCode'] ?? $product['EanCode'] ?? $product['eanCode'] ?? $product['Sku'] ?? $product['sku'] ?? ''),
            'EanCode' => (string) ($product['EanCode'] ?? $product['eanCode'] ?? $product['ProductCode'] ?? $product['productCode'] ?? $product['Sku'] ?? $product['sku'] ?? ''),
            'Description1' => $product['Description1'] ?? $product['description1'] ?? $product['Description'] ?? $product['description'] ?? $product['Name'] ?? $product['name'] ?? '',
            'SalesPrice' => $this->get_first_row_value($product, ['SalesPrice', 'salesPrice', 'SellingPrice', 'sellingPrice']) ?? 0,
            'SalePrice' => $this->get_carfac_sale_price($product),
            'PurchasePrice' => $product['PurchasePrice'] ?? $product['purchasePrice'] ?? null,
            'SalesPriceIsIncVat' => $product['SalesPriceIsIncVat'] ?? $product['salesPriceIsIncVat'] ?? null,
            'StockPerWarehouse' => is_array($stockPerWarehouse) ? $stockPerWarehouse : [],
            'TotalStock' => (int) ($totalStock ?? 0),
        ]);
    }

    public function get_products(array $params = []) {
        return $this->get_products_from_parts($params);
    }

    /**
     * Lightweight connection test:
     *   1. Forces a fresh User/Login to verify credentials.
     *   2. POSTs a minimal Part/GetParts with paging.numberOfRecords=1, using
     *      the full request schema (all fields present, null where unused) so
     *      Carfac never rejects the request for missing keys.
     *
     * @return array{ok: bool, message: string, sample: ?array}
     */
    public function test_connection() {
        $this->forget_jwt();
        $jwt = $this->ensure_jwt(true);
        if (is_wp_error($jwt)) {
            return [
                'ok' => false,
                'message' => 'Login failed: ' . $jwt->get_error_message(),
                'sample' => null,
            ];
        }

        $request = $this->build_part_request([], [
            'paging' => [
                'startAtRecord' => 0,
                'numberOfRecords' => 1,
            ],
        ]);

        $response = $this->post('Part/GetParts', $request);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => 'Part/GetParts failed: ' . $response->get_error_message(),
                'sample' => null,
            ];
        }

        $parts = $this->extract_products_from_response($response);
        return [
            'ok' => true,
            'message' => sprintf('Login OK, Part/GetParts returned %d record(s).', count($parts)),
            'sample' => $parts[0] ?? null,
        ];
    }

    /**
     * Fetch Carfac parts for a specific list of part names (WooCommerce SKUs),
     * enriched with stock from Part/GetPartStock. Designed for the manual sync
     * loop so we only ever ask Carfac for parts we actually have in Woo.
     *
     * @param string[] $partNames Part names / Woo SKUs to look up
     * @return array|WP_Error Normalized product rows or WP_Error
     */
    public function get_parts_by_part_names(array $partNames) {
        $partNames = $this->normalize_part_names($partNames);
        if (empty($partNames)) {
            return [];
        }

        $request = $this->build_part_request([], [
            'partNameList' => array_values($partNames),
        ]);

        $response = $this->post('Part/GetParts', $request);
        if (is_wp_error($response)) {
            $this->logger->error('Carfac Part/GetParts (SKU chunk) failed: ' . $response->get_error_message());
            return $response;
        }

        $parts = $this->extract_products_from_response($response);
        if (empty($parts)) {
            $this->logger->info(sprintf(
                'Carfac Part/GetParts (SKU chunk) returned 0 matches for %d requested SKUs.',
                count($partNames)
            ));
            return [];
        }

        $partIds = array_values(array_filter(array_map(function($part) {
            return (string) ($part['PartId'] ?? $part['partId'] ?? '');
        }, $parts)));

        $stockMap = !empty($partIds) ? $this->get_stock_map_for_parts($partIds) : [];

        $normalized = [];
        foreach ($parts as $part) {
            $partId = (string) ($part['PartId'] ?? $part['partId'] ?? '');
            $normalized[] = $this->normalize_part_product($part, $partId, $stockMap[(string) $partId] ?? null);
        }

        $this->logger->info(sprintf(
            'Carfac Part/GetParts (SKU chunk): requested=%d, matched=%d',
            count($partNames),
            count($normalized)
        ));

        return $normalized;
    }

    public function get_products_page(int $offset, int $limit, array $params = []) {
        $limit = $limit > 0 ? $limit : 100;
        $products = $this->get_products_from_parts_page($offset, $limit, $params);
        if (is_wp_error($products)) {
            return $products;
        }

        $this->logger->info(sprintf(
            'Carfac GetParts page fetched: start=%d, fetched=%d',
            $offset,
            count($products)
        ));
        return $products;
    }

    private function get_products_from_product_api(array $params = []) {
        $this->logger->info('Carfac: Fetching products via Product/GetProducts');
        $pageSize = $params['pageSize'] ?? $params['numberOfRecords'] ?? $params['NumberOfRecords'] ?? 100;
        unset($params['pageSize'], $params['numberOfRecords'], $params['NumberOfRecords']);

        $startAt = 0;
        $allProducts = [];
        $expectedTotal = null;
        $seenProductKeys = [];

        do {
            $request = $this->build_product_request($params, $startAt, $pageSize);

            $response = $this->post('Product/GetProducts', $request);
            if (is_wp_error($response)) {
                return $response;
            }

            $responseTotal = $this->extract_total_count_from_response($response);
            if ($responseTotal !== null) {
                $expectedTotal = $responseTotal;
            }

            $cachedBeforePage = count($allProducts);
            $products = $this->extract_products_from_response($response);
            if (empty($products)) {
                $this->logger->warning('Carfac Product/GetProducts returned no product list. Response keys: ' . implode(', ', array_keys((array) $response)));
            }

            foreach ($products as $product) {
                $normalizedProduct = $this->normalize_product($product);
                $productKey = $normalizedProduct['EanCode'] ?: md5(wp_json_encode($normalizedProduct));
                if (isset($seenProductKeys[$productKey])) {
                    continue;
                }

                $seenProductKeys[$productKey] = true;
                $allProducts[] = $normalizedProduct;
            }

            $fetched = count($products);
            $this->logger->info(sprintf(
                'Carfac products page fetched: start=%d, page_size=%d, page_count=%d, total_cached=%d%s',
                $startAt,
                $pageSize,
                $fetched,
                count($allProducts),
                $expectedTotal !== null ? ', expected_total=' . $expectedTotal : ''
            ));

            if ($fetched === 0) {
                break;
            }

            if ($expectedTotal !== null && count($allProducts) === $cachedBeforePage) {
                $this->logger->warning('Carfac products paging returned no new unique products; stopping to avoid repeating the same page.');
                break;
            }

            $startAt += (int) $pageSize;
        } while (
            ($expectedTotal !== null && count($allProducts) < $expectedTotal)
            || ($expectedTotal === null && $fetched === (int) $pageSize)
        );

        return $allProducts;
    }

    private function get_products_from_parts(array $params = []) {
        $this->logger->info('Carfac: Fetching products via Part/GetParts using paged extendedView=true response');
        $products = $this->get_products_from_parts_paged($params);
        if (is_wp_error($products)) {
            return $products;
        }

        if (!empty($products)) {
            return $products;
        }

        $this->logger->warning('No parts returned from paged GetParts; falling back to SKU lookup.');
        return $this->get_products_from_parts_by_part_name_list($params);
    }

    private function get_products_from_parts_by_part_name_list(array $params = []) {
        $this->logger->info('Carfac: Fetching parts by WooCommerce SKU lookup.');
        $allProducts = [];
        $seenProductKeys = [];
        $partNames = $this->get_part_names_for_lookup($params);
        unset($params['partNameList'], $params['PartNameList'], $params['partNames'], $params['PartNames']);

        if (empty($partNames)) {
            $this->logger->warning('Carfac Part/GetParts cannot run because there are no WooCommerce SKUs/part names to look up.');
            return [];
        }

        $this->logger->info(sprintf('Carfac SKU lookup: checking %d WooCommerce SKUs.', count($partNames)));

        foreach (array_chunk($partNames, 50) as $chunkIndex => $partNameChunk) {
            if (get_transient('dsn_carfac_sync_stop_requested')) {
                $this->logger->warning('Carfac SKU lookup stopped before chunk ' . ($chunkIndex + 1) . '.');
                break;
            }

            $parts = $this->fetch_parts_by_part_names($partNameChunk, $params, $chunkIndex + 1);
            if (is_wp_error($parts)) {
                return $parts;
            }

            if (empty($parts)) {
                $this->logger->warning('Carfac SKU lookup chunk ' . ($chunkIndex + 1) . ' returned no matching parts.');
                continue;
            }

            $partIds = array_values(array_filter(array_map(function($part) {
                return (string) ($part['PartId'] ?? $part['partId'] ?? '');
            }, $parts)));
            $stockMap = !empty($partIds) ? $this->get_stock_map_for_parts($partIds) : [];
            foreach ($parts as $part) {
                if (get_transient('dsn_carfac_sync_stop_requested')) {
                    $this->logger->warning('Carfac SKU lookup stopped while processing chunk ' . ($chunkIndex + 1) . '.');
                    break 2;
                }

                $partId = (string) ($part['PartId'] ?? $part['partId'] ?? '');
                $normalizedProduct = $this->normalize_part_product($part, $partId, $stockMap[(string) $partId] ?? null);
                $productKey = $normalizedProduct['CarfacPartName'] ?: $normalizedProduct['ProductCode'] ?: $normalizedProduct['EanCode'] ?: (string) $partId;
                if (isset($seenProductKeys[$productKey])) {
                    continue;
                }

                $seenProductKeys[$productKey] = true;
                $allProducts[] = $normalizedProduct;
            }
        }

        $returnedPartNames = array_values(array_filter(array_map(function($product) {
            return (string) ($product['CarfacPartName'] ?? $product['PartName'] ?? $product['partName'] ?? '');
        }, $allProducts)));
        $missingPartNames = array_values(array_diff($partNames, $returnedPartNames));
        if (!empty($missingPartNames)) {
            $this->logger->warning(sprintf(
                'Carfac Part/GetParts did not return records for %d requested WooCommerce SKUs. Sample missing: %s%s',
                count($missingPartNames),
                implode(', ', array_slice($missingPartNames, 0, 20)),
                count($missingPartNames) > 20 ? ', ...' : ''
            ));
        }

        $this->logger->info(sprintf('Carfac SKU lookup complete: found %d matching parts.', count($allProducts)));
        return $allProducts;
    }

    private function get_products_from_parts_paged(array $params = []) {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $pageSize = $params['pageSize'] ?? $params['numberOfRecords'] ?? $params['NumberOfRecords'] ?? 100;
        unset($params['pageSize'], $params['numberOfRecords'], $params['NumberOfRecords'], $params['partNameList'], $params['PartNameList'], $params['partNames'], $params['PartNames']);

        $startAt = 0;
        $allProducts = [];
        $seenProductKeys = [];
        $pageDelayMicros = 250000;
        $page = 0;

        do {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            if (get_transient('dsn_carfac_sync_stop_requested')) {
                $this->logger->warning('Carfac paged Part/GetParts fetch stopped before startAt=' . $startAt . ' because a stop was requested.');
                break;
            }

            $parts = $this->get_products_from_parts_page($startAt, (int) $pageSize, $params);
            if (is_wp_error($parts)) {
                return $parts;
            }
            if (empty($parts)) {
                break;
            }

            $addedThisPage = 0;
            foreach ($parts as $normalizedProduct) {
                $productKey = $normalizedProduct['CarfacPartName'] ?: $normalizedProduct['ProductCode'] ?: $normalizedProduct['EanCode'] ?: $normalizedProduct['CarfacPartId'];
                if ($productKey === '' || isset($seenProductKeys[$productKey])) {
                    continue;
                }

                $seenProductKeys[$productKey] = true;
                $allProducts[] = $normalizedProduct;
                $addedThisPage++;
            }

            if ($addedThisPage === 0 && count($parts) > 0) {
                $this->logger->warning('Carfac paged Part/GetParts returned only duplicate/empty-key records; stopping to avoid looping.');
                break;
            }

            $startAt += (int) $pageSize;
            $page++;

            $this->logger->info(sprintf(
                'Carfac GetParts page %d: fetched=%d, total_cached=%d',
                $page,
                $addedThisPage,
                count($allProducts)
            ));

            if (count($parts) === (int) $pageSize && $pageDelayMicros > 0) {
                usleep($pageDelayMicros);
            }
        } while (count($parts) === (int) $pageSize);

        $this->logger->info(sprintf('Carfac GetParts fetch complete: found %d parts.', count($allProducts)));
        return $allProducts;
    }

    private function get_products_from_parts_page(int $startAt, int $pageSize, array $params = []) {
        unset($params['pageSize'], $params['numberOfRecords'], $params['NumberOfRecords'], $params['partNameList'], $params['PartNameList'], $params['partNames'], $params['PartNames']);

        $request = $this->build_part_request($params, [
            'paging' => [
                'startAtRecord' => $startAt,
                'numberOfRecords' => $pageSize,
            ],
        ]);

        $response = $this->post('Part/GetParts', $request);
        if (is_wp_error($response)) {
            $this->logger->error('Carfac paged Part/GetParts failed: ' . $response->get_error_message());
            return $response;
        }

        $parts = $this->extract_products_from_response($response);
        if (empty($parts)) {
            return [];
        }

        $partIds = array_values(array_filter(array_map(function($part) {
            return (string) ($part['PartId'] ?? $part['partId'] ?? '');
        }, $parts)));
        $stockMap = !empty($partIds) ? $this->get_stock_map_for_parts($partIds) : [];

        $normalizedProducts = [];
        foreach ($parts as $part) {
            $partId = (string) ($part['PartId'] ?? $part['partId'] ?? '');
            $normalizedProducts[] = $this->normalize_part_product($part, $partId, $stockMap[(string) $partId] ?? null);
        }

        return $normalizedProducts;
    }

    private function fetch_parts_by_part_names(array $partNames, array $params, int $chunkNumber) {
        $request = $this->build_part_request($params, [
            'partNameList' => array_values($partNames),
        ]);

        $response = $this->post('Part/GetParts', $request);
        if (is_wp_error($response)) {
            $this->logger->error('Carfac Part/GetParts failed: ' . $response->get_error_message());
            return $response;
        }

        $parts = $this->extract_products_from_response($response);
        if (!empty($parts) || count($partNames) <= 1) {
            return $parts;
        }

        $this->logger->warning(sprintf(
            'Carfac SKU lookup chunk %d returned no matches for %d SKUs; retrying one by one.',
            $chunkNumber,
            count($partNames)
        ));

        $singleParts = [];
        foreach ($partNames as $partName) {
            if (get_transient('dsn_carfac_sync_stop_requested')) {
                $this->logger->warning('Carfac one-by-one SKU lookup stopped.');
                break;
            }

            $singleRequest = $this->build_part_request($params, [
                'partNameList' => [(string) $partName],
            ]);
            $singleResponse = $this->post('Part/GetParts', $singleRequest);
            if (is_wp_error($singleResponse)) {
                $this->logger->warning(sprintf(
                    'Carfac Part/GetParts one-by-one failed for PartName=%s: %s',
                    $partName,
                    $singleResponse->get_error_message()
                ));
                continue;
            }

            $matchedParts = $this->extract_products_from_response($singleResponse);
            foreach ($matchedParts as $matchedPart) {
                $singleParts[] = $matchedPart;
            }
        }

        return $singleParts;
    }

    private function build_part_request(array $params = [], array $overrides = []) {
        $request = [
            'partIdList' => null,
            'partNameList' => null,
            'brandId' => null,
            'description' => null,
            'descriptionDutch' => null,
            'descriptionFrench' => null,
            'descriptionGerman' => null,
            'descriptionEnglish' => null,
            'lastSellingDate' => null,
            // Extended view contains Carfac's additional pricing fields,
            // including Tijdelijke Prijs / temporary sale pricing.
            'extendedView' => true,
            'visibleOnWebshop' => null,
            'webshopGroupLinkId' => null,
            'paging' => [
                'startAtRecord' => 0,
                'numberOfRecords' => 100,
            ],
            'sorting' => null,
            'dateModified' => null,
            'datePriceModified' => null,
            'dateStockModified' => null,
            'dateLinksModified' => null,
            'dateFilesModified' => null,
        ];

        foreach ($params as $key => $value) {
            $normalizedKey = lcfirst($key);
            if (array_key_exists($normalizedKey, $request)) {
                $request[$normalizedKey] = $value;
            }
        }

        foreach ($overrides as $key => $value) {
            $request[$key] = $value;
        }

        return $request;
    }

    private function get_part_names_for_lookup(array $params) {
        foreach (['partNameList', 'PartNameList', 'partNames', 'PartNames'] as $key) {
            if (!empty($params[$key]) && is_array($params[$key])) {
                return $this->normalize_part_names($params[$key]);
            }
        }

        return $this->get_woocommerce_skus();
    }

    private function normalize_part_names(array $partNames) {
        $normalized = [];
        foreach ($partNames as $partName) {
            $partName = is_scalar($partName) ? trim((string) $partName) : '';
            if ($partName !== '') {
                $normalized[] = $partName;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function get_woocommerce_skus() {
        global $wpdb;

        if (empty($wpdb)) {
            return [];
        }

        $skus = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value <> ''
            AND p.post_type IN ('product', 'product_variation')
            AND p.post_status NOT IN ('trash', 'auto-draft')"
        );

        return $this->normalize_part_names(is_array($skus) ? $skus : []);
    }

    private function merge_product_sources(array $products, array $parts) {
        $merged = [];
        $seen = [];

        foreach (array_merge($products, $parts) as $product) {
            $key = $this->get_product_merge_key($product);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $product;
        }

        return $merged;
    }

    private function get_product_merge_key(array $product) {
        foreach (['CarfacProductCode', 'ProductCode', 'EanCode', 'CarfacProductId', 'ProductId', 'CarfacPartId', 'PartId'] as $key) {
            if (!empty($product[$key])) {
                return $key . ':' . (string) $product[$key];
            }
        }

        return md5(wp_json_encode($product));
    }

    private function build_product_request(array $params, int $startAt, int $pageSize) {
        $request = $params;

        if ($startAt === 0) {
            $request['paging'] = [
                'startAtRecord' => $startAt,
                'numberOfRecords' => $pageSize,
            ];
            return $request;
        }

        $request['paging'] = [
            'StartAtRecord' => $startAt,
            'NumberOfRecords' => $pageSize,
        ];

        return $request;
    }

    private function get_stock_map_for_parts(array $partIds) {
        $stockMap = [];
        // Carfac caps paging.numberOfRecords at 100. Chunk partIdList small
        // enough that one warehouse row per part fits comfortably under that.
        foreach (array_chunk($partIds, 50) as $batchIds) {
            $response = $this->post('Part/GetPartStock', [
                'partIdList' => array_values($batchIds),
                'paging' => ['startAtRecord' => 0, 'numberOfRecords' => 100],
            ]);

            if (is_wp_error($response) || !is_array($response)) {
                continue;
            }

            foreach ($response as $item) {
                $partId = (string) ($item['PartId'] ?? $item['partId'] ?? '');
                if ($partId === '') {
                    continue;
                }

                $totalQty = 0;
                $warehouses = [];
                foreach (($item['Warehouse'] ?? $item['warehouse'] ?? []) as $warehouse) {
                    $qty = (int) ($warehouse['QuantityInStock'] ?? $warehouse['quantityInStock'] ?? 0);
                    $totalQty += $qty;
                    $warehouses[] = [
                        'WarehouseId' => $warehouse['WarehouseId'] ?? $warehouse['warehouseId'] ?? null,
                        'WarehouseName' => $warehouse['WarehouseName'] ?? $warehouse['warehouseName'] ?? $warehouse['Name'] ?? $warehouse['name'] ?? 'Unknown',
                        'QuantityInStock' => $qty,
                    ];
                }

                $stockMap[$partId] = [
                    'total' => $totalQty,
                    'warehouses' => $warehouses,
                ];
            }
        }

        return $stockMap;
    }

    private function extract_part_detail($detail) {
        if (!is_array($detail)) {
            return [];
        }

        if (isset($detail['Data']) && is_array($detail['Data'])) {
            return $detail['Data'];
        }

        if (array_values($detail) === $detail && isset($detail[0]) && is_array($detail[0])) {
            return $detail[0];
        }

        return $detail;
    }

    private function normalize_part_product(array $part, string $partId, ?array $stockInfo) {
        $stockInfo = $stockInfo ?? ['total' => 0, 'warehouses' => []];
        $stockPerWarehouse = [];

        foreach ($stockInfo['warehouses'] as $warehouse) {
            $stockPerWarehouse[] = [
                'WarehouseId' => $warehouse['WarehouseId'],
                'WarehouseName' => $warehouse['WarehouseName'],
                'FreeStock' => $warehouse['QuantityInStock'],
                'ShelfStock' => 0,
                'EconomicalStock' => 0,
            ];
        }

        if (empty($stockPerWarehouse)) {
            $stockPerWarehouse[] = [
                'WarehouseId' => null,
                'WarehouseName' => 'Default',
                'FreeStock' => $stockInfo['total'],
                'ShelfStock' => 0,
                'EconomicalStock' => 0,
            ];
        }

        $code = (string) ($part['ProductCode'] ?? $part['productCode'] ?? $part['EanCode'] ?? $part['eanCode'] ?? $part['PartId'] ?? $part['partId'] ?? $partId);

        return array_merge($part, [
            'CarfacSource' => 'parts',
            'CarfacProductId' => (string) ($part['ProductId'] ?? $part['productId'] ?? ''),
            'CarfacProductCode' => (string) ($part['ProductCode'] ?? $part['productCode'] ?? ''),
            'CarfacPartId' => (string) ($part['PartId'] ?? $part['partId'] ?? $partId),
            'CarfacPartName' => (string) ($part['PartName'] ?? $part['partName'] ?? ''),
            'ProductCode' => $code,
            'EanCode' => $code,
            'Description1' => $part['PartName'] ?? $part['partName'] ?? $part['Description1'] ?? $part['description1'] ?? $part['DescriptionEnglish'] ?? $part['descriptionEnglish'] ?? $part['DescriptionDutch'] ?? $part['descriptionDutch'] ?? $part['Description'] ?? $part['description'] ?? '',
            'SalesPrice' => $this->get_first_row_value($part, ['SalesPrice', 'salesPrice', 'SellingPrice', 'sellingPrice']) ?? 0,
            'SalePrice' => $this->get_carfac_sale_price($part),
            'PurchasePrice' => $part['PurchasePrice'] ?? $part['purchasePrice'] ?? null,
            'SalesPriceIsIncVat' => $part['SalesPriceIsIncVat'] ?? $part['salesPriceIsIncVat'] ?? $part['SellingPriceIsIncVat'] ?? $part['sellingPriceIsIncVat'] ?? null,
            'StockPerWarehouse' => $stockPerWarehouse,
            'TotalStock' => (int) $stockInfo['total'],
        ]);
    }

    private function format_part_product_summary(array $product) {
        $keys = array_keys($product);

        return sprintf(
            'PartName=%s | PartId=%s | ProductId=%s | ProductCode=%s | SalesPrice=%s | SalePrice=%s | TotalStock=%s | Keys=%s',
            $product['CarfacPartName'] ?? $product['PartName'] ?? $product['partName'] ?? 'n/a',
            $product['CarfacPartId'] ?? $product['PartId'] ?? $product['partId'] ?? 'n/a',
            $product['CarfacProductId'] ?? $product['ProductId'] ?? $product['productId'] ?? 'n/a',
            $product['CarfacProductCode'] ?? $product['ProductCode'] ?? $product['productCode'] ?? 'n/a',
            $product['SalesPrice'] ?? $product['salesPrice'] ?? 'n/a',
            $product['SalePrice'] ?? $product['salePrice'] ?? 'n/a',
            $product['TotalStock'] ?? $product['totalStock'] ?? 'n/a',
            implode(', ', array_slice($keys, 0, 30)) . (count($keys) > 30 ? ', ...' : '')
        );
    }

    public function get_product_stock(string $product_id) {
        // Call Part/GetPartStock (POST) and sum QuantityInStock across warehouses for the part.
        // Carfac caps paging.numberOfRecords at 100.
        $req = [
            'partIdList' => [$product_id],
            'paging' => ['startAtRecord' => 0, 'numberOfRecords' => 100],
        ];
        $resp = $this->post('Part/GetPartStock', $req);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $quantity = 0;
        // Response is an array of part objects
        if (is_array($resp)) {
            foreach ($resp as $item) {
                if (!empty($item['Warehouse']) && is_array($item['Warehouse'])) {
                    foreach ($item['Warehouse'] as $w) {
                        $quantity += (int) ($w['QuantityInStock'] ?? 0);
                    }
                }
            }
        }

        return ['quantity' => $quantity];
    }

    public function get_or_create_relation(array $customer_data, bool $dry_run = false) {
        // Carfac: lookup by email using Customer/GetCustomers, create via Customer/PostCustomer if not found
        $email = $customer_data['customer']['email'] ?? '';
        if (!$email) {
            return new \WP_Error('no_email', 'Customer email required');
        }

        $this->logger->info('Carfac: Looking up customer by email: ' . $email);

        // --- Attempt 1: Filter search by emailAddress ---
        $existing = $this->find_customer_by_email($email);
        if (is_wp_error($existing)) {
            $this->logger->error('Carfac: Customer lookup failed: ' . $existing->get_error_message());
            // Don't fail here — we'll try the broader search below
        } elseif ($existing !== null) {
            $this->logger->info('Carfac: Found existing customer via filter search. ID: ' . ($existing['CustomerId'] ?? 'unknown'));
            return $existing;
        }

        // --- Attempt 2: Broader search (fetch recent customers and scan for email match) ---
        $this->logger->info('Carfac: Filter search returned no match, trying broader search...');
        $broad_existing = $this->find_customer_by_email_broad($email);
        if ($broad_existing !== null) {
            $this->logger->info('Carfac: Found existing customer via broad search. ID: ' . ($broad_existing['CustomerId'] ?? 'unknown'));
            return $broad_existing;
        }

        $this->logger->info('Carfac: No existing customer found for email: ' . $email . '. Proceeding to create.');

        // Not found — create customer
        $billing = $customer_data['billing_address'] ?? [];

        // Dry-run: don't create a customer, return a simulated customer object
        if ($dry_run) {
            $simId = 'DRYRUN-' . strtoupper(substr(md5($email . time()), 0, 8));
            $this->logger->info('Carfac: Dry-run create relation simulated for ' . $email);
            return [
                'CustomerId' => $simId,
                'EmailAddress' => $email,
                'FirstName' => $customer_data['customer']['first_name'] ?? '',
                'LastName' => $customer_data['customer']['last_name'] ?? '',
            ];
        }
        $relation_data = [
            'name' => $customer_data['customer']['last_name'] ?? ($customer_data['customer']['first_name'] ?? ''),
            'firstName' => $customer_data['customer']['first_name'] ?? '',
            'phone1' => $customer_data['phone'] ?? '',
            'mobilePhone' => $customer_data['customer']['phone'] ?? '',
            'emailAddress' => $email,
            'name2' => '',
            'address' => $billing['address_1'] ?? '',
            'address2' => $billing['address_2'] ?? '',
            'zipCode' => $billing['postcode'] ?? '',
            'city' => $billing['city'] ?? '',
            'countryCode' => $billing['country'] ?? '',
        ];

    $created = $this->post('Customer/PostCustomer', $relation_data);
        if (is_wp_error($created)) {
            return $created;
        }

        $this->logger->info('Carfac: Customer created successfully for email: ' . $email);

        // Created response may return the created customer or an object with CustomerId
        if (isset($created['CustomerId'])) {
            return $created;
        }
        if (isset($created['Data'])) {
            return $created['Data'];
        }

        return $created;
    }

    /**
     * Search for a customer by email using the filtered GetCustomers endpoint.
     *
     * @param string $email
     * @return array|null|WP_Error  Customer array if found, null if not found, WP_Error on failure
     */
    private function find_customer_by_email(string $email) {
        $filter = [
            'emailAddress' => $email,
            'paging' => ['startAtRecord' => 0, 'numberOfRecords' => 50],
        ];
        $resp = $this->post('Customer/GetCustomers', $filter);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $customers = $this->extract_customer_list($resp);
        return $this->match_email_in_list($customers, $email);
    }

    /**
     * Broader fallback search: fetch a larger set of customers and scan for email match.
     * This catches cases where the API filter doesn't support email filtering properly.
     *
     * @param string $email
     * @return array|null  Customer array if found, null if not found
     */
    private function find_customer_by_email_broad(string $email) {
        $filter = [
            'paging' => ['startAtRecord' => 0, 'numberOfRecords' => 500],
        ];
        $resp = $this->post('Customer/GetCustomers', $filter);
        if (is_wp_error($resp)) {
            $this->logger->warning('Carfac: Broad customer search failed: ' . $resp->get_error_message());
            return null;
        }

        $customers = $this->extract_customer_list($resp);
        return $this->match_email_in_list($customers, $email);
    }

    /**
     * Extract a flat array of customer objects from various API response formats.
     *
     * @param mixed $resp  The API response
     * @return array  Flat array of customer associative arrays
     */
    private function extract_customer_list($resp): array {
        if (!is_array($resp)) {
            return [];
        }

        // Format: { "Data": [ ... ] }
        if (isset($resp['Data']) && is_array($resp['Data'])) {
            return $resp['Data'];
        }

        // Format: { "customerIdList": [ ... ] } — would need detail fetching, skip for now
        // Format: flat array of customer objects [ { "CustomerId": ..., "EmailAddress": ... }, ... ]
        if (array_values($resp) === $resp && !empty($resp) && isset($resp[0]) && is_array($resp[0])) {
            return $resp;
        }

        // Format: single customer object { "CustomerId": ..., "EmailAddress": ... }
        if (isset($resp['CustomerId']) || isset($resp['EmailAddress'])) {
            return [$resp];
        }

        return [];
    }

    /**
     * Case-insensitive email match against a list of customer objects.
     * Checks multiple possible email field names.
     *
     * @param array  $customers  Array of customer associative arrays
     * @param string $email      Email to match
     * @return array|null  Matching customer or null
     */
    private function match_email_in_list(array $customers, string $email): ?array {
        $email_lower = strtolower(trim($email));
        // Carfac API may return email under different key names
        $email_fields = ['EmailAddress', 'emailAddress', 'Email', 'email', 'E-mail'];

        foreach ($customers as $cust) {
            if (!is_array($cust)) {
                continue;
            }
            foreach ($email_fields as $field) {
                if (isset($cust[$field]) && strtolower(trim($cust[$field])) === $email_lower) {
                    return $cust;
                }
            }
        }

        return null;
    }

    public function create_order(array $order_data, bool $dry_run = false) {
        // Ensure customer exists in Carfac and get customerId
        $customer = $this->get_or_create_relation($order_data, $dry_run);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $customerId = $customer['CustomerId'] ?? $customer['Id'] ?? ($customer['CustomerID'] ?? null);

        // If dry-run, simulate creation and return without posting to Carfac
        if ($dry_run) {
            $simWorkId = 'DRYRUN-WO-' . strtoupper(substr(md5(json_encode($order_data) . time()), 0, 8));
            $this->logger->info('Carfac: Dry-run create_order called, simulated WorkOrderId: ' . $simWorkId);
            return ['WorkorderId' => $simWorkId, 'CustomerId' => $customerId];
        }

        // Map order_data (coming from Order_Sync) to Carfac WorkOrder/PostWorkOrder
        // Build WorkOrder payload
        $workOrder = [
            'date' => $order_data['date_created'] ?? date('c'),
            // workOrderTypeId default to 2 (example)
            'workOrderTypeId' => $order_data['workOrderTypeId'] ?? 2,
            'customerId' => $customerId,
            'siteId' => $order_data['shipping']['siteId'] ?? 1,
            'brandId' => $order_data['brandId'] ?? null,
            'vehicleId' => $order_data['vehicleId'] ?? null,
            'mileage' => $order_data['mileage'] ?? null,
            // Enforce warehouseId = 1 (hardcoded per request)
            'warehouseId' => 1,
            'noteNumber' => $order_data['external_id'] ?? null,
            'description' => $order_data['notes'] ?? '',
            'deliveryName' => $order_data['shipping']['first_name'] ?? '',
            'deliveryAddress' => $order_data['shipping']['address']['street'] ?? '',
            'deliveryZipCode' => $order_data['shipping']['address']['postcode'] ?? '',
            'deliveryCity' => $order_data['shipping']['address']['city'] ?? '',
        ];

        $resp = $this->post('WorkOrder/PostWorkOrder', $workOrder);
        if (is_wp_error($resp)) {
            return $resp;
        }

        // Response expected { WorkorderId: 100 }
        $workOrderId = $resp['WorkorderId'] ?? ($resp['WorkOrderId'] ?? null);
        if (empty($workOrderId) && isset($resp['Data']['WorkorderId'])) {
            $workOrderId = $resp['Data']['WorkorderId'];
        }
        if (empty($workOrderId)) {
            return new \WP_Error('invalid_response', 'No WorkOrderId returned from Carfac');
        }

        // Build workorder lines from order_data['items']
        $lines = [];
        foreach ($order_data['items'] as $i) {
            $lines[] = [
                'partId' => (string) ($i['product_id'] ?? $i['partId'] ?? ''),
                'description' => $i['name'] ?? '',
                'quantity' => $i['quantity'] ?? 1,
                'unitPrice' => $i['unit_price'] ?? $i['price'] ?? 0,
                'discount' => $i['discount'] ?? 0,
                'taxRate' => $i['taxRate'] ?? null,
                'orderLineType' => $i['orderLineType'] ?? 'V',
                // Enforce warehouseId = 1 for each line
                'warehouseId' => 1,
                'vehicleId' => $order_data['vehicleId'] ?? null,
                'unitPurchasePrice' => $i['unitPurchasePrice'] ?? $i['purchasePrice'] ?? null,
            ];
        }

        $linesPayload = [
            'workOrderId' => $workOrderId,
            'workOrderRowVersion' => 1,
            'workOrderLines' => $lines,
        ];

        $linesResp = $this->post('WorkOrder/PostWorkOrderLines', $linesPayload);
        if (is_wp_error($linesResp)) {
            return $linesResp;
        }

        return ['WorkorderId' => $workOrderId, 'CustomerId' => $customerId];
    }

    public function update_order_status(string $order_id, string $status) {
        return $this->put("orders/{$order_id}", ['status' => $status]);
    }
}
