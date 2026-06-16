<?php
namespace DSNCarfac;

/**
 * Interface for API providers
 */
interface API_Provider_Interface {
    /**
     * Make a GET request to provider API
     * @param string $endpoint
     * @param array $params
     * @return array|\WP_Error
     */
    public function get(string $endpoint, array $params = []);

    /**
     * Make a POST request to provider API
     */
    public function post(string $endpoint, array $data = []);

    /**
     * Make a PUT request to provider API
     */
    public function put(string $endpoint, array $data = []);

    /**
     * Get products in a provider-agnostic shape
     */
    public function get_products(array $params = []);

    /**
     * Get product stock by product identifier
     */
    public function get_product_stock(string $product_id);

    /**
     * Create or get relation (customer)
     */
    public function get_or_create_relation(array $customer_data, bool $dry_run = false);

    /**
     * Create an order
     */
    public function create_order(array $order_data, bool $dry_run = false);

    /**
     * Update order status
     */
    public function update_order_status(string $order_id, string $status);
}

