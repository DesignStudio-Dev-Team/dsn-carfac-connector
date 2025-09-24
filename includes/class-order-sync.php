<?php
namespace DSNWooPowerall;

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
            return new \WP_Error('invalid_order', __('Invalid order.', 'dsn-woo-powerall'));
        }

        // Prepare order data for Powerall CRM
        $order_data = $this->prepare_order_data($order);
        
        // Create order in Powerall CRM
        $result = $this->api_handler->create_order($order_data);
        
        if (is_wp_error($result)) {
            // Log error
            $order->add_order_note(
                sprintf(
                    __('Failed to create order in Powerall CRM: %s', 'dsn-woo-powerall'),
                    $result->get_error_message()
                )
            );
            return $result;
        }

        // Save Powerall CRM order ID
        $order->update_meta_data('_powerall_order_id', $result['id']);
        $order->save();

        // Add success note
        $order->add_order_note(
            sprintf(
                __('Order created in Powerall CRM with ID: %s', 'dsn-woo-powerall'),
                $result['id']
            )
        );

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
            return new \WP_Error('invalid_order', __('Invalid order.', 'dsn-woo-powerall'));
        }

        $powerall_order_id = $order->get_meta('_powerall_order_id');
        if (!$powerall_order_id) {
            return new \WP_Error('no_powerall_id', __('Order not synced with Powerall CRM.', 'dsn-woo-powerall'));
        }

        // Map WooCommerce status to Powerall CRM status
        $powerall_status = $this->map_order_status($new_status);

        // Update order status in Powerall CRM
        $result = $this->api_handler->update_order_status($powerall_order_id, $powerall_status);

        if (is_wp_error($result)) {
            // Log error
            $order->add_order_note(
                sprintf(
                    __('Failed to update order status in Powerall CRM: %s', 'dsn-woo-powerall'),
                    $result->get_error_message()
                )
            );
            return $result;
        }

        // Add success note
        $order->add_order_note(
            sprintf(
                __('Order status updated in Powerall CRM to: %s', 'dsn-woo-powerall'),
                $powerall_status
            )
        );

        return true;
    }

    /**
     * Prepare order data for Powerall CRM
     *
     * @param WC_Order $order WooCommerce order
     * @return array Order data for Powerall CRM
     */
    private function prepare_order_data($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $powerall_id = $product ? $product->get_meta('_powerall_product_id') : null;

            if ($powerall_id) {
                $items[] = array(
                    'product_id' => $powerall_id,
                    'quantity' => $item->get_quantity(),
                    'price' => $item->get_total(),
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
     * Map WooCommerce order status to Powerall CRM status
     *
     * @param string $wc_status WooCommerce order status
     * @return string Powerall CRM order status
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