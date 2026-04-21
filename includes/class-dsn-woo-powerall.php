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
     *
     * @return void
     */
    public function init() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook
     * @return void
     */
    public function enqueue_admin_scripts($hook) {
        $settings_hook = isset($this->admin_settings->settings_page_hook) ? $this->admin_settings->settings_page_hook : 'toplevel_page_dsn-woo-powerall';
        $tools_hook    = isset($this->admin_settings->tools_page_hook) ? $this->admin_settings->tools_page_hook : '';

        if ($hook === $settings_hook) {
            wp_enqueue_style(
                'dsn-location-picker',
                plugins_url('../assets/css/location-picker.css', __FILE__),
                array(),
                DSN_WOO_POWERALL_VERSION
            );
            wp_enqueue_script(
                'dsn-location-picker',
                plugins_url('../assets/js/location-picker.js', __FILE__),
                array(),
                DSN_WOO_POWERALL_VERSION,
                true
            );
            return;
        }

        if ($tools_hook === '' || $hook !== $tools_hook) {
            return;
        }

        wp_enqueue_script(
            'dsn-woo-cleanup',
            plugins_url('../assets/js/cleanup.js', __FILE__),
            array('jquery'),
            DSN_WOO_POWERALL_VERSION,
            true
        );
        wp_localize_script('dsn-woo-cleanup', 'DSNWooPowerall', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dsn_woo_powerall_cleanup_nonce'),
        ));

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        if ($view !== 'sync-progress') {
            return;
        }

        wp_enqueue_script(
            'dsn-woo-sync-progress',
            plugins_url('../assets/js/sync-progress.js', __FILE__),
            array('jquery'),
            DSN_WOO_POWERALL_VERSION,
            true
        );
        wp_localize_script('dsn-woo-sync-progress', 'DSNWooPowerallSync', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dsn_woo_powerall_sync_progress'),
            'auto_start' => isset($_GET['start']),
            'progress_url' => add_query_arg(
                array(
                    'page' => 'dsn-woo-powerall-tools',
                    'view' => 'sync-progress',
                ),
                admin_url('admin.php')
            ),
            'state' => $this->product_sync->get_manual_sync_state(),
        ));
    }

    /**
     * AJAX handler for cleanup.
     *
     * @return void
     */
    public function ajax_cleanup_sale_prices() {
        $this->logger->info('AJAX cleanup request received: ' . print_r(array('post' => $_POST, 'user' => get_current_user_id()), true));

        if (!current_user_can('manage_options') || !check_ajax_referer('dsn_woo_powerall_cleanup_nonce', 'nonce', false)) {
            $this->logger->warning('AJAX cleanup unauthorized or nonce failed. User: ' . get_current_user_id());
            wp_send_json_error(array('message' => 'unauthorized'), 403);
        }

        $batch = isset($_POST['batch']) ? intval($_POST['batch']) : 100;
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;

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

            $count_query = new \WP_Query(array(
                'post_type' => array('product', 'product_variation'),
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => false,
            ));
            $total_posts = isset($count_query->found_posts) ? $count_query->found_posts : 0;
            $total_pages = $batch > 0 ? max(1, ceil($total_posts / $batch)) : 1;

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
            if ($prev_error_handler !== null) {
                set_error_handler($prev_error_handler);
            }
            $this->logger->error('AJAX cleanup exception: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => $e->getMessage(), 'trace' => $e->getTraceAsString()), 500);
        }
    }

    /**
     * AJAX handler to start a manual sync run.
     *
     * @return void
     */
    public function ajax_start_manual_sync() {
        $this->authorize_sync_ajax();

        $result = $this->product_sync->start_manual_sync_run();
        if (is_wp_error($result)) {
            $this->logger->error('Manual sync start failed: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler to process a manual sync batch.
     *
     * @return void
     */
    public function ajax_process_manual_sync_batch() {
        $this->authorize_sync_ajax();

        $run_id = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';
        $result = $this->product_sync->process_manual_sync_batch($run_id);
        if (is_wp_error($result)) {
            $this->logger->error('Manual sync batch failed: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }

        wp_send_json_success($result);
    }

    /**
     * Load plugin dependencies.
     *
     * @return void
     */
    private function load_dependencies() {
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-logger.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-api-handler.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-order-sync.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-stock-display.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-product-meta-box.php';
        require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-github-updater.php';
        if (defined('WP_CLI') && WP_CLI) {
            require_once DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/cli-commands.php';
        }
    }

    /**
     * Initialize plugin components.
     *
     * @return void
     */
    private function init_components() {
        $this->api_handler = new API_Handler();
        $this->logger = new Logger();
        $this->admin_settings = new Admin_Settings();
        $this->product_sync = new Product_Sync($this->api_handler);
        $this->order_sync = new Order_Sync($this->api_handler);
        new Stock_Display();
        if (is_admin()) {
            new Product_Meta_Box();
        }

        try {
            new \DSNWooPowerall\GitHub_Updater('DesignStudio-Dev-Team', 'dsn-woo-powerall-connector', DSN_WOO_POWERALL_PLUGIN_FILE, '', $this->logger);
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->warning('GitHub Updater initialization failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    private function register_hooks() {
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));

        if (!wp_next_scheduled('dsn_woo_powerall_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'dsn_woo_powerall_daily_sync');
        }

        // Migrate away from the older weekly log cleanup cron if it is still scheduled.
        $legacy_clear_logs = wp_next_scheduled('dsn_woo_powerall_weekly_clear_logs');
        if ($legacy_clear_logs) {
            wp_unschedule_event($legacy_clear_logs, 'dsn_woo_powerall_weekly_clear_logs');
        }

        if (!wp_next_scheduled('dsn_woo_powerall_biweekly_clear_logs')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'dsn_woo_powerall_fortnightly', 'dsn_woo_powerall_biweekly_clear_logs');
        }

        add_action('dsn_woo_powerall_daily_sync', array($this->product_sync, 'sync_products'));
        add_action('dsn_woo_powerall_biweekly_clear_logs', array($this, 'clear_all_logs'));
        // Back-compat: still run the handler if the legacy hook fires (another cron runner may trigger it once).
        add_action('dsn_woo_powerall_weekly_clear_logs', array($this, 'clear_all_logs'));

        add_action('woocommerce_checkout_order_processed', array($this->order_sync, 'handle_new_order'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this->order_sync, 'handle_order_status_change'), 10, 3);

        add_action('admin_menu', array($this->admin_settings, 'add_admin_menu'));
        add_action('wp_ajax_dsn_woo_powerall_cleanup', array($this, 'ajax_cleanup_sale_prices'));
        add_action('wp_ajax_dsn_woo_powerall_start_sync', array($this, 'ajax_start_manual_sync'));
        add_action('wp_ajax_dsn_woo_powerall_process_sync_batch', array($this, 'ajax_process_manual_sync_batch'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Check stock for all items in cart.
     *
     * @return void
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
     * Register custom WP-Cron schedules used by this plugin.
     * Adds a 14-day "fortnightly" interval for the log-cleanup cron.
     *
     * @param array $schedules
     * @return array
     */
    public function add_custom_cron_schedules($schedules) {
        if (!isset($schedules['dsn_woo_powerall_fortnightly'])) {
            $schedules['dsn_woo_powerall_fortnightly'] = array(
                'interval' => 14 * DAY_IN_SECONDS,
                'display'  => __('Every 2 weeks', 'dsn-woo-powerall'),
            );
        }

        return $schedules;
    }

    /**
     * Clear all plugin logs (runs every 2 weeks via WP-Cron).
     *
     * @return void
     */
    public function clear_all_logs() {
        $logger = new Logger();
        $logger->clear_log();

        $product_log = dirname(__FILE__) . '/../product_changes_log.txt';
        if (file_exists($product_log)) {
            file_put_contents($product_log, '');
        }
    }

    /**
     * Verify that the current AJAX request can manage manual syncs.
     *
     * @return void
     */
    private function authorize_sync_ajax() {
        if (!current_user_can('manage_options') || !check_ajax_referer('dsn_woo_powerall_sync_progress', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Unauthorized request.', 'dsn-woo-powerall')), 403);
        }
    }
}
