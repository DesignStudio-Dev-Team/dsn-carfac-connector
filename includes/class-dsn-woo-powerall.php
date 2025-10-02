<?php
namespace DSNWooPowerall;

class DSN_Woo_Powerall {
    /**
     * Plugin instance.
     *
     * @var DSN_Woo_Powerall
     */
    private static $instance = null;

    /**
     * API handler instance.
     *
     * @var API_Handler
     */
    private $api_handler;

    /**
     * Admin settings instance.
     *
     * @var Admin_Settings
     */
    private $admin_settings;

    /**
     * Product sync handler instance.
     *
     * @var Product_Sync
     */
    private $product_sync;

    /**
     * Order sync handler instance.
     *
     * @var Order_Sync
     */
    private $order_sync;

    /**
     * Initialize the plugin.
     */
    public function init() {
        // Load dependencies
        $this->load_dependencies();

        // Initialize components
        $this->init_components();

        // Register hooks
        $this->register_hooks();
    }

    /**
     * Load plugin dependencies.
     */
    private function load_dependencies() {
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-api-handler.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-order-sync.php';
    }

    /**
     * Initialize plugin components.
     */
    private function init_components() {
        $this->api_handler = new API_Handler();
        $this->admin_settings = new Admin_Settings();
        $this->product_sync = new Product_Sync($this->api_handler);
        $this->order_sync = new Order_Sync($this->api_handler);
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks() {
        // Schedule daily sync for prices and stock
        if (!wp_next_scheduled('dsn_woo_powerall_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'dsn_woo_powerall_daily_sync');
        }

        // Schedule weekly log clear
        if (!wp_next_scheduled('dsn_woo_powerall_weekly_clear_logs')) {
            wp_schedule_event(time(), 'weekly', 'dsn_woo_powerall_weekly_clear_logs');
        }

        // Add sync action hook for products
        add_action('dsn_woo_powerall_daily_sync', array($this->product_sync, 'sync_products'));
        // Add weekly log clear action
        add_action('dsn_woo_powerall_weekly_clear_logs', array($this, 'clear_all_logs'));
    
    
         // Add order hooks - only from WooCommerce to Powerall
        add_action('woocommerce_checkout_order_processed', array($this->order_sync, 'handle_new_order'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this->order_sync, 'handle_order_status_change'), 10, 3);

        // Add stock check before purchase
        // add_action('woocommerce_check_cart_items', array($this, 'check_cart_stock'));
        // add_action('woocommerce_before_checkout_process', array($this, 'check_cart_stock'));

        // Add admin menu
        add_action('admin_menu', array($this->admin_settings, 'add_admin_menu'));

    }
  
    /**
     * Check stock for all items in cart
     */
    public function check_cart_stock() {
        if (is_admin()) {
            return;
        }

       foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
       $quantity = $cart_item['quantity'];

            $result = $this->product_sync->check_stock_before_purchase($product_id, $quantity);
            if (is_wp_error($result)) {
               wc_add_notice($result->get_error_message(), 'error');
            return;
          }
        }
    }

    /**
     * Get plugin instance.
     *
     * @return DSN_Woo_Powerall
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Clear all plugin logs (weekly cron)
     */
    public function clear_all_logs() {
        // Clear main logger log
        $logger = new Logger();
        $logger->clear_log();
        // Optionally clear other logs (e.g., product_changes_log.txt)
        $product_log = dirname(__FILE__) . '/../product_changes_log.txt';
        if (file_exists($product_log)) {
            file_put_contents($product_log, '');
        }
    }
}