<?php
namespace DSNWooPowerall;

class API_Handler {
    /**
     * API base URL
     *
     * @var string
     */
    private $api_base_url = 'https://connect.powerall.io/v1/';

    /**
     * Tenant name
     *
     * @var string
     */
    private $tenant_name;

    /**
     * API token
     *
     * @var string
     */
    private $token;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        $this->tenant_name = get_option('dsn_woo_powerall_tenant_name');
        $this->token = get_option('dsn_woo_powerall_token');
        
        // Log authentication details (without exposing the full token)
        // $this->logger->info(sprintf(
        //     // 'API Handler initialized with tenant: %s, token: %s...',
        //     $this->tenant_name,
        //     substr($this->token, 0, 4) . '...'
        // ));
    }

    /**
     * Make a GET request to the Powerall API
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|WP_Error Response data or error
     */
    public function get($endpoint, $params = array()) {
        $this->logger->info(sprintf('Making GET request to %s with params: %s', $endpoint, json_encode($params)));
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Make a POST request to the Powerall API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    public function post($endpoint, $data = array()) {
        $this->logger->info(sprintf('Making POST request to %s with data: %s', $endpoint, json_encode($data)));
        return $this->request('POST', $endpoint, array(), $data);
    }

    /**
     * Make a PUT request to the Powerall API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    public function put($endpoint, $data = array()) {
        $this->logger->info(sprintf('Making PUT request to %s with data: %s', $endpoint, json_encode($data)));
        return $this->request('PUT', $endpoint, array(), $data);
    }

    /**
     * Make a request to the Powerall API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    private function request($method, $endpoint, $params = array(), $data = array()) {
        if (empty($this->tenant_name) || empty($this->token)) {
            $error = new \WP_Error(
                'api_credentials_missing',
                __('Powerall CRM tenant name and token are required.', 'dsn-woo-powerall')
            );
            $this->logger->error('API credentials missing: ' . $error->get_error_message());
            return $error;
        }

        $url = trailingslashit($this->api_base_url) . ltrim($endpoint, '/');
        
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        // Create Basic Auth header
        $auth_string = base64_encode($this->tenant_name . ':' . $this->token);
        
        $headers = array(
            'Authorization' => 'Basic ' . $auth_string,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );

        $this->logger->info(sprintf('Request headers: %s', json_encode($headers)));

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        );

        if (!empty($data)) {
            $args['body'] = json_encode($data);
        }

        $this->logger->info(sprintf('Making request to URL: %s', $url));
        
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->logger->error(sprintf('API request failed: %s', $response->get_error_message()));
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // $error = new \WP_Error('invalid_response', __('Invalid response from Powerall API.', 'dsn-woo-powerall'));
            $this->logger->error(sprintf('Invalid JSON response: %s', $body));
            return $error;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        
        $this->logger->info(sprintf(
            'Response received - Status: %d, Headers: %s',
            $response_code,
            json_encode($response_headers)
        ));

        if ($response_code < 200 || $response_code >= 300) {
            $error_message = isset($data['message']) ? $data['message'] : __('Unknown error occurred.', 'dsn-woo-powerall');
            // $error = new \WP_Error('api_error', $error_message, array('status' => $response_code));
            $this->logger->error(sprintf(
                'API error (HTTP %d): %s. Response body: %s',
                $response_code,
                $error_message,
                $body
            ));
            return $error;
        }

        $this->logger->info(sprintf('API request successful (HTTP %d)', $response_code));
        return $data;
    }

    /**
     * Get products from Powerall API
     *
     * @param array $params Query parameters
     * @return array|WP_Error Products data or error
     */
    public function get_products($params = array()) {
        $this->logger->info('Fetching products from Powerall API (export endpoint)');
        $response = $this->get('products/export.JSON?include=Stock');

        if (is_wp_error($response)) {
            return $response;
        }

        // The export endpoint returns an array of products directly
        if (!is_array($response)) {
            $this->logger->error('Invalid response structure from Powerall API export endpoint');
            return new \WP_Error('invalid_response', 'Invalid response structure from Powerall API export endpoint');
        }

        return $response;
    }

    /**
     * Get product stock from Powerall API
     *
     * @param string $product_id Product ID
     * @return array|WP_Error Stock data or error
     */
    public function get_product_stock($product_id) {
        $response = $this->get("products/{$product_id}?include=Stock");
        
        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['Data'][0]['StockPerWarehouse'])) {
            // return new \WP_Error('invalid_response', 'No stock information available');
        }

        $stock_data = array();
        foreach ($response['Data'][0]['StockPerWarehouse'] as $stock) {
            $stock_data[] = array(
                'EconomicalStock' => $stock['EconomicalStock'] ?? 0,
                'FreeStock' => $stock['FreeStock'] ?? 0,
                'ShelfStock' => $stock['ShelfStock'] ?? 0
            );
        }

        return $stock_data;
    }

    /**
     * Get relation by email
     *
     * @param string $email Email address
     * @return array|WP_Error Relation data or error
     */
    public function get_relation_by_email($email) {
        $this->logger->info('Searching for relation with email: ' . $email);
        
        // Get all relations
        $response = $this->get('relations');
        
        // $this->logger->info('Get Relations repsonse: ' . var_dump($response));
        
        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['Data'])) {
            return null;
        }

        // Search for the relation with matching email
        foreach ($response['Data'] as $relation) {
            if (isset($relation['EmailAddress']) && strtolower($relation['EmailAddress']) === strtolower($email)) {
                $this->logger->info('Found relation with code: ' . $relation['RelationCode']);
                return $relation;
            }
        }

        $this->logger->info('No relation found with email: ' . $email);
        return null;
    }

    /**
     * Get relation by ID
     *
     * @param string $id Relation ID
     * @return array|WP_Error Relation data or error
     */
    public function get_relation_by_id($id) {
        $this->logger->info('Getting relation by ID: ' . $id);
        
        $response = $this->get('relations');
        
        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['Data'])) {
            return null;
        }

        // Search for the relation with matching ID
        foreach ($response['Data'] as $relation) {
            if (isset($relation['Id']) && $relation['Id'] === $id) {
                $this->logger->info('Found relation with ID: ' . $id . ', IsDebtor: ' . ($relation['IsDebtor'] ? 'true' : 'false'));
                return $relation;
            }
        }

        return null;
    }

    /**
     * Get relations
     *
     * @return array|WP_Error Relations data or error
     */
    public function get_relations() {
        $this->logger->info('Fetching relations from Powerall API');
        $response = $this->get('relations');
        
        //get the 1st relation only id
        if (is_wp_error($response)) {
            return $response;
        }
        if (empty($response['Data'])) {
            return null;
        }
        return $response['Data'][0];
    }



    /**
     * Create a new relation
     *
     * @param array $customer_data Customer data
     * @return array|WP_Error Relation data or error
     */
    public function create_relation($customer_data) {
        $this->logger->info('Creating new relation with data: ' . json_encode($customer_data));

        $relation_data = array(
            'Name1' => $customer_data['customer']['first_name'] . ' ' . $customer_data['customer']['last_name'],
            'EmailAddress' => $customer_data['customer']['email'],
            'AddressLine' => $customer_data['billing_address']['address_1'] ?? '',
            'ZipCode' => $customer_data['billing_address']['postcode'] ?? '',
            'Town' => $customer_data['billing_address']['city'] ?? '',
            'CountryCode' => 1, // Default to Netherlands
            'Type' => 'Personal',
            'Phone' => $customer_data['phone'] ?? '',
            'PayVat' => true
        );


        $this->logger->info('Sending relation data to Powerall: ' . json_encode($relation_data));
        $response = $this->post('relations', $relation_data);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to create relation: ' . $response->get_error_message());
            return $response;
        }

        // Log the full response for debugging
        $this->logger->info('Powerall response for relation creation: ' . json_encode($response));

        if (empty($response['Data'])) {
            $error = new \WP_Error('invalid_response', 'No relation data in response');
            $this->logger->error('Failed to create relation: ' . $error->get_error_message());
            return $error;
        }

        // Get the relation ID from the response
        $relation = $response['Data'];
        if (empty($relation['Id'])) {
            $error = new \WP_Error('invalid_response', 'No relation ID in response');
            $this->logger->error('Failed to create relation: ' . $error->get_error_message());
            return $error;
        }

        $this->logger->info('Successfully created relation with ID: ' . $relation['Id']);
        return $relation;
    }

    /**
     * Get or create relation
     *
     * @param array $customer_data Customer data
     * @return array|WP_Error Relation data or error
     */
    public function get_or_create_relation($customer_data) {
        // First try to find existing relation
        $relation = $this->get_relation_by_email($customer_data['customer']['email']);
        
        if (is_wp_error($relation)) {
            return $relation;
        }

        // If relation exists, return it
        if ($relation) {
            $this->logger->info('Found existing relation with ID: ' . $relation['Id']);
            return $relation;
        } else {

            // If not found, create new relation
            $this->logger->info('No existing relation found, creating new one');
            $new_relation = $this->create_relation($customer_data);
            
            if (is_wp_error($new_relation)) {
                return $new_relation;
            }

            // Verify we have a relation ID
            if (empty($new_relation['Id'])) {
                $error = new \WP_Error('invalid_response', 'No relation ID in new relation');
                $this->logger->error('Failed to get relation ID: ' . $error->get_error_message());
                return $error;
            }

            $this->logger->info('Successfully created new relation with ID: ' . $new_relation['Id']);
            return $new_relation;
        }
    }

    /**
     * Create order in Powerall API
     *
     * @param array $order_data Order data
     * @param bool $simulate Whether to simulate the order creation
     * @return array|WP_Error Order data or error
     */
    public function create_order($order_data, $simulate = false) {

        //get get_or_create_relation with order_data
        $relation = $this->get_or_create_relation($order_data);
        if (is_wp_error($relation)) {
            return $relation;
        }

        if(isset($relation['Id'])) {
            $relation_id = $relation['Id'];
        } else {
            $this->logger->info('error getting relation id');

        }

        $this->logger->info('Using test relation ID: ' . $relation_id);

        // Transform WooCommerce order data to Powerall sales order format
        $sales_order = array(
            'RelationId' => $relation_id,
            'EntryNumber' => intval($order_data['order_number'] ?? 0),
            'OrderDate' => date('Y-m-d'),
            'DeliveryDate' => date('Y-m-d', strtotime('+1 day')),
            'Lines' => array_map(function($item) {
                // $uniqueID =  $this->get_product_uid($item['product_id']);
                return array(
                    'ProductCode' => $item['sku'],
                    'QuantityOrdered' => intval($item['quantity']),
                    'GrossPrice' => floatval($item['price']),
                    'PriceIncVat' => true
                );
            }, $order_data['items']),
            'DeliveryAddress' => isset($order_data['shipping_address']) ? array(
                'Address' => $order_data['shipping_address']['address_1'],
                'City' => $order_data['shipping_address']['city'],
                'PostalCode' => $order_data['shipping_address']['postcode'],
                'Country' => $order_data['shipping_address']['country']
            ) : null,
            'InvoiceAddress' => isset($order_data['billing_address']) ? array(
                'Address' => $order_data['billing_address']['address_1'],
                'City' => $order_data['billing_address']['city'],
                'PostalCode' => $order_data['billing_address']['postcode'],
                'Country' => $order_data['billing_address']['country']
            ) : null
        );

        $endpoint = $simulate ? 'sales-orders/simulate' : 'sales-orders';
        $this->logger->info(sprintf('Creating %s sales order with data: %s', 
            $simulate ? 'simulated' : 'actual',
            json_encode($sales_order)
        ));
        
        return $this->post($endpoint, $sales_order);
    }

        /**
     * Get WooCommerce Unique ID
     *
     * @param string $product_id Product ID
     */
    public function get_product_uid($product_id) {
        return get_post_meta($product_id, '_global_unique_id', true);
    }

} 