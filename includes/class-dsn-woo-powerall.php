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
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

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
     * Enqueue admin scripts for cleanup UI
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin admin page
        if ($hook !== 'toplevel_page_dsn-woo-powerall') {
            return;
        }
        wp_enqueue_script('dsn-woo-cleanup', plugins_url('../assets/js/cleanup.js', __FILE__), array('jquery'), false, true);
        wp_localize_script('dsn-woo-cleanup', 'DSNWooPowerall', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dsn_woo_powerall_cleanup_nonce'),
        ));
    }

    /**
     * AJAX handler for cleanup
     */
    public function ajax_cleanup_sale_prices() {
        // Log incoming AJAX request for debugging
        $this->logger->info('AJAX cleanup request received: ' . print_r(array('post' => $_POST, 'user' => get_current_user_id()), true));

        if (!current_user_can('manage_options') || !check_ajax_referer('dsn_woo_powerall_cleanup_nonce', 'nonce', false)) {
            $this->logger->warning('AJAX cleanup unauthorized or nonce failed. User: ' . get_current_user_id());
            wp_send_json_error(array('message' => 'unauthorized'), 403);
        }

        $batch = isset($_POST['batch']) ? intval($_POST['batch']) : 100;
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;

        // Setup temporary error handler to catch warnings/notices as exceptions for logging
        $prev_error_handler = set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            require_once __DIR__ . '/class-api-handler.php';
            require_once __DIR__ . '/class-product-sync.php';
            $api_handler = new API_Handler();
            $sync = new Product_Sync($api_handler);

            $page_result = $sync->cleanup_remove_equal_sale_prices_page($page, $batch);
            if (!is_array($page_result)) {
                $this->logger->error('AJAX cleanup page processing returned invalid result for page ' . $page);
                throw new \Exception('cleanup_failed');
            }

            // Estimate total pages by querying a small WP_Query with no_found_rows=false for total
            $count_query = new \WP_Query(array(
                'post_type' => array('product', 'product_variation'),
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => false,
            ));
            $total_posts = isset($count_query->found_posts) ? $count_query->found_posts : 0;
            $total_pages = $batch > 0 ? max(1, ceil($total_posts / $batch)) : 1;

            // Restore previous error handler
            if ($prev_error_handler !== null) {
                set_error_handler($prev_error_handler);
            }

            wp_send_json_success(array(
                'page' => $page,
                'processed' => $page_result['processed'],
                'updated' => $page_result['updated'],
                'total_pages' => $total_pages,
            ));

        } catch (\Throwable $e) {
            // Restore previous error handler
            if ($prev_error_handler !== null) {
                set_error_handler($prev_error_handler);
            }
            $this->logger->error('AJAX cleanup exception: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => $e->getMessage(), 'trace' => $e->getTraceAsString()), 500);
        }
    }

    /**
     * Load plugin dependencies.
     */
    private function load_dependencies() {
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-logger.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-api-handler.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-order-sync.php';
        // GitHub updater (optional) - enables updates from GitHub releases/tags
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-github-updater.php';
        // Load optional WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/cli-commands.php';
        }
    }

    /**
     * Initialize plugin components.
     */
    private function init_components() {
        $this->api_handler = new API_Handler();
        $this->logger = new Logger();
        $this->admin_settings = new Admin_Settings();
        $this->product_sync = new Product_Sync($this->api_handler);
        $this->order_sync = new Order_Sync($this->api_handler);
        // Initialize GitHub updater to enable plugin updates from GitHub releases
        try {
            // Repo: DesignStudio-Dev-Team/dsn-woo-powerall-connector
            new \DSNWooPowerall\GitHub_Updater('DesignStudio-Dev-Team', 'dsn-woo-powerall-connector', DSN_WOO_POWERALL_PLUGIN_FILE);
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->warning('GitHub Updater initialization failed: ' . $e->getMessage());
            }
        }
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

    // Register AJAX handler for cleanup
    add_action('wp_ajax_dsn_woo_powerall_cleanup', array($this, 'ajax_cleanup_sale_prices'));
    // Enqueue admin scripts
    add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

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