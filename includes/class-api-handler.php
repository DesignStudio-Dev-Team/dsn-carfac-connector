<?php
namespace DSNCarfac;

class API_Handler {
    /**
     * API base URL
     *
     * @var string
     */
    /**
     * Provider instance implementing API_Provider_Interface
     *
     * @var API_Provider_Interface
     */
    private $provider;

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

        // Sole provider: Carfac
        $this->provider = new Carfac_Provider();
    }

    /**
     * Make a GET request to the Carfac API
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|WP_Error Response data or error
     */
    public function get($endpoint, $params = array()) {
        $this->logger->info(sprintf('Provider GET: %s %s', $endpoint, json_encode($params)));
        return $this->provider->get($endpoint, $params);
    }

    /**
     * Make a POST request to the Carfac API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    public function post($endpoint, $data = array()) {
        $this->logger->info(sprintf('Provider POST: %s %s', $endpoint, json_encode($data)));
        return $this->provider->post($endpoint, $data);
    }

    /**
     * Make a PUT request to the Carfac API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    public function put($endpoint, $data = array()) {
        $this->logger->info(sprintf('Provider PUT: %s %s', $endpoint, json_encode($data)));
        return $this->provider->put($endpoint, $data);
    }

    /**
     * Get products from Carfac API
     *
     * @param array $params Query parameters
     * @return array|WP_Error Products data or error
     */
    public function get_products($params = array()) {
        $this->logger->info('API_Handler: get_products');
        return $this->provider->get_products($params);
    }

    /**
     * Get one page of products from Carfac API.
     *
     * @param int $offset Start record offset
     * @param int $limit Number of records to request
     * @param array $params Additional provider params
     * @return array|WP_Error Products data or error
     */
    public function get_products_page($offset, $limit, $params = array()) {
        if (method_exists($this->provider, 'get_products_page')) {
            return $this->provider->get_products_page((int) $offset, (int) $limit, $params);
        }

        $params['startAtRecord'] = (int) $offset;
        $params['numberOfRecords'] = (int) $limit;
        return $this->provider->get_products($params);
    }

    /**
     * Lightweight connection test against the provider.
     *
     * @return array{ok: bool, message: string, sample: ?array}
     */
    public function test_connection() {
        if (method_exists($this->provider, 'test_connection')) {
            return $this->provider->test_connection();
        }

        $response = $this->provider->get_products(['pageSize' => 1]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message(), 'sample' => null];
        }
        return ['ok' => true, 'message' => 'OK', 'sample' => null];
    }

    /**
     * Get Carfac parts that match a list of WooCommerce SKUs (used as Carfac PartName).
     *
     * @param string[] $skus Woo SKUs to look up in Carfac
     * @return array|WP_Error Normalized product rows or WP_Error
     */
    public function get_parts_by_skus(array $skus) {
        $this->logger->info(sprintf('API_Handler: get_parts_by_skus (%d SKUs)', count($skus)));
        if (method_exists($this->provider, 'get_parts_by_part_names')) {
            return $this->provider->get_parts_by_part_names($skus);
        }

        return $this->provider->get_products(['partNameList' => array_values($skus)]);
    }

    /**
     * Get product stock from Carfac API
     *
     * @param string $product_id Product ID
     * @return array|WP_Error Stock data or error
     */
    public function get_product_stock($product_id) {
        return $this->provider->get_product_stock($product_id);
    }

    /**
     * Get relations
     *
     * @return array|WP_Error Relations data or error
     */
    public function get_relations() {
        $this->logger->info('API_Handler: get_relations');
        $response = $this->provider->get('relations');
        if (is_wp_error($response)) {
            return $response;
        }
        return $response['Data'] ?? $response;
    }

    /**
     * Create a new relation
     *
     * @param array $customer_data Customer data
     * @return array|WP_Error Relation data or error
     */
    public function create_relation($customer_data) {
        $this->logger->info('API_Handler: create_relation');
        return $this->provider->get_or_create_relation($customer_data);
    }

    /**
     * Get or create relation
     *
     * @param array $customer_data Customer data
     * @param bool $dry_run Whether to perform a dry run
     * @return array|WP_Error Relation data or error
     */
    public function get_or_create_relation($customer_data, $dry_run = false) {
        return $this->provider->get_or_create_relation($customer_data, $dry_run);
    }

    /**
     * Create an order in Carfac
     *
     * @param array $order_data Order data
     * @param bool $dry_run Whether to perform a dry run
     * @return array|WP_Error Order data or error
     */
    public function create_order(array $order_data, bool $dry_run = false) {
        return $this->provider->create_order($order_data, $dry_run);
    }

    /**
     * Update order status in Carfac
     *
     * @param string $order_id Order ID
     * @param string $status New status
     * @return array|WP_Error Response or error
     */
    public function update_order_status($order_id, $status) {
        return $this->provider->update_order_status($order_id, $status);
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
 
