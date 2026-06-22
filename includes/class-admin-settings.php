<?php
namespace DSNCarfac;

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
        add_action('add_meta_boxes', array($this, 'add_product_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        // AJAX handlers for background manual sync
        add_action('wp_ajax_dsn_carfac_sync_fetch', array($this, 'ajax_sync_fetch'));
        add_action('wp_ajax_dsn_carfac_sync_cleanup', array($this, 'ajax_sync_cleanup'));
        add_action('wp_ajax_dsn_carfac_sync_log_tail', array($this, 'ajax_sync_log_tail'));
        add_action('wp_ajax_dsn_carfac_sync_progress', array($this, 'ajax_sync_progress'));
        // Loopback worker — callable without auth cookies (token-validated)
        // so the background sync keeps ticking even when WP-Cron is disabled.
        add_action('wp_ajax_dsn_carfac_sync_worker', array($this, 'ajax_sync_worker'));
        add_action('wp_ajax_nopriv_dsn_carfac_sync_worker', array($this, 'ajax_sync_worker'));
        $this->logger = new Logger();
    }

    /**
     * Enqueue admin scripts on the plugin settings page
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_dsn-carfac') {
            return;
        }
        wp_enqueue_script(
            'dsn-carfac-admin',
            DSN_CARFAC_PLUGIN_URL . 'assets/js/admin-sync.js',
            array('jquery'),
            DSN_CARFAC_VERSION . '-' . filemtime(DSN_CARFAC_PLUGIN_DIR . 'assets/js/admin-sync.js'),
            true
        );
        wp_localize_script('dsn-carfac-admin', 'dsnCarfacSync', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('dsn_carfac_sync_nonce'),
            'logOffset' => $this->get_log_file_size(),
            'pollIntervalMs' => 4000,
            'i18n'      => array(
                'starting'   => __('Queuing background sync...', 'dsn-carfac'),
                'running'    => __('Background sync running...', 'dsn-carfac'),
                'complete'   => __('Sync complete!', 'dsn-carfac'),
                'failed'     => __('Sync failed: %s', 'dsn-carfac'),
                'error'      => __('An error occurred. Please try again.', 'dsn-carfac'),
                'stopping'   => __('Stopping background sync...', 'dsn-carfac'),
                'resumed'    => __('Resumed in-progress background sync.', 'dsn-carfac'),
            ),
        ));
    }

    /**
     * AJAX: Kick off a background (WP-Cron driven) sync. Returns immediately
     * with the initial run state — the browser only polls for progress and
     * never has to wait for Carfac batches.
     */
    public function ajax_sync_fetch() {
        check_ajax_referer('dsn_carfac_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'dsn-carfac')));
        }

        $api_handler = new API_Handler();
        $product_sync = new Product_Sync($api_handler);
        $state = $product_sync->start_background_sync();

        wp_send_json_success($state);
    }

    /**
     * AJAX (loopback): Advance the background sync by one batch.
     *
     * Validated by the worker token in the run state, so the cron worker can
     * call this with no cookies/nonce — and also so a logged-in admin can
     * trigger a manual nudge if cron is disabled.
     */
    public function ajax_sync_worker() {
        $api_handler  = new API_Handler();
        $product_sync = new Product_Sync($api_handler);

        $token = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
        $authorized = $product_sync->verify_worker_token($token);
        if (!$authorized && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized worker call', 'dsn-carfac')), 403);
        }

        $product_sync->run_bg_batch_event();
        wp_send_json_success(array('ticked' => true));
    }

    /**
     * AJAX: Stop the running background sync.
     */
    public function ajax_sync_cleanup() {
        check_ajax_referer('dsn_carfac_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'dsn-carfac')));
        }

        $api_handler = new API_Handler();
        $product_sync = new Product_Sync($api_handler);
        $product_sync->request_bg_stop();

        wp_send_json_success($product_sync->get_bg_state());
    }

    /**
     * AJAX: Return current background sync state for the progress poll.
     * Touching the state via is_bg_sync_active() also self-heals stalled runs.
     */
    public function ajax_sync_progress() {
        check_ajax_referer('dsn_carfac_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'dsn-carfac')));
        }

        $api_handler = new API_Handler();
        $product_sync = new Product_Sync($api_handler);
        $product_sync->is_bg_sync_active();
        wp_send_json_success($product_sync->get_bg_state());
    }

    /**
     * AJAX: Return new log lines written since the frontend's last known offset.
     */
    public function ajax_sync_log_tail() {
        check_ajax_referer('dsn_carfac_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'dsn-carfac')));
        }

        $log_file = $this->logger->get_log_file_path();
        if (!file_exists($log_file) || !is_readable($log_file)) {
            wp_send_json_success(array(
                'lines' => array(),
                'offset' => 0,
            ));
        }

        $file_size = filesize($log_file);
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : $file_size;

        if ($offset > $file_size) {
            $offset = 0;
        }

        $max_bytes = 65536;
        if (($file_size - $offset) > $max_bytes) {
            $offset = max(0, $file_size - $max_bytes);
        }

        $contents = '';
        if ($file_size > $offset) {
            $handle = fopen($log_file, 'rb');
            if ($handle) {
                fseek($handle, $offset);
                $contents = fread($handle, $file_size - $offset);
                fclose($handle);
            }
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $contents))));

        wp_send_json_success(array(
            'lines' => $lines,
            'offset' => $file_size,
        ));
    }

    private function get_log_file_size() {
        $log_file = $this->logger->get_log_file_path();
        return file_exists($log_file) ? (int) filesize($log_file) : 0;
    }


    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('DSN Carfac', 'dsn-carfac'),
            __('DSN Carfac', 'dsn-carfac'),
            'manage_options',
            'dsn-carfac',
            array($this, 'render_settings_page'),
            'dashicons-update',
            56
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_tenant_name');
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_token');
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_carfac_dealer_code');
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_carfac_username');
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_carfac_password');
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_sync_frequency');
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_use_sale_price', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($val) {
                return $val === '1' ? '1' : '0';
            },
        ]);
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_log_product_payloads');
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_sync_batch_size', [
            'type' => 'integer',
            'default' => 25,
            'sanitize_callback' => function($val) {
                $val = absint($val);
                if ($val <= 0) {
                    return 25;
                }
                return min($val, 75);
            },
        ]);
        register_setting('DSN_CARFAC_settings', 'DSN_CARFAC_delay_between_batches', [
            'type' => 'integer',
            'default' => 1,
            'sanitize_callback' => function($val) {
                $val = absint($val);
                return $val >= 0 ? $val : 1;
            },
        ]);

        add_settings_section(
            'DSN_CARFAC_main_section',
            __('API Settings', 'dsn-carfac'),
            array($this, 'render_section_info'),
            'dsn-carfac'
        );

        add_settings_field(
            'DSN_CARFAC_carfac_dealer_code',
            __('Carfac Dealer Code', 'dsn-carfac'),
            array($this, 'render_carfac_dealer_field'),
            'dsn-carfac',
            'DSN_CARFAC_main_section'
        );

        add_settings_field(
            'DSN_CARFAC_carfac_username',
            __('Carfac Username', 'dsn-carfac'),
            array($this, 'render_carfac_username_field'),
            'dsn-carfac',
            'DSN_CARFAC_main_section'
        );

        add_settings_field(
            'DSN_CARFAC_carfac_password',
            __('Carfac Password', 'dsn-carfac'),
            array($this, 'render_carfac_password_field'),
            'dsn-carfac',
            'DSN_CARFAC_main_section'
        );

        add_settings_field(
            'DSN_CARFAC_sync_frequency',
            __('Sync Frequency', 'dsn-carfac'),
            array($this, 'render_sync_frequency_field'),
            'dsn-carfac',
            'DSN_CARFAC_main_section'
        );

        add_settings_field(
            'DSN_CARFAC_use_sale_price',
            __('Use Carfac Temporary Sale Price', 'dsn-carfac'),
            array($this, 'render_use_sale_price_field'),
            'dsn-carfac',
            'DSN_CARFAC_main_section'
        );

        // Sync Settings section
        add_settings_section(
            'DSN_CARFAC_sync_section',
            __('Sync Settings', 'dsn-carfac'),
            array($this, 'render_sync_section_info'),
            'dsn-carfac'
        );

        add_settings_field(
            'DSN_CARFAC_log_product_payloads',
            __('Log Product Payloads', 'dsn-carfac'),
            array($this, 'render_log_product_payloads_field'),
            'dsn-carfac',
            'DSN_CARFAC_sync_section'
        );

        add_settings_field(
            'DSN_CARFAC_sync_batch_size',
            __('Sync Batch Size', 'dsn-carfac'),
            array($this, 'render_sync_batch_size_field'),
            'dsn-carfac',
            'DSN_CARFAC_sync_section'
        );

        add_settings_field(
            'DSN_CARFAC_delay_between_batches',
            __('Delay Between Batches', 'dsn-carfac'),
            array($this, 'render_delay_between_batches_field'),
            'dsn-carfac',
            'DSN_CARFAC_sync_section'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Determine active tab
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';

        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'DSN_CARFAC_messages',
                'DSN_CARFAC_message',
                __('Settings Saved', 'dsn-carfac'),
                'updated'
            );
        }

        // Handle log clearing
        if (isset($_POST['clear_log']) && check_admin_referer('DSN_CARFAC_clear_log')) {
            $this->logger->clear_log();
            add_settings_error(
                'DSN_CARFAC_messages',
                'DSN_CARFAC_message',
                __('Log cleared successfully.', 'dsn-carfac'),
                'updated'
            );
        }

        // Manual sync is now handled via AJAX (see ajax_sync_fetch / ajax_sync_batch)

        // Handle test execution
        if (isset($_POST['run_tests']) && check_admin_referer('DSN_CARFAC_run_tests')) {
            $test = new Test();
            $test->run_all_tests();
            add_settings_error(
                'DSN_CARFAC_messages',
                'DSN_CARFAC_message',
                __('Tests completed. Check the log for results.', 'dsn-carfac'),
                'updated'
            );
        }

        settings_errors('DSN_CARFAC_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=dsn-carfac&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>"><?php _e('Settings', 'dsn-carfac'); ?></a>
                <a href="?page=dsn-carfac&tab=tools" class="nav-tab <?php echo $active_tab == 'tools' ? 'nav-tab-active' : ''; ?>"><?php _e('Tools', 'dsn-carfac'); ?></a>
                <a href="?page=dsn-carfac&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>"><?php _e('Logs', 'dsn-carfac'); ?></a>
            </h2>

            <div class="tab-content" style="margin-top: 20px;">
                <?php if ($active_tab == 'settings'): ?>
                    <form action="options.php" method="post">
                        <?php
                        settings_fields('DSN_CARFAC_settings');
                        do_settings_sections('dsn-carfac');
                        submit_button(__('Save Settings', 'dsn-carfac'));
                        ?>
                    </form>
                <?php elseif ($active_tab == 'tools'): ?>
                    <h2><?php _e('Manual Sync', 'dsn-carfac'); ?></h2>
                    <p><?php _e('Starts a background WP-Cron sync that processes WooCommerce SKUs in small batches against Carfac Part/GetParts. You can close this page — the sync keeps running. Re-open the Tools tab to check progress.', 'dsn-carfac'); ?></p>

                    <div id="dsn-carfac-sync-wrapper">
                        <button type="button" id="dsn-carfac-sync-btn" class="button button-primary">
                            <?php esc_html_e('Sync Products Now', 'dsn-carfac'); ?>
                        </button>
                        <button type="button" id="dsn-carfac-stop-btn" class="button" style="display:none; margin-left: 8px;">
                            <?php esc_html_e('Stop Sync', 'dsn-carfac'); ?>
                        </button>

                        <div id="dsn-carfac-sync-progress" style="display:none; margin-top: 20px;">
                            <div id="dsn-sync-status" style="margin-bottom: 10px; font-weight: 600; font-size: 14px;"></div>

                            <div style="background: #e0e0e0; border-radius: 4px; height: 24px; width: 100%; max-width: 600px; overflow: hidden; position: relative;">
                                <div id="dsn-sync-bar" style="background: #0073aa; height: 100%; width: 0%; border-radius: 4px; transition: width 0.3s ease;"></div>
                                <span id="dsn-sync-percent" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 12px; font-weight: bold; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">0%</span>
                            </div>

                            <div id="dsn-sync-stats" style="margin-top: 12px; display: flex; gap: 20px; flex-wrap: wrap;">
                                <span><strong><?php _e('Updated:', 'dsn-carfac'); ?></strong> <span id="dsn-stat-updated">0</span></span>
                                <span><strong><?php _e('Skipped:', 'dsn-carfac'); ?></strong> <span id="dsn-stat-skipped">0</span></span>
                                <span><strong><?php _e('Errors:', 'dsn-carfac'); ?></strong> <span id="dsn-stat-errors">0</span></span>
                                <span><strong><?php _e('Total:', 'dsn-carfac'); ?></strong> <span id="dsn-stat-processed">0</span> / <span id="dsn-stat-total">0</span></span>
                            </div>

                            <div id="dsn-sync-log" style="margin-top: 12px; background: #23282d; color: #eee; padding: 12px 16px; border-radius: 4px; max-height: 250px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.6; display: none;"></div>
                        </div>
                    </div>

                    <hr>

                    <h2 id="sc_test_heading"><?php _e('Test Connection', 'dsn-carfac'); ?></h2>
                    <p id="sc_test_description"><?php _e('Run tests to verify the connection with Carfac.', 'dsn-carfac'); ?></p>
                    <form method="post" action="?page=dsn-carfac&tab=tools">
                        <?php wp_nonce_field('DSN_CARFAC_run_tests'); ?>
                        <input type="submit" name="run_tests" class="button button-secondary" value="<?php esc_attr_e('Run Tests', 'dsn-carfac'); ?>">
                    </form>
                <?php elseif ($active_tab == 'logs'): ?>
                    <h2><?php _e('Log Viewer', 'dsn-carfac'); ?></h2>
                    <p><?php _e('View the latest API requests and responses.', 'dsn-carfac'); ?></p>
                    
                    <form method="post" action="?page=dsn-carfac&tab=logs">
                        <?php wp_nonce_field('DSN_CARFAC_clear_log'); ?>
                        <input type="submit" name="clear_log" class="button" value="<?php esc_attr_e('Clear Log', 'dsn-carfac'); ?>">
                    </form>

                    <div class="log-viewer" style="margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 500px; overflow-y: auto;">
                            <?php
                            $log_file = $this->logger->get_log_file_path();
                            if (file_exists($log_file)) {
                                $log_contents = file_get_contents($log_file);
                                $entries = preg_split('/(?=^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[[A-Z]+\])/m', $log_contents, -1, PREG_SPLIT_NO_EMPTY);
                                echo esc_html(implode('', array_reverse($entries)));
                            } else {
                                _e('No log entries found.', 'dsn-carfac');
                            }
                            ?>
                        </pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }


    /**
     * Render section info
     */
    public function render_section_info() {
        echo '<p>' . esc_html__('Configure your Carfac API settings below.', 'dsn-carfac') . '</p>';
    }

    /**
     * Render sync section info
     */
    public function render_sync_section_info() {
        echo '<p>' . esc_html__('Configure product sync behavior.', 'dsn-carfac') . '</p>';
    }

    /**
     * Render Use Carfac Sale Price checkbox field.
     */
    public function render_use_sale_price_field() {
        $checked = get_option('DSN_CARFAC_use_sale_price', '1') === '0' ? '0' : '1';
        ?>
        <label for="DSN_CARFAC_use_sale_price">
            <input type="hidden" name="DSN_CARFAC_use_sale_price" value="0">
            <input type="checkbox"
                   id="DSN_CARFAC_use_sale_price"
                   name="DSN_CARFAC_use_sale_price"
                   value="1"
                   <?php checked($checked, '1'); ?>>
            <?php _e('Map Carfac Tijdelijke Prijs to the WooCommerce sale price', 'dsn-carfac'); ?>
        </label>
        <p class="description">
            <?php _e('When disabled, the sync clears WooCommerce sale prices and uses the VAT-inclusive regular price.', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    /**
     * Render full product payload logging checkbox field
     */
    public function render_log_product_payloads_field() {
        $checked = get_option('DSN_CARFAC_log_product_payloads', '0');
        ?>
        <label for="DSN_CARFAC_log_product_payloads">
            <input type="checkbox"
                   id="DSN_CARFAC_log_product_payloads"
                   name="DSN_CARFAC_log_product_payloads"
                   value="1"
                   <?php checked($checked, '1'); ?>>
            <?php _e('Log full Carfac payload for each synced product', 'dsn-carfac'); ?>
        </label>
        <p class="description">
            <?php _e('Useful for debugging field mappings. This can make the log very large.', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    /**
     * Render Sync Batch Size field
     */
    public function render_sync_batch_size_field() {
        $batch_size = get_option('DSN_CARFAC_sync_batch_size', 25);
        ?>
        <input type="number"
               id="DSN_CARFAC_sync_batch_size"
               name="DSN_CARFAC_sync_batch_size"
               value="<?php echo esc_attr($batch_size); ?>"
               class="small-text"
               min="1"
               max="75"
               step="1">
        <p class="description">
            <?php _e('Number of WooCommerce SKUs sent per Carfac Part/GetParts request. Smaller values avoid gateway timeouts. Default: 25 (max 75).', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    /**
     * Render Delay Between Batches field
     */
    public function render_delay_between_batches_field() {
        $delay = get_option('DSN_CARFAC_delay_between_batches', 1);
        ?>
        <input type="number"
               id="DSN_CARFAC_delay_between_batches"
               name="DSN_CARFAC_delay_between_batches"
               value="<?php echo esc_attr($delay); ?>"
               class="small-text"
               min="0"
               max="30"
               step="1">
        <span class="description"><?php _e('seconds', 'dsn-carfac'); ?></span>
        <p class="description">
            <?php _e('Pause between Carfac Part/GetParts requests. Set to 0 for back-to-back batches; bump up if you start seeing gateway timeouts. Default: 1 second.', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    /**
     * Render sync frequency field
     */
    public function render_sync_frequency_field() {
        $sync_frequency = get_option('DSN_CARFAC_sync_frequency', 'daily');
        ?>
        <select id="DSN_CARFAC_sync_frequency" name="DSN_CARFAC_sync_frequency">
            <option value="hourly" <?php selected($sync_frequency, 'hourly'); ?>><?php _e('Hourly', 'dsn-carfac'); ?></option>
            <option value="twicedaily" <?php selected($sync_frequency, 'twicedaily'); ?>><?php _e('Twice Daily', 'dsn-carfac'); ?></option>
            <option value="daily" <?php selected($sync_frequency, 'daily'); ?>><?php _e('Daily', 'dsn-carfac'); ?></option>
        </select>
        <p class="description">
            <?php _e('How often should the plugin sync products with Carfac?', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    /**
     * Render Carfac dealer code field
     */
    public function render_carfac_dealer_field() {
        $dealer = get_option('DSN_CARFAC_carfac_dealer_code', '');
        ?>
        <input type="text"
               id="DSN_CARFAC_carfac_dealer_code"
               name="DSN_CARFAC_carfac_dealer_code"
               value="<?php echo esc_attr($dealer); ?>"
               class="regular-text">
        <p class="description">
            <?php _e('Enter your Carfac DealerCode (numeric).', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    public function render_carfac_username_field() {
        $username = get_option('DSN_CARFAC_carfac_username', '');
        ?>
        <input type="text"
               id="DSN_CARFAC_carfac_username"
               name="DSN_CARFAC_carfac_username"
               value="<?php echo esc_attr($username); ?>"
               class="regular-text">
        <p class="description">
            <?php _e('Carfac username (used to log in and retrieve JWT).', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    public function render_carfac_password_field() {
        $password = get_option('DSN_CARFAC_carfac_password', '');
        ?>
        <input type="password"
               id="DSN_CARFAC_carfac_password"
               name="DSN_CARFAC_carfac_password"
               value="<?php echo esc_attr($password); ?>"
               class="regular-text">
        <p class="description">
            <?php _e('Carfac password (used to log in and retrieve JWT).', 'dsn-carfac'); ?>
        </p>
        <?php
    }

    /**
     * Add product meta box
     */
    public function add_product_meta_box() {
        add_meta_box(
            'dsn_carfac_sync_info',
            __('DSN Carfac Sync Info', 'dsn-carfac'),
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render product meta box
     *
     * @param WP_Post $post
     */
    public function render_product_meta_box($post) {
        $last_update = get_post_meta($post->ID, '_dsn_carfac_last_update', true);
        $carfac_id = get_post_meta($post->ID, '_carfac_product_id', true);
        $carfac_part_id = get_post_meta($post->ID, '_carfac_part_id', true);
        $carfac_part_name = get_post_meta($post->ID, '_carfac_part_name', true);
        $carfac_product_code = get_post_meta($post->ID, '_carfac_product_code', true);
        $carfac_source = get_post_meta($post->ID, '_carfac_source', true);
        $last_sync = get_post_meta($post->ID, '_dsn_carfac_last_sync', true);
        $stock_per_location = get_post_meta($post->ID, '_carfac_stock_per_location', true);

        if ($carfac_id) {
            echo '<p><strong>' . __('Carfac ID:', 'dsn-carfac') . '</strong> ' . esc_html($carfac_id) . '</p>';
        }
        if ($carfac_part_id) {
            echo '<p><strong>' . __('Part ID:', 'dsn-carfac') . '</strong> ' . esc_html($carfac_part_id) . '</p>';
        }
        if ($carfac_part_name) {
            echo '<p><strong>' . __('PartName:', 'dsn-carfac') . '</strong> ' . esc_html($carfac_part_name) . '</p>';
        }
        if ($carfac_product_code) {
            echo '<p><strong>' . __('ProductCode:', 'dsn-carfac') . '</strong> ' . esc_html($carfac_product_code) . '</p>';
        }
        if ($carfac_source) {
            echo '<p><strong>' . __('Source:', 'dsn-carfac') . '</strong> ' . esc_html($carfac_source) . '</p>';
        }
        if ($last_sync) {
            echo '<p><strong>' . __('Last Sync:', 'dsn-carfac') . '</strong><br>' . esc_html($last_sync) . '</p>';
        }

        // Display per-location stock breakdown
        if (!empty($stock_per_location) && is_array($stock_per_location)) {
            echo '<strong>' . __('Stock by Location:', 'dsn-carfac') . '</strong>';
            echo '<table style="width:100%; margin-top:5px; border-collapse:collapse;">';
            echo '<thead><tr style="text-align:left; border-bottom:1px solid #ddd;">';
            echo '<th style="padding:4px 6px;">' . __('Location', 'dsn-carfac') . '</th>';
            echo '<th style="padding:4px 6px; text-align:right;">' . __('Qty', 'dsn-carfac') . '</th>';
            echo '</tr></thead><tbody>';
            $total = 0;
            foreach ($stock_per_location as $loc) {
                $name = esc_html($loc['warehouse_name'] ?? 'Unknown');
                $qty = (int) ($loc['quantity'] ?? 0);
                $total += $qty;
                echo '<tr style="border-bottom:1px solid #f0f0f0;">';
                echo '<td style="padding:3px 6px;">' . $name . '</td>';
                echo '<td style="padding:3px 6px; text-align:right;">' . $qty . '</td>';
                echo '</tr>';
            }
            echo '<tr style="font-weight:bold; border-top:2px solid #ddd;">';
            echo '<td style="padding:3px 6px;">' . __('Total', 'dsn-carfac') . '</td>';
            echo '<td style="padding:3px 6px; text-align:right;">' . $total . '</td>';
            echo '</tr>';
            echo '</tbody></table>';
        }

        if ($last_update) {
            echo '<p style="margin-top:10px;"><strong>' . __('Last Update:', 'dsn-carfac') . '</strong><br>' . esc_html($last_update['date']) . '</p>';
            if (!empty($last_update['changes'])) {
                echo '<strong>' . __('Changes:', 'dsn-carfac') . '</strong>';
                echo '<ul style="margin-top: 5px; padding-left: 15px; list-style-type: disc;">';
                foreach ($last_update['changes'] as $change) {
                    echo '<li>' . wp_kses_post($change) . '</li>';
                }
                echo '</ul>';
            }
        } else {
            echo '<p>' . __('No sync data available yet.', 'dsn-carfac') . '</p>';
        }
    }
}

 
