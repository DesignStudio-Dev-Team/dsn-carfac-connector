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
     * Add admin menu.
     *
     * @return void
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
     * Register settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_tenant_name');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_token');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_sync_frequency');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_use_sale_price');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_stock_tracking_mode', array(
            'sanitize_callback' => array($this, 'sanitize_stock_tracking_mode'),
            'default' => Stock_Helper::DEFAULT_MODE,
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_frontend_stock_enabled');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_frontend_stock_display', array(
            'sanitize_callback' => array($this, 'sanitize_frontend_stock_display'),
            'default' => 'combined',
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_frontend_stock_position', array(
            'sanitize_callback' => array($this, 'sanitize_frontend_stock_position'),
            'default' => 'disabled',
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_sync_batch_size', array(
            'sanitize_callback' => array($this, 'sanitize_sync_batch_size'),
            'default' => Product_Sync::DEFAULT_BATCH_SIZE,
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_sync_batch_delay', array(
            'sanitize_callback' => array($this, 'sanitize_sync_batch_delay'),
            'default' => Product_Sync::DEFAULT_BATCH_DELAY,
        ));

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

        add_settings_field(
            'dsn_woo_powerall_stock_tracking_mode',
            __('Stock Tracking Mode', 'dsn-woo-powerall'),
            array($this, 'render_stock_tracking_mode_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_frontend_stock_enabled',
            __('Show Stock on Frontend', 'dsn-woo-powerall'),
            array($this, 'render_frontend_stock_enabled_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_frontend_stock_display',
            __('Frontend Stock Display Mode', 'dsn-woo-powerall'),
            array($this, 'render_frontend_stock_display_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_frontend_stock_position',
            __('Auto Display Position', 'dsn-woo-powerall'),
            array($this, 'render_frontend_stock_position_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_sync_batch_size',
            __('Sync Batch Size', 'dsn-woo-powerall'),
            array($this, 'render_sync_batch_size_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_sync_batch_delay',
            __('Delay Between Batches', 'dsn-woo-powerall'),
            array($this, 'render_sync_batch_delay_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (
            isset($_POST['dsn_woo_powerall_manual_sync']) &&
            check_admin_referer('dsn_woo_powerall_manual_sync', 'dsn_woo_powerall_sync_nonce')
        ) {
            wp_safe_redirect($this->get_sync_progress_url(true));
            exit;
        }

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';

        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Settings Saved', 'dsn-woo-powerall'),
                'updated'
            );
        }

        if (isset($_POST['clear_log']) && check_admin_referer('dsn_woo_powerall_clear_log')) {
            $this->logger->clear_log();
            delete_transient('dsn_github_latest_release_DesignStudio-Dev-Team_dsn-woo-powerall-connector');

            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Log cleared and update cache reset successfully.', 'dsn-woo-powerall'),
                'updated'
            );
        }

        if (
            isset($_POST['dsn_woo_powerall_cleanup']) &&
            check_admin_referer('dsn_woo_powerall_cleanup_sale_prices', 'dsn_woo_powerall_cleanup_nonce')
        ) {
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

        if ($view === 'sync-progress') {
            $this->render_sync_progress_page();
            return;
        }

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

            <h2><?php esc_html_e('Manual Sync', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Open the progress screen to start a manual product sync. The sync runs in smaller batches so you can watch progress and reduce timeout risk on slower hosting.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_manual_sync', 'dsn_woo_powerall_sync_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_manual_sync" class="button button-primary" value="<?php esc_attr_e('Open Product Sync Progress', 'dsn-woo-powerall'); ?>">
                <a href="<?php echo esc_url($this->get_sync_progress_url(false)); ?>" class="button"><?php esc_html_e('View Current Progress', 'dsn-woo-powerall'); ?></a>
            </form>

            <hr>

            <h2><?php esc_html_e('Test Connection', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Run tests to verify the connection with Powerall CRM.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_run_tests'); ?>
                <input type="submit" name="run_tests" class="button button-secondary" value="<?php esc_attr_e('Run Tests', 'dsn-woo-powerall'); ?>">
            </form>

            <hr>

            <h2><?php esc_html_e('Cleanup', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Remove sale price when it is equal to the regular price across all products.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_cleanup_sale_prices', 'dsn_woo_powerall_cleanup_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_cleanup" class="button button-secondary" value="<?php esc_attr_e('Cleanup sale prices', 'dsn-woo-powerall'); ?>">
            </form>

            <h2><?php esc_html_e('Log Viewer', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('View the latest API requests and responses.', 'dsn-woo-powerall'); ?></p>

            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_clear_log'); ?>
                <input type="submit" name="clear_log" class="button" value="<?php esc_attr_e('Clear Log', 'dsn-woo-powerall'); ?>">
            </form>

            <div class="log-viewer" style="margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 500px; overflow-y: auto;"><?php echo esc_html($this->get_log_contents()); ?></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Render section info.
     *
     * @return void
     */
    public function render_section_info() {
        echo '<p>' . esc_html__('Configure your Powerall CRM API settings below. You can find these settings in your Powerall CRM account.', 'dsn-woo-powerall') . '</p>';
    }

    /**
     * Render tenant name field.
     *
     * @return void
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
            <?php esc_html_e('Enter your Powerall CRM tenant name. This is your organization identifier in Powerall CRM.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render token field.
     *
     * @return void
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
            <?php esc_html_e('Enter your Powerall CRM API token. You can generate this in your Powerall CRM account settings.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render sync frequency field.
     *
     * @return void
     */
    public function render_sync_frequency_field() {
        $sync_frequency = get_option('dsn_woo_powerall_sync_frequency', 'daily');
        ?>
        <select id="dsn_woo_powerall_sync_frequency" name="dsn_woo_powerall_sync_frequency">
            <option value="hourly" <?php selected($sync_frequency, 'hourly'); ?>><?php esc_html_e('Hourly', 'dsn-woo-powerall'); ?></option>
            <option value="twicedaily" <?php selected($sync_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'dsn-woo-powerall'); ?></option>
            <option value="daily" <?php selected($sync_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'dsn-woo-powerall'); ?></option>
        </select>
        <p class="description">
            <?php esc_html_e('How often should the plugin sync products with Powerall CRM?', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render use sale price field.
     *
     * @return void
     */
    public function render_use_sale_price_field() {
        $use_sale_price = get_option('dsn_woo_powerall_use_sale_price', '1');
        ?>
        <input type="checkbox"
               id="dsn_woo_powerall_use_sale_price"
               name="dsn_woo_powerall_use_sale_price"
               value="1"
               <?php checked(1, $use_sale_price, true); ?>>
        <p class="description">
            <?php esc_html_e('Enable to use the sale price from Powerall CRM.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render stock tracking mode field.
     *
     * @return void
     */
    public function render_stock_tracking_mode_field() {
        $stock_mode = Stock_Helper::get_selected_mode();
        ?>
        <select id="dsn_woo_powerall_stock_tracking_mode" name="dsn_woo_powerall_stock_tracking_mode">
            <?php foreach (Stock_Helper::get_available_modes() as $mode_key => $label) : ?>
                <option value="<?php echo esc_attr($mode_key); ?>" <?php selected($stock_mode, $mode_key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Choose which stock type from Powerall to use for WooCommerce inventory. The default behavior sums FreeStock across every Powerall location.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render sync batch size field.
     *
     * @return void
     */
    public function render_sync_batch_size_field() {
        $batch_size = Product_Sync::get_batch_size();
        ?>
        <input type="number"
               id="dsn_woo_powerall_sync_batch_size"
               name="dsn_woo_powerall_sync_batch_size"
               value="<?php echo esc_attr($batch_size); ?>"
               class="small-text"
               min="1"
               max="200"
               step="1">
        <p class="description">
            <?php esc_html_e('How many Powerall products should be processed per request. Lower this if your hosting is timing out.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render sync batch delay field.
     *
     * @return void
     */
    public function render_sync_batch_delay_field() {
        $delay_seconds = Product_Sync::get_batch_delay_seconds();
        ?>
        <input type="number"
               id="dsn_woo_powerall_sync_batch_delay"
               name="dsn_woo_powerall_sync_batch_delay"
               value="<?php echo esc_attr($delay_seconds); ?>"
               class="small-text"
               min="0"
               max="30"
               step="1">
        <p class="description">
            <?php esc_html_e('Pause this many seconds between batches. This is used for both manual progress syncs and the standard sync loop.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render frontend stock enabled toggle field.
     *
     * @return void
     */
    public function render_frontend_stock_enabled_field() {
        $enabled = get_option('dsn_woo_powerall_frontend_stock_enabled', '');
        ?>
        <input type="checkbox"
               id="dsn_woo_powerall_frontend_stock_enabled"
               name="dsn_woo_powerall_frontend_stock_enabled"
               value="1"
               <?php checked(1, $enabled); ?>>
        <p class="description">
            <?php esc_html_e('Enable the [dsn_powerall_stock] shortcode and load its assets on the frontend.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render frontend stock display mode field.
     *
     * @return void
     */
    public function render_frontend_stock_display_field() {
        $mode = get_option('dsn_woo_powerall_frontend_stock_display', 'combined');
        ?>
        <select id="dsn_woo_powerall_frontend_stock_display" name="dsn_woo_powerall_frontend_stock_display">
            <option value="combined" <?php selected($mode, 'combined'); ?>>
                <?php esc_html_e('Combined (single total)', 'dsn-woo-powerall'); ?>
            </option>
            <option value="per_warehouse" <?php selected($mode, 'per_warehouse'); ?>>
                <?php esc_html_e('Per warehouse (list with location names)', 'dsn-woo-powerall'); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e('Combined shows the total stock number. Per warehouse lists each Powerall location individually — if there are more than 3 locations a "Show more" link will open a modal with all of them.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render auto display position field.
     *
     * @return void
     */
    public function render_frontend_stock_position_field() {
        $position = get_option('dsn_woo_powerall_frontend_stock_position', 'disabled');
        $options  = array(
            'disabled'           => __('Disabled (shortcode only)', 'dsn-woo-powerall'),
            'below_title'        => __('Below the product title', 'dsn-woo-powerall'),
            'after_price'        => __('After the price', 'dsn-woo-powerall'),
            'before_add_to_cart' => __('Before the Add to Cart button', 'dsn-woo-powerall'),
        );
        ?>
        <select id="dsn_woo_powerall_frontend_stock_position" name="dsn_woo_powerall_frontend_stock_position">
            <?php foreach ($options as $value => $label) : ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($position, $value); ?>>
                <?php echo esc_html($label); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Automatically inject the stock widget into single product pages at the chosen position. Select "Disabled" to rely solely on the [dsn_powerall_stock] shortcode.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Sanitize frontend stock display mode.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_frontend_stock_display($value) {
        return in_array($value, array('combined', 'per_warehouse'), true) ? $value : 'combined';
    }

    /**
     * Sanitize auto display position.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_frontend_stock_position($value) {
        $valid = array('disabled', 'below_title', 'after_price', 'before_add_to_cart');
        return in_array($value, $valid, true) ? $value : 'disabled';
    }

    /**
     * Sanitize stock tracking mode.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_stock_tracking_mode($value) {
        return Stock_Helper::normalize_mode($value);
    }

    /**
     * Sanitize sync batch size.
     *
     * @param mixed $value
     * @return int
     */
    public function sanitize_sync_batch_size($value) {
        $value = absint($value);

        return min(200, max(1, $value ?: Product_Sync::DEFAULT_BATCH_SIZE));
    }

    /**
     * Sanitize sync batch delay.
     *
     * @param mixed $value
     * @return int
     */
    public function sanitize_sync_batch_delay($value) {
        $value = absint($value);

        return min(30, max(0, $value));
    }

    /**
     * Render the dedicated sync progress page.
     *
     * @return void
     */
    private function render_sync_progress_page() {
        $auto_start = isset($_GET['start']) ? '1' : '0';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Product Sync Progress', 'dsn-woo-powerall'); ?></h1>
            <p><?php echo esc_html(sprintf(__('Products are processed in batches of %1$d with a %2$d second pause between batches to reduce timeout risk.', 'dsn-woo-powerall'), Product_Sync::get_batch_size(), Product_Sync::get_batch_delay_seconds())); ?></p>

            <div id="dsn-woo-powerall-sync-app" data-auto-start="<?php echo esc_attr($auto_start); ?>" style="max-width: 920px;">
                <div style="background:#fff; border:1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding:24px;">
                    <p>
                        <button type="button" class="button button-primary" id="dsn-woo-powerall-start-sync"><?php esc_html_e('Start Sync', 'dsn-woo-powerall'); ?></button>
                        <a href="<?php echo esc_url($this->get_settings_page_url()); ?>" class="button"><?php esc_html_e('Back to Settings', 'dsn-woo-powerall'); ?></a>
                    </p>

                    <div style="background:#f0f0f1; width:100%; height:18px; border-radius:4px; overflow:hidden;">
                        <div class="dsn-sync-progress-bar" style="width:0%; height:100%; background:#2271b1;"></div>
                    </div>

                    <p class="dsn-sync-status" style="margin:12px 0 0;"><?php esc_html_e('Idle', 'dsn-woo-powerall'); ?></p>
                    <p class="dsn-sync-last-message" style="margin:8px 0 0; color:#50575e;"><?php esc_html_e('Manual sync has not been started yet.', 'dsn-woo-powerall'); ?></p>

                    <table class="widefat striped" style="margin-top:20px;">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Run ID', 'dsn-woo-powerall'); ?></strong></td>
                                <td class="dsn-sync-run-id">-</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Batch', 'dsn-woo-powerall'); ?></strong></td>
                                <td><span class="dsn-sync-current-batch">0</span> / <span class="dsn-sync-total-batches">0</span></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Products', 'dsn-woo-powerall'); ?></strong></td>
                                <td>
                                    <span class="dsn-sync-processed">0</span> / <span class="dsn-sync-total">0</span>
                                    <?php esc_html_e('processed', 'dsn-woo-powerall'); ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Results', 'dsn-woo-powerall'); ?></strong></td>
                                <td>
                                    <?php esc_html_e('Updated:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-updated">0</span>
                                    |
                                    <?php esc_html_e('Unchanged:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-synced">0</span>
                                    |
                                    <?php esc_html_e('Skipped:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-skipped">0</span>
                                    |
                                    <?php esc_html_e('Failed:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-failed">0</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Batch Size / Delay', 'dsn-woo-powerall'); ?></strong></td>
                                <td><span class="dsn-sync-batch-size"><?php echo esc_html(Product_Sync::get_batch_size()); ?></span> / <span class="dsn-sync-delay"><?php echo esc_html(Product_Sync::get_batch_delay_seconds()); ?></span>s</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Last Product', 'dsn-woo-powerall'); ?></strong></td>
                                <td class="dsn-sync-last-product">-</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Started / Completed', 'dsn-woo-powerall'); ?></strong></td>
                                <td><span class="dsn-sync-started-at">-</span> / <span class="dsn-sync-completed-at">-</span></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="dsn-sync-errors" style="display:none; margin-top:20px;">
                        <h2 style="margin:0 0 8px;"><?php esc_html_e('Recent Errors', 'dsn-woo-powerall'); ?></h2>
                        <ul style="margin:0; padding-left:18px;"></ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get the contents of the log file.
     *
     * @return string
     */
    private function get_log_contents() {
        $log_file = $this->logger->get_log_file_path();

        if (!file_exists($log_file)) {
            return __('No log entries found.', 'dsn-woo-powerall');
        }

        $contents = file_get_contents($log_file);

        return $contents === false ? __('Unable to read the log file.', 'dsn-woo-powerall') : $contents;
    }

    /**
     * Get the main settings page URL.
     *
     * @return string
     */
    private function get_settings_page_url() {
        return admin_url('admin.php?page=dsn-woo-powerall');
    }

    /**
     * Get the sync progress page URL.
     *
     * @param bool $auto_start
     * @return string
     */
    private function get_sync_progress_url($auto_start = false) {
        $args = array(
            'page' => 'dsn-woo-powerall',
            'view' => 'sync-progress',
        );

        if ($auto_start) {
            $args['start'] = 1;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }
}
