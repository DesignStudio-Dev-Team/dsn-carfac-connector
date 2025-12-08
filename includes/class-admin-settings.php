<?php
namespace DSNWooPowerall;

class Admin_Settings {
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
        add_action('admin_init', array($this, 'register_settings'));
        $this->logger = new Logger();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('DSN Woo To Powerall', 'dsn-woo-powerall'),
            __('DSN Woo To Powerall', 'dsn-woo-powerall'),
            'manage_options',
            'dsn-woo-powerall',
            array($this, 'render_settings_page'),
            'dashicons-update',
            56
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_tenant_name');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_token');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_sync_frequency');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_use_sale_price');

        add_settings_section(
            'dsn_woo_powerall_main_section',
            __('API Settings', 'dsn-woo-powerall'),
            array($this, 'render_section_info'),
            'dsn-woo-powerall'
        );

        add_settings_field(
            'dsn_woo_powerall_tenant_name',
            __('Tenant Name', 'dsn-woo-powerall'),
            array($this, 'render_tenant_name_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_token',
            __('API Token', 'dsn-woo-powerall'),
            array($this, 'render_token_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_sync_frequency',
            __('Sync Frequency', 'dsn-woo-powerall'),
            array($this, 'render_sync_frequency_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_use_sale_price',
            __('Use Powerall Sale Price', 'dsn-woo-powerall'),
            array($this, 'render_use_sale_price_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Settings Saved', 'dsn-woo-powerall'),
                'updated'
            );
        }

        // Handle log clearing
        if (isset($_POST['clear_log']) && check_admin_referer('dsn_woo_powerall_clear_log')) {
            $this->logger->clear_log();
            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Log cleared successfully.', 'dsn-woo-powerall'),
                'updated'
            );
        }

        // Handle manual product sync
        if (isset($_POST['dsn_woo_powerall_manual_sync']) && check_admin_referer('dsn_woo_powerall_manual_sync', 'dsn_woo_powerall_sync_nonce')) {
            require_once __DIR__ . '/class-product-sync.php';
            require_once __DIR__ . '/class-api-handler.php';
            $api_handler = new API_Handler();
            $product_sync = new Product_Sync($api_handler);
            $result = $product_sync->sync_products();
            if (is_wp_error($result)) {
                $this->logger->error('Manual product sync failed: ' . $result->get_error_message());
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    __('Manual product sync failed: ', 'dsn-woo-powerall') . $result->get_error_message(),
                    'error'
                );
            } else {
                $this->logger->info('Manual product sync completed successfully.');
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    __('Manual product sync completed successfully.', 'dsn-woo-powerall'),
                    'updated'
                );
            }
        }

        // Handle cleanup execution
        if (isset($_POST['dsn_woo_powerall_cleanup']) && check_admin_referer('dsn_woo_powerall_cleanup_sale_prices', 'dsn_woo_powerall_cleanup_nonce')) {
            require_once __DIR__ . '/class-product-sync.php';
            require_once __DIR__ . '/class-api-handler.php';
            $api_handler = new API_Handler();
            $product_sync = new Product_Sync($api_handler);
            $result = $product_sync->cleanup_remove_equal_sale_prices(100);
            if (is_array($result)) {
                $this->logger->info('Cleanup completed. Processed: ' . $result['processed'] . ' Updated: ' . $result['updated']);
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    sprintf(__('Cleanup completed. Processed: %d Updated: %d', 'dsn-woo-powerall'), $result['processed'], $result['updated']),
                    'updated'
                );
            } else {
                $this->logger->error('Cleanup failed or returned unexpected result.');
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    __('Cleanup failed. Check logs for details.', 'dsn-woo-powerall'),
                    'error'
                );
            }
        }

        // Handle test execution
        if (isset($_POST['run_tests']) && check_admin_referer('dsn_woo_powerall_run_tests')) {
            $test = new Test();
            $test->run_all_tests();
            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Tests completed. Check the log for results.', 'dsn-woo-powerall'),
                'updated'
            );
        }

        settings_errors('dsn_woo_powerall_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('dsn_woo_powerall_settings');
                do_settings_sections('dsn-woo-powerall');
                submit_button(__('Save Settings', 'dsn-woo-powerall'));
                ?>
            </form>

            <hr>

            <h2><?php _e('Manual Sync', 'dsn-woo-powerall'); ?></h2>
            <p><?php _e('Click the button below to manually sync products with Powerall CRM.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_manual_sync', 'dsn_woo_powerall_sync_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_manual_sync" class="button button-primary" value="<?php esc_attr_e('Sync Products Now', 'dsn-woo-powerall'); ?>">
            </form>

            <hr>

            <h2><?php _e('Test Connection', 'dsn-woo-powerall'); ?></h2>
            <p><?php _e('Run tests to verify the connection with Powerall CRM.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_run_tests'); ?>
                <input type="submit" name="run_tests" class="button button-secondary" value="<?php esc_attr_e('Run Tests', 'dsn-woo-powerall'); ?>">
            </form>

            <hr>

            <h2><?php _e('Cleanup', 'dsn-woo-powerall'); ?></h2>
            <p><?php _e('Remove sale price when it is equal to the regular price across all products.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_cleanup_sale_prices', 'dsn_woo_powerall_cleanup_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_cleanup" class="button button-secondary" value="<?php esc_attr_e('Cleanup sale prices', 'dsn-woo-powerall'); ?>">
            </form>

            <h2><?php _e('Log Viewer', 'dsn-woo-powerall'); ?></h2>
            <p><?php _e('View the latest API requests and responses.', 'dsn-woo-powerall'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_clear_log'); ?>
                <input type="submit" name="clear_log" class="button" value="<?php esc_attr_e('Clear Log', 'dsn-woo-powerall'); ?>">
            </form>

            <div class="log-viewer" style="margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 500px; overflow-y: auto;">
                    <?php
                    $log_file = $this->logger->get_log_file_path();
                    if (file_exists($log_file)) {
                        echo esc_html(file_get_contents($log_file));
                    } else {
                        _e('No log entries found.', 'dsn-woo-powerall');
                    }
                    ?>
                </pre>
            </div>
        </div>
        <?php
    }

    /**
     * Render section info
     */
    public function render_section_info() {
        echo '<p>' . esc_html__('Configure your Powerall CRM API settings below. You can find these settings in your Powerall CRM account.', 'dsn-woo-powerall') . '</p>';
    }

    /**
     * Render tenant name field
     */
    public function render_tenant_name_field() {
        $tenant_name = get_option('dsn_woo_powerall_tenant_name');
        ?>
        <input type="text" 
               id="dsn_woo_powerall_tenant_name" 
               name="dsn_woo_powerall_tenant_name" 
               value="<?php echo esc_attr($tenant_name); ?>" 
               class="regular-text">
        <p class="description">
            <?php _e('Enter your Powerall CRM tenant name. This is your organization identifier in Powerall CRM.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render token field
     */
    public function render_token_field() {
        $token = get_option('dsn_woo_powerall_token');
        ?>
        <input type="password" 
               id="dsn_woo_powerall_token" 
               name="dsn_woo_powerall_token" 
               value="<?php echo esc_attr($token); ?>" 
               class="regular-text">
        <p class="description">
            <?php _e('Enter your Powerall CRM API token. You can generate this in your Powerall CRM account settings.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render sync frequency field
     */
    public function render_sync_frequency_field() {
        $sync_frequency = get_option('dsn_woo_powerall_sync_frequency', 'daily');
        ?>
        <select id="dsn_woo_powerall_sync_frequency" name="dsn_woo_powerall_sync_frequency">
            <option value="hourly" <?php selected($sync_frequency, 'hourly'); ?>><?php _e('Hourly', 'dsn-woo-powerall'); ?></option>
            <option value="twicedaily" <?php selected($sync_frequency, 'twicedaily'); ?>><?php _e('Twice Daily', 'dsn-woo-powerall'); ?></option>
            <option value="daily" <?php selected($sync_frequency, 'daily'); ?>><?php _e('Daily', 'dsn-woo-powerall'); ?></option>
        </select>
        <p class="description">
            <?php _e('How often should the plugin sync products with Powerall CRM?', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render use sale price field
     */
    public function render_use_sale_price_field() {
        $use_sale_price = get_option('dsn_woo_powerall_use_sale_price', '1'); // Default to true (1)
        ?>
        <input type="checkbox" 
               id="dsn_woo_powerall_use_sale_price" 
               name="dsn_woo_powerall_use_sale_price" 
               value="1" 
               <?php checked(1, $use_sale_price, true); ?>>
        <p class="description">
            <?php _e('Enable to use the sale price from Powerall CRM.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }
} 