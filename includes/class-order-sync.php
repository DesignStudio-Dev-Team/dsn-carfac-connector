<?php
namespace DSNCarfac;

class Order_Sync {
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
    }

    /**
     * Handle new WooCommerce order
     *
     * @param int $order_id WooCommerce order ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function handle_new_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', __('Invalid order.', 'dsn-carfac'));
        }

    // Prepare order data for Carfac
    $order_data = $this->prepare_order_data($order);

    // Create order via API handler (delegates to Carfac provider)
    $result = $this->api_handler->create_order($order_data);
        
        if (is_wp_error($result)) {
            // Log error
            $order->add_order_note(
                sprintf(
                    __('Failed to create order in Carfac: %s', 'dsn-carfac'),
                    $result->get_error_message()
                )
            );
            return $result;
        }

        // Save Carfac-specific IDs
        $provider_ids = [];
        if ($existing = $order->get_meta('_sc_provider_ids')) {
            $existing_map = json_decode($existing, true);
            if (is_array($existing_map)) {
                $provider_ids = $existing_map;
            }
        }

        if (isset($result['WorkorderId'])) {
            $order->update_meta_data('_carfac_workorder_id', $result['WorkorderId']);
            $provider_ids['carfac'] = $result['WorkorderId'];
        }
        if (isset($result['CustomerId'])) {
            $order->update_meta_data('_carfac_customer_id', $result['CustomerId']);
            $provider_ids['carfac_customer'] = $result['CustomerId'];
        }

        // Store unified provider id map as JSON
        if (!empty($provider_ids)) {
            $order->update_meta_data('_sc_provider_ids', wp_json_encode($provider_ids));
        }
        $order->save();

        // Add success note(s)
        if (!empty($provider_ids)) {
            foreach ($provider_ids as $k => $v) {
                $order->add_order_note(sprintf(__('Order created in Carfac [%s] with ID: %s', 'dsn-carfac'), esc_html($k), esc_html($v)));
            }
        }

        return true;
    }

    /**
     * Handle order status change
     *
     * @param int $order_id WooCommerce order ID
     * @param string $old_status Old order status
     * @param string $new_status New order status
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', __('Invalid order.', 'dsn-carfac'));
        }

        $carfac_order_id = $order->get_meta('_carfac_workorder_id');
        if (!$carfac_order_id) {
            return new \WP_Error('no_carfac_id', __('Order not synced with Carfac.', 'dsn-carfac'));
        }

        // Map WooCommerce status to Carfac status
        $carfac_status = $this->map_order_status($new_status);

        // Update order status in Carfac
        $result = $this->api_handler->update_order_status($carfac_order_id, $carfac_status);

        if (is_wp_error($result)) {
            // Log error
            $order->add_order_note(
                sprintf(
                    __('Failed to update order status in Carfac: %s', 'dsn-carfac'),
                    $result->get_error_message()
                )
            );
            return $result;
        }

        // Add success note
        $order->add_order_note(
            sprintf(
                __('Order status updated in Carfac to: %s', 'dsn-carfac'),
                $carfac_status
            )
        );

        return true;
    }

    /**
     * Prepare order data for Carfac
     *
     * @param WC_Order $order WooCommerce order
     * @return array Order data for Carfac
     */
    private function prepare_order_data($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            // Prefer Carfac-specific product id meta, fall back to SKU
            $carfac_product_id = null;
            if ($product) {
                $carfac_product_id = $product->get_meta('_carfac_product_id');
                if (empty($carfac_product_id)) {
                    $carfac_product_id = $product->get_sku();
                }
            }

            if ($carfac_product_id) {
                $quantity = $item->get_quantity();
                $line_total = (float) $item->get_total();
                $unit_price = $quantity > 0 ? ($line_total / $quantity) : $line_total;
                $items[] = array(
                    'product_id' => $carfac_product_id,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'price' => $line_total,
                    'name' => $item->get_name(),
                );
            }
        }

        // Get billing address using HPOS-compatible methods
        $billing_address = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'address' => array(
                'street' => $order->get_billing_address_1(),
                'city' => $order->get_billing_city(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ),
        );

        // Get shipping address if different from billing
        if ($order->get_shipping_address_1() && $order->get_shipping_address_1() !== $order->get_billing_address_1()) {
            $shipping_address = array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address' => array(
                    'street' => $order->get_shipping_address_1(),
                    'city' => $order->get_shipping_city(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country(),
                ),
            );
        } else {
            $shipping_address = $billing_address;
        }

        return array(
            'external_id' => $order->get_id(),
            'customer' => $billing_address,
            'shipping' => $shipping_address,
            'items' => $items,
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => $this->map_order_status($order->get_status()),
            'notes' => $order->get_customer_note(),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'date_created' => $order->get_date_created()->format('c'),
            'date_modified' => $order->get_date_modified()->format('c'),
        );
    }

    /**
     * Map WooCommerce order status to Carfac status
     *
     * @param string $wc_status WooCommerce order status
     * @return string Carfac order status
     */
    private function map_order_status($wc_status) {
        $status_map = array(
            'pending' => 'pending',
            'processing' => 'processing',
            'on-hold' => 'on_hold',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
        );

        return isset($status_map[$wc_status]) ? $status_map[$wc_status] : 'pending';
    }
}
 