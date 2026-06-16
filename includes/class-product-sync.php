<?php
namespace DSNCarfac;

class Product_Sync {
    const CARFAC_VAT_RATE = 0.21;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;
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
        $this->logger = new \DSNCarfac\Logger();
    }

    /**
     * Sync products from Carfac to WooCommerce
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function sync_products() {
        return $this->start_scheduled_sync();
    }

    public function prepare_manual_sync() {
        delete_transient('dsn_carfac_sync_stop_requested');
        $this->clear_cached_products();
        $this->set_manual_progress([
            'total' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'current' => '',
            'status' => 'fetching',
        ]);
        $this->logger->info('Manual Sync: Prepared new run; cleared stop flag and old cached products.');
    }

    /**
     * Collect Woo product → Carfac article_id pairs and cache them so the
     * batch loop can fetch matching Carfac parts in small chunks via
     * Part/GetParts partNameList (where partNameList contains article_ids).
     *
     * @return int Total pair count cached.
     */
    public function prepare_sku_sync() {
        delete_transient('dsn_carfac_sync_stop_requested');
        $this->delete_cached_skus();

        $pairs = $this->get_woocommerce_article_pairs();
        $this->set_cached_pairs($pairs);

        $total = count($pairs);
        $this->set_manual_progress([
            'total' => $total,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'current' => '',
            'status' => $total > 0 ? 'ready' : 'empty',
        ]);

        $this->logger->info(sprintf(
            'Manual Sync: Cached %d Woo→Carfac article_id pairs from dss_syndified meta.',
            $total
        ));

        return $total;
    }

    private function get_cached_pairs() {
        $pairs = get_option('dsn_carfac_sync_pairs', null);
        if (is_array($pairs)) {
            return $pairs;
        }

        return [];
    }

    private function set_cached_pairs(array $pairs) {
        update_option('dsn_carfac_sync_pairs', $pairs, false);
    }

    private function delete_cached_skus() {
        // New pair cache.
        delete_option('dsn_carfac_sync_pairs');
        // Legacy SKU-list caches from earlier versions.
        delete_option('dsn_carfac_sync_skus');
        delete_transient('dsn_carfac_sync_skus');
    }

    /* ------------------------------------------------------------------ */
    /* Background sync (WP-Cron driven, browser-independent)              */
    /* ------------------------------------------------------------------ */

    const BG_CRON_HOOK = 'dsn_carfac_run_bg_batch';
    const BG_STATE_OPTION = 'dsn_carfac_bg_run_state';
    const BG_LOCK_OPTION = 'dsn_carfac_bg_worker_lock';
    const BG_LOCK_TTL = 300; // seconds — stale lock auto-recovers after this.

    /**
     * Default state shape for the background sync. Centralised so reads
     * always return every key the JS expects.
     */
    private function default_bg_state() {
        return [
            'running' => false,
            'started_at' => 0,
            'finished_at' => 0,
            'last_batch_at' => 0,
            'offset' => 0,
            'total' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'current' => '',
            'message' => '',
            'stop_requested' => false,
            'error' => '',
            'worker_token' => '',
        ];
    }

    public function get_bg_state() {
        $state = get_option(self::BG_STATE_OPTION, null);
        if (!is_array($state)) {
            $state = [];
        }

        return array_merge($this->default_bg_state(), $state);
    }

    private function set_bg_state(array $changes) {
        $state = array_merge($this->get_bg_state(), $changes);
        update_option(self::BG_STATE_OPTION, $state, false);
        return $state;
    }

    public function request_bg_stop() {
        $this->set_bg_state(['stop_requested' => true, 'message' => 'Stop requested — finishing current batch then halting.']);
        set_transient('dsn_carfac_sync_stop_requested', 1, HOUR_IN_SECONDS);
        wp_clear_scheduled_hook(self::BG_CRON_HOOK);
        $this->logger->warning('BG Sync: Stop requested.');
    }

    /**
     * True when a stop has been requested. Checks both the (flaky) transient
     * and the option-backed bg state so per-item stop works on hosts where
     * transients get evicted by the object cache.
     */
    public function should_stop() {
        if (get_transient('dsn_carfac_sync_stop_requested')) {
            return true;
        }

        $state = get_option(self::BG_STATE_OPTION, null);
        return is_array($state) && !empty($state['stop_requested']);
    }

    /**
     * Kick off a background sync. Returns the initial state.
     */
    public function start_background_sync() {
        if ($this->is_bg_sync_active()) {
            $this->logger->info('BG Sync: Start requested but a sync is already running.');
            return $this->get_bg_state();
        }

        // Clean slate.
        delete_transient('dsn_carfac_sync_stop_requested');
        wp_clear_scheduled_hook(self::BG_CRON_HOOK);
        delete_option(self::BG_LOCK_OPTION);
        $this->delete_cached_skus();

        $pairs = $this->get_woocommerce_article_pairs();
        $this->set_cached_pairs($pairs);
        $total = count($pairs);

        $worker_token = wp_generate_password(32, false);
        $state = array_merge($this->default_bg_state(), [
            'running' => $total > 0,
            'started_at' => time(),
            'last_batch_at' => time(),
            'offset' => 0,
            'total' => $total,
            'worker_token' => $worker_token,
            'message' => $total > 0
                ? sprintf('Queued background sync for %d Woo→Carfac article_id pairs.', $total)
                : 'No Woo products with dss_syndified.article_id found.',
        ]);
        update_option(self::BG_STATE_OPTION, $state, false);

        $this->logger->info(sprintf('BG Sync: Started with %d SKUs.', $total));

        if ($total === 0) {
            $state['finished_at'] = time();
            $state['running'] = false;
            update_option(self::BG_STATE_OPTION, $state, false);
            return $state;
        }

        // Kick both paths: wp-cron (preferred) and admin-ajax loopback (fallback
        // for sites with DISABLE_WP_CRON or unreliable system cron).
        $this->schedule_next_bg_batch(0);
        $this->trigger_worker_loopback($worker_token);
        return $state;
    }

    /**
     * Public entry-point used by both the WP-Cron action and the admin-ajax
     * loopback. Wraps the worker in a lock so concurrent triggers don't
     * double-process a batch. The next-tick loopback fires AFTER the lock is
     * released so the new worker can immediately acquire it.
     */
    public function run_bg_batch_event() {
        if (!$this->acquire_worker_lock()) {
            $this->logger->info('BG Sync: Worker already running; skipping this tick.');
            return;
        }

        $continuation = null;
        try {
            $continuation = $this->do_bg_batch();
        } catch (\Throwable $e) {
            $this->logger->error('BG Sync: Worker threw: ' . $e->getMessage());
        } finally {
            $this->release_worker_lock();
        }

        // Lock is now released. If there's more work, honor the delay then
        // fire the next loopback. The current PHP process keeps running for
        // the sleep — that's fine because this whole worker runs in the
        // background admin-ajax loopback / wp-cron context, not the user's tab.
        if (is_array($continuation) && !empty($continuation['continue'])) {
            $delay = max(0, (int) ($continuation['delay'] ?? 1));
            if ($delay > 0) {
                sleep($delay);
            }
            $this->trigger_worker_loopback((string) ($continuation['token'] ?? ''));
        }
    }

    /**
     * Process exactly one batch. Returns continuation info so the outer
     * wrapper can fire the next loopback AFTER releasing the worker lock.
     *
     * @return array{continue: bool, token?: string, delay?: int}|null
     */
    private function do_bg_batch() {
        $state = $this->get_bg_state();

        if (!$state['running']) {
            $this->logger->info('BG Sync: Worker fired but state.running=false; exiting.');
            return null;
        }

        if ($state['stop_requested']) {
            $this->set_bg_state([
                'running' => false,
                'finished_at' => time(),
                'message' => 'Sync stopped by user.',
            ]);
            $this->clear_cached_products();
            wp_clear_scheduled_hook(self::BG_CRON_HOOK);
            $this->logger->warning('BG Sync: Stop honoured; sync ended.');
            return null;
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $batchSize = (int) get_option('DSN_CARFAC_sync_batch_size', 25);
        $batchSize = $batchSize > 0 ? min($batchSize, 75) : 25;
        $delay = max(0, (int) get_option('DSN_CARFAC_delay_between_batches', 1));
        $offset = (int) $state['offset'];

        $result = $this->sync_batch($offset, $batchSize);
        if (is_wp_error($result)) {
            $this->set_bg_state([
                'running' => false,
                'finished_at' => time(),
                'error' => $result->get_error_message(),
                'message' => 'Sync failed: ' . $result->get_error_message(),
            ]);
            $this->logger->error('BG Sync: Batch failed: ' . $result->get_error_message());
            return null;
        }

        $next_offset = isset($result['next_offset']) ? (int) $result['next_offset'] : $offset + $batchSize;
        $done = !empty($result['done']);

        $update = [
            'processed' => (int) $state['processed'] + (int) ($result['processed'] ?? 0),
            'updated' => (int) $state['updated'] + (int) ($result['updated'] ?? 0),
            'skipped' => (int) $state['skipped'] + (int) ($result['skipped'] ?? 0),
            'errors' => (int) $state['errors'] + (int) ($result['errors'] ?? 0),
            'offset' => $next_offset,
            'last_batch_at' => time(),
            'message' => sprintf('Processed %d/%d SKUs', $next_offset, $state['total']),
        ];

        if (isset($result['total']) && (int) $result['total'] > 0) {
            $update['total'] = (int) $result['total'];
        }

        // Re-read state in case a stop came in mid-batch.
        $fresh = $this->get_bg_state();
        if (!empty($fresh['stop_requested'])) {
            $update['running'] = false;
            $update['finished_at'] = time();
            $update['message'] = 'Sync stopped by user.';
            $this->set_bg_state($update);
            $this->clear_cached_products();
            wp_clear_scheduled_hook(self::BG_CRON_HOOK);
            $this->logger->warning('BG Sync: Stop honoured after batch; sync ended.');
            return null;
        }

        if ($done) {
            $update['running'] = false;
            $update['finished_at'] = time();
            $update['message'] = sprintf(
                'Sync complete: %d updated, %d skipped, %d errors of %d total.',
                $update['updated'],
                $update['skipped'],
                $update['errors'],
                $update['total'] ?? $state['total']
            );
            $this->set_bg_state($update);
            $this->clear_cached_products();
            wp_clear_scheduled_hook(self::BG_CRON_HOOK);
            $this->logger->info('BG Sync: Complete. ' . $update['message']);
            return null;
        }

        $this->set_bg_state($update);
        // Belt-and-braces: keep a wp-cron event as a fallback in case the
        // loopback HTTP call gets blocked. The post-lock loopback is the
        // primary continuation path.
        $this->schedule_next_bg_batch($next_offset, max(1, $delay));

        return [
            'continue' => true,
            'token' => (string) ($state['worker_token'] ?? ''),
            'delay' => $delay,
        ];
    }

    /**
     * Schedule the next batch via WP-Cron and spawn cron so it fires promptly.
     */
    private function schedule_next_bg_batch($offset, $delay = null) {
        if ($delay === null) {
            $delay = max(2, (int) get_option('DSN_CARFAC_delay_between_batches', 2));
        }

        $fire_at = time() + max(1, (int) $delay);
        if (!wp_next_scheduled(self::BG_CRON_HOOK)) {
            wp_schedule_single_event($fire_at, self::BG_CRON_HOOK);
        }

        spawn_cron($fire_at);
    }

    /**
     * Is a background sync currently active? Includes a "stalled" self-heal:
     * if the state says running but the worker has been silent for >2 minutes,
     * re-schedule a wp-cron tick AND fire a loopback admin-ajax tick.
     */
    public function is_bg_sync_active() {
        $state = $this->get_bg_state();
        if (!$state['running']) {
            return false;
        }

        $stalled = $state['last_batch_at'] > 0 && (time() - $state['last_batch_at']) > 120;
        if ($stalled) {
            $this->logger->warning('BG Sync: Detected stalled run; re-kicking worker.');
            if (!wp_next_scheduled(self::BG_CRON_HOOK)) {
                $this->schedule_next_bg_batch((int) $state['offset']);
            }
            $this->trigger_worker_loopback($state['worker_token'] ?? '');
        }

        return true;
    }

    /**
     * Acquire the worker lock. Returns true if acquired, false if another
     * worker holds it. The lock auto-expires after BG_LOCK_TTL seconds so a
     * crashed worker can't deadlock the chain.
     */
    private function acquire_worker_lock() {
        $now = time();
        $existing = (int) get_option(self::BG_LOCK_OPTION, 0);
        if ($existing > 0 && ($now - $existing) < self::BG_LOCK_TTL) {
            return false;
        }

        update_option(self::BG_LOCK_OPTION, $now, false);
        return true;
    }

    private function release_worker_lock() {
        delete_option(self::BG_LOCK_OPTION);
    }

    /**
     * Fire a non-blocking POST to admin-ajax to advance the background sync.
     * Authenticated by the worker token stored in the run state — no cookies,
     * no nonce required, so it works even when called from server-side cron
     * or after the user closes the browser.
     */
    public function trigger_worker_loopback($token) {
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            return;
        }

        $url = admin_url('admin-ajax.php');
        $args = [
            'timeout'   => 0.5,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies'   => [],
            'body'      => [
                'action' => 'dsn_carfac_sync_worker',
                'token'  => $token,
            ],
        ];

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            $this->logger->warning('BG Sync: Loopback trigger failed: ' . $response->get_error_message());
        }
    }

    /**
     * Validate a worker token against the current run state.
     */
    public function verify_worker_token($token) {
        $state = $this->get_bg_state();
        $stored = isset($state['worker_token']) ? (string) $state['worker_token'] : '';
        if ($stored === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Collect WooCommerce product → Carfac article_id pairs.
     *
     * For each Woo product we read the `dss_syndified` post meta (JSON or
     * PHP-serialized), and pull the nested `article_id`. That article_id is
     * what we send to Carfac in partNameList. Returns:
     *
     *   [
     *     ['article_id' => '124720', 'post_id' => 312],
     *     ['article_id' => '129099', 'post_id' => 419],
     *     ...
     *   ]
     */
    private function get_woocommerce_article_pairs() {
        global $wpdb;

        if (empty($wpdb)) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'dss_syndified'
            AND pm.meta_value <> ''
            AND p.post_type IN ('product', 'product_variation')
            AND p.post_status NOT IN ('trash', 'auto-draft')"
        );

        $pairs = [];
        $seen = [];
        foreach ((array) $rows as $row) {
            $post_id = (int) $row->post_id;
            $raw = (string) $row->meta_value;

            $decoded = $this->decode_dss_syndified($raw);
            if (!is_array($decoded)) {
                continue;
            }

            $article_id = $decoded['article_id']
                ?? $decoded['articleId']
                ?? $decoded['ArticleId']
                ?? $decoded['article_ID']
                ?? null;
            if ($article_id === null || $article_id === '') {
                continue;
            }
            $article_id = is_scalar($article_id) ? trim((string) $article_id) : '';
            if ($article_id === '') {
                continue;
            }

            $dedupe_key = $article_id . '|' . $post_id;
            if (isset($seen[$dedupe_key])) {
                continue;
            }
            $seen[$dedupe_key] = true;

            $pairs[] = [
                'article_id' => $article_id,
                'post_id' => $post_id,
            ];
        }

        return $pairs;
    }

    /**
     * Decode the dss_syndified meta into an array. The meta value can be
     * JSON-encoded or PHP-serialized depending on how it was written; handle
     * both. Returns array on success, null otherwise.
     */
    private function decode_dss_syndified($raw) {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        $maybe_serialized = @unserialize($raw);
        if (is_array($maybe_serialized)) {
            return $maybe_serialized;
        }

        return null;
    }

    public function request_stop() {
        set_transient('dsn_carfac_sync_stop_requested', 1, HOUR_IN_SECONDS);
        $this->logger->warning('Manual Sync: Stop requested; active fetch/batch loops will stop at the next checkpoint.');
    }

    public function get_manual_progress() {
        $progress = get_option('dsn_carfac_sync_progress', null);
        if (!is_array($progress)) {
            $legacy = get_transient('dsn_carfac_sync_progress');
            $progress = is_array($legacy) ? $legacy : [];
        }

        return array_merge([
            'total' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'current' => '',
            'status' => '',
        ], $progress);
    }

    private function set_manual_progress(array $progress) {
        $merged = array_merge($this->get_manual_progress(), $progress);
        update_option('dsn_carfac_sync_progress', $merged, false);
    }

    private function update_manual_progress($result_key, array $product_data, $status) {
        $progress = $this->get_manual_progress();
        $progress['processed'] = (int) $progress['processed'] + 1;
        if (isset($progress[$result_key])) {
            $progress[$result_key] = (int) $progress[$result_key] + 1;
        }
        $progress['current'] = $this->get_carfac_part_name($product_data) ?: $this->get_product_name($product_data);
        $progress['status'] = $status;
        $this->set_manual_progress($progress);
    }

    /**
     * Sync a single product from Carfac to WooCommerce.
     *
     * Strict match: Carfac PartName == dss_syndified.article_id of the Woo
     * product whose post_id is passed in. The caller already resolved the
     * mapping, so this method only needs the Woo post_id — no SKU lookup.
     *
     * @param array $product_data Product data from Carfac
     * @param int   $known_post_id Resolved Woo post ID for this article_id
     * @return int|WP_Error Product ID on success, WP_Error on failure
     */
    private function sync_single_product($product_data, $known_post_id = 0) {
        $partName = $this->get_carfac_part_name($product_data);
        $identifier_log = $this->format_carfac_identifier_log($product_data, $partName ? [$partName] : []);

        if ($partName === '') {
            $this->logger->warning('Skipped Carfac product without PartName: ' . $this->get_product_name($product_data));
            return false;
        }

        $this->log_product_payload($product_data, $identifier_log);
        $product_id = (int) $known_post_id;
        if ($product_id <= 0) {
            $this->logger->warning(sprintf(
                'sync_single_product called without a known Woo post_id for PartName=%s.',
                $partName
            ));
            return false;
        }

        // Update existing product
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->logger->error('Product with ID ' . $product_id . ' not found in WooCommerce.');
            return new \WP_Error('invalid_product', __('Product not found in WooCommerce.', 'dsn-carfac'));
        }

        $sku = $partName;
        $this->logger->info(sprintf('WooCommerce product found: ID=%d | SKU=%s | PartName(article_id)=%s | Name=%s', $product_id, $product->get_sku() ?: 'n/a', $partName, $product->get_name() ?: 'n/a'));

        // --- Price Handling ---
        $old_regular_price = $product->get_regular_price();
        $old_sale_price = $product->get_sale_price();
        $old_stock = $product->get_stock_quantity();

        $new_regular_price = $this->format_carfac_regular_price($product_data);
        // Carfac's Tijdelijke Prijs / temporary sale price is already incl. VAT.
        $new_sale_price = $this->format_price($product_data['SalePrice'] ?? null, true);

        // Always update the regular price from SalesPrice (the main selling price)
        if ($old_regular_price != $new_regular_price) {
            $product->set_regular_price($new_regular_price);
        }

        // Check if the "Use Carfac Sale Price" setting is enabled
        $use_sale_price = get_option('DSN_CARFAC_use_sale_price', '1') !== '0';

        if ($use_sale_price && $new_sale_price !== null && $new_sale_price !== '' && (float)$new_sale_price > 0) {
            // Map Carfac Tijdelijke Prijs / temporary sale price to WooCommerce sale price.
            if ($old_sale_price != $new_sale_price) {
                $product->set_sale_price($new_sale_price);
            }
        } else {
            // Clear any existing sale price when setting is disabled or no sale price from Carfac
            if ($old_sale_price !== '') {
                $product->set_sale_price('');
            }
        }

        // --- Stock Handling ---
        $product->set_manage_stock(true);

        // Use TotalStock if available, otherwise calculate from StockPerWarehouse
        $total_stock = $this->get_total_stock($product_data);
        $product->set_stock_quantity($total_stock);

        // Store per-location stock breakdown as product meta
        $location_stock = [];
        if (isset($product_data['StockPerWarehouse']) && is_array($product_data['StockPerWarehouse'])) {
            foreach ($product_data['StockPerWarehouse'] as $wh) {
                $location_stock[] = [
                    'warehouse_id'   => $wh['WarehouseId'] ?? null,
                    'warehouse_name' => $wh['WarehouseName'] ?? 'Unknown',
                    'quantity'       => (int) ($wh['FreeStock'] ?? 0),
                ];
            }
        }

        // --- Track changes for the UI ---
        $changes = [];
        if ($old_regular_price != $new_regular_price) {
            $changes[] = sprintf(__('Regular Price updated incl. VAT: %s &rarr; %s', 'dsn-carfac'), $old_regular_price ?: '0', $new_regular_price);
        }
        if ($use_sale_price && $new_sale_price !== null && (float)$new_sale_price > 0) {
            if ($old_sale_price != $new_sale_price) {
                $changes[] = sprintf(__('Sale Price updated from Tijdelijke Prijs incl. VAT: %s &rarr; %s', 'dsn-carfac'), $old_sale_price ?: '0', $new_sale_price);
            }
        } elseif ($old_sale_price !== '' && $old_sale_price !== null) {
            $changes[] = __('Sale Price cleared', 'dsn-carfac');
        }
        if ($old_stock != $total_stock) {
            $stock_detail = sprintf(__('Stock updated: %s &rarr; %s', 'dsn-carfac'), $old_stock ?: '0', $total_stock);
            // Add per-location breakdown to the change note
            if (!empty($location_stock)) {
                $parts = [];
                foreach ($location_stock as $ls) {
                    $parts[] = $ls['warehouse_name'] . ': ' . $ls['quantity'];
                }
                $stock_detail .= ' (' . implode(', ', $parts) . ')';
            }
            $changes[] = $stock_detail;
        }

        $this->update_carfac_product_meta($product, $product_data, $changes, $location_stock, $sku);

        // Log changes to a txt file if price or stock changed
        $regular_price_changed = ($old_regular_price != $new_regular_price);
        $sale_price_changed = ($use_sale_price && $old_sale_price != $new_sale_price);
        if ($regular_price_changed || $sale_price_changed || $old_stock != $total_stock) {
            $log_parts = [
                sprintf('[%s] SKU: %s', date('Y-m-d H:i:s'), $product->get_sku()),
            ];
            if ($regular_price_changed) {
                $log_parts[] = sprintf('Regular Price incl. VAT: %s → %s', $old_regular_price, $new_regular_price);
            }
            if ($sale_price_changed) {
                $log_parts[] = sprintf('Sale Price incl. VAT: %s → %s', $old_sale_price, $new_sale_price);
            }
            if ($old_stock != $total_stock) {
                $stock_log = sprintf('Stock: %s → %s', $old_stock, $total_stock);
                if (!empty($location_stock)) {
                    $loc_parts = [];
                    foreach ($location_stock as $ls) {
                        $loc_parts[] = $ls['warehouse_name'] . ':' . $ls['quantity'];
                    }
                    $stock_log .= ' [' . implode(', ', $loc_parts) . ']';
                }
                $log_parts[] = $stock_log;
            }

            $log_entry = implode(' | ', $log_parts) . "\n";
            $log_file = DSN_CARFAC_PLUGIN_DIR . 'product_changes_log.txt';
            file_put_contents($log_file, $log_entry, FILE_APPEND);
            $this->logger->info(sprintf(
                'Updated WooCommerce product: ID=%d | SKU=%s | %s',
                $product_id,
                $product->get_sku() ?: $sku,
                implode(' | ', array_slice($log_parts, 1))
            ));
        } else {
            $this->logger->info(sprintf('WooCommerce product found, no changes: ID=%d | SKU=%s', $product_id, $product->get_sku() ?: $sku));
        }

        // Save product
        $product_id = $product->save();


        if (is_wp_error($product_id)) {
            $this->logger->error('Failed to save product SKU: ' . $sku . ' - ' . $product_id->get_error_message());
            return $product_id;
        }

        return $product_id;
    }

    /**
     * Extract the Carfac PartName from a normalized product row. The plugin
     * matches Carfac PartName 1:1 against the Woo product's
     * dss_syndified.article_id — never against _sku or anything else.
     */
    private function get_carfac_part_name(array $product_data) {
        foreach (['CarfacPartName', 'PartName', 'partName'] as $key) {
            if (!empty($product_data[$key])) {
                return wc_clean((string) $product_data[$key]);
            }
        }

        return '';
    }

    private function update_carfac_product_meta($product, array $product_data, array $changes, array $location_stock, string $fallback_identifier) {
        $part_id = $this->get_first_product_value($product_data, ['CarfacPartId', 'PartId', 'partId']);
        $part_name = $this->get_first_product_value($product_data, ['CarfacPartName', 'PartName', 'partName']);
        $product_id = $this->get_first_product_value($product_data, ['CarfacProductId', 'ProductId', 'productId']);
        $product_code = $this->get_first_product_value($product_data, ['CarfacProductCode', 'ProductCode', 'productCode']);
        $source = $product_data['CarfacSource'] ?? 'unknown';

        $carfac_id = $part_id ?: ($product_id ?: $fallback_identifier);
        $product->update_meta_data('_carfac_product_id', $carfac_id);
        $product->update_meta_data('_carfac_part_id', $part_id);
        $product->update_meta_data('_carfac_part_name', $part_name);
        $product->update_meta_data('_carfac_product_code', $product_code);
        $product->update_meta_data('_carfac_source', $source);
        $product->update_meta_data('_dsn_carfac_last_sync', current_time('mysql'));

        $regular_price_ex_vat = $this->format_price($product_data['SalesPrice'] ?? null, true);
        $regular_price_inc_vat = $this->format_carfac_regular_price($product_data);
        $sale_price_inc_vat = $this->format_price($product_data['SalePrice'] ?? null, true);

        $product->update_meta_data('_carfac_sales_price_ex_vat', $regular_price_ex_vat);
        $product->update_meta_data('_carfac_sales_price_inc_vat', $regular_price_inc_vat);
        $product->update_meta_data('_carfac_sale_price_inc_vat', $sale_price_inc_vat);

        if (!empty($location_stock)) {
            $product->update_meta_data('_carfac_stock_per_location', $location_stock);
        } else {
            $product->delete_meta_data('_carfac_stock_per_location');
        }

        $product->update_meta_data('_dsn_carfac_last_update', [
            'date' => current_time('mysql'),
            'changes' => !empty($changes) ? $changes : [__('Synced with Carfac; no price or stock changes.', 'dsn-carfac')],
            'carfac' => [
                'source' => $source,
                'part_id' => $part_id,
                'part_name' => $part_name,
                'product_id' => $product_id,
                'product_code' => $product_code,
                'regular_price_ex_vat' => $regular_price_ex_vat,
                'regular_price_inc_vat' => $regular_price_inc_vat,
                'sale_price_inc_vat' => $sale_price_inc_vat,
            ],
        ]);
    }

    private function get_first_product_value(array $product_data, array $keys) {
        foreach ($keys as $key) {
            if (isset($product_data[$key]) && $product_data[$key] !== '') {
                return (string) $product_data[$key];
            }
        }

        return '';
    }

    private function format_carfac_identifier_log(array $product_data, array $lookup_codes) {
        $parts = [
            'Source=' . ($product_data['CarfacSource'] ?? 'unknown'),
            'PartName=' . ($product_data['CarfacPartName'] ?? $product_data['PartName'] ?? $product_data['partName'] ?? 'n/a'),
            'PartId=' . ($product_data['CarfacPartId'] ?? $product_data['PartId'] ?? $product_data['partId'] ?? 'n/a'),
            'ProductId=' . ($product_data['CarfacProductId'] ?? $product_data['ProductId'] ?? $product_data['productId'] ?? 'n/a'),
            'ProductCode=' . ($product_data['CarfacProductCode'] ?? $product_data['ProductCode'] ?? $product_data['productCode'] ?? 'n/a'),
            'LookupCandidates=' . (empty($lookup_codes) ? 'none' : implode(', ', $lookup_codes)),
        ];

        return implode(' | ', $parts);
    }

    private function log_product_payload(array $product_data, string $identifier_log) {
        if (get_option('DSN_CARFAC_log_product_payloads', '0') !== '1') {
            return;
        }

        $payload = wp_json_encode($product_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $payload = print_r($product_data, true);
        }

        $this->logger->info("Carfac product payload: {$identifier_log}\n" . $payload);
    }

    private function get_product_name(array $product_data) {
        foreach (['PartName', 'partName', 'Description1', 'description1', 'Description', 'description', 'Name', 'name'] as $key) {
            if (!empty($product_data[$key])) {
                return (string) $product_data[$key];
            }
        }

        return 'Unknown';
    }

    private function format_carfac_regular_price(array $product_data) {
        $price = $this->format_price($product_data['SalesPrice'] ?? '', true);
        if ($price === null || $price === '') {
            return '';
        }

        if ($this->carfac_regular_price_includes_vat($product_data)) {
            return $price;
        }

        return $this->format_price((float) $price * (1 + self::CARFAC_VAT_RATE));
    }

    private function carfac_regular_price_includes_vat(array $product_data) {
        foreach (['SalesPriceIsIncVat', 'salesPriceIsIncVat', 'SellingPriceIsIncVat', 'sellingPriceIsIncVat'] as $key) {
            if (!array_key_exists($key, $product_data)) {
                continue;
            }

            $value = $product_data[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value === 1;
            }

            $value = strtolower(trim((string) $value));
            if (in_array($value, ['1', 'true', 'yes', 'y', 'incl', 'included', 'incvat', 'inclvat'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'n', 'excl', 'excluded', 'exvat', 'exclvat'], true)) {
                return false;
            }
        }

        return false;
    }

    private function format_price($price, $allow_empty = false) {
        if ($price === null || $price === '') {
            return $allow_empty ? null : '';
        }

        if (is_string($price)) {
            $price = trim($price);
            if (strpos($price, ',') !== false && strpos($price, '.') === false) {
                $price = str_replace(',', '.', $price);
            }
            $price = preg_replace('/[^0-9.\-]/', '', $price);
        }

        if (!is_numeric($price)) {
            return $allow_empty ? null : '';
        }

        return wc_format_decimal((float) $price, wc_get_price_decimals());
    }

    private function get_total_stock(array $product_data) {
        foreach (['TotalStock', 'totalStock', 'Stock', 'stock', 'QuantityInStock', 'quantityInStock'] as $key) {
            if (isset($product_data[$key]) && is_numeric($product_data[$key])) {
                return (int) $product_data[$key];
            }
        }

        $total_stock = 0;
        if (!empty($product_data['StockPerWarehouse']) && is_array($product_data['StockPerWarehouse'])) {
            foreach ($product_data['StockPerWarehouse'] as $warehouse_stock) {
                $total_stock += (int) ($warehouse_stock['FreeStock'] ?? $warehouse_stock['freeStock'] ?? 0);
                $total_stock += (int) ($warehouse_stock['ShelfStock'] ?? $warehouse_stock['shelfStock'] ?? 0);
                $total_stock += (int) ($warehouse_stock['EconomicalStock'] ?? $warehouse_stock['economicalStock'] ?? 0);
            }
        }

        return $total_stock;
    }

    /**
     * Check product stock in Carfac before purchase
     *
     * @param int $product_id WooCommerce product ID
     * @param int $quantity Requested quantity
     * @return bool|WP_Error True if stock available, WP_Error if not
     */
    public function check_stock_before_purchase($product_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('invalid_product', __('Invalid product.', 'dsn-carfac'));
        }

        $sku = $product->get_sku();
        if (!$sku) {
            return new \WP_Error('no_sku', __('Product does not have a SKU.', 'dsn-carfac'));
        }

        $stock_data = $this->api_handler->get_product_stock($sku);
        if (is_wp_error($stock_data)) {
            return $stock_data;
        }

        if ($stock_data['quantity'] < $quantity) {
            return new \WP_Error(
                'insufficient_stock',
                sprintf(
                    __('Sorry, we do not have enough "%s" in stock. Only %d available.', 'dsn-carfac'),
                    $product->get_name(),
                    $stock_data['quantity']
                )
            );
        }

        return true;
    }

    public function start_scheduled_sync() {
        if (get_transient('dsn_carfac_cron_sync_running')) {
            $this->logger->warning('Cron Sync: A product sync is already running; skipping duplicate trigger.');
            return false;
        }

        $this->logger->info('Cron Sync: Starting product sync using SKU-chunked Part/GetParts flow.');
        set_transient('dsn_carfac_cron_sync_running', [
            'total' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ], HOUR_IN_SECONDS);

        $total = $this->prepare_sku_sync();

        set_transient('dsn_carfac_cron_sync_running', [
            'total' => (int) $total,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ], HOUR_IN_SECONDS);

        if ((int) $total === 0) {
            $this->logger->info('Cron Sync: No WooCommerce SKUs to look up in Carfac.');
            $this->clear_cached_products();
            delete_transient('dsn_carfac_cron_sync_running');
            return true;
        }

        return $this->process_scheduled_sync_batch(0);
    }

    public function process_scheduled_sync_batch($offset = 0) {
        $state = get_transient('dsn_carfac_cron_sync_running');
        if (empty($state) || !is_array($state)) {
            $this->logger->warning('Cron Sync: No active scheduled sync state found.');
            return false;
        }

        $batchSize = (int) get_option('DSN_CARFAC_sync_batch_size', 25);
        $batchSize = $batchSize > 0 ? min($batchSize, 75) : 25;
        $offset = absint($offset);
        $total = (int) ($state['total'] ?? 0);

        $this->logger->info(sprintf(
            'Cron Sync: Processing batch offset=%d limit=%d total=%d',
            $offset,
            $batchSize,
            $total
        ));

        $result = $this->sync_batch($offset, $batchSize);
        if (is_wp_error($result)) {
            $this->logger->error('Cron Sync: Batch failed: ' . $result->get_error_message());
            $this->clear_cached_products();
            delete_transient('dsn_carfac_cron_sync_running');
            return $result;
        }

        $state['processed'] = (int) ($state['processed'] ?? 0) + (int) ($result['processed'] ?? 0);
        $state['updated'] = (int) ($state['updated'] ?? 0) + (int) ($result['updated'] ?? 0);
        $state['skipped'] = (int) ($state['skipped'] ?? 0) + (int) ($result['skipped'] ?? 0);
        $state['errors'] = (int) ($state['errors'] ?? 0) + (int) ($result['errors'] ?? 0);
        set_transient('dsn_carfac_cron_sync_running', $state, HOUR_IN_SECONDS);

        $nextOffset = $offset + $batchSize;
        if ($nextOffset < $total) {
            $delay = (int) get_option('DSN_CARFAC_delay_between_batches', 1);
            $delay = max(1, $delay);
            wp_schedule_single_event(time() + $delay, 'DSN_CARFAC_process_sync_batch', [$nextOffset]);
            $this->logger->info(sprintf('Cron Sync: Scheduled next batch at offset=%d in %d second(s).', $nextOffset, $delay));
            return true;
        }

        $this->logger->info(sprintf(
            'Cron Sync: Complete. Updated: %d, Skipped: %d, Errors: %d, Total: %d',
            $state['updated'],
            $state['skipped'],
            $state['errors'],
            $state['processed']
        ));
        $this->clear_cached_products();
        delete_transient('dsn_carfac_cron_sync_running');
        return true;
    }

    /**
     * Process a single batch by slicing the cached WooCommerce SKU list, asking
     * Carfac for those exact part names via Part/GetParts (small payload), then
     * updating the matching WooCommerce products.
     *
     * @param int $offset Start index in the cached SKU list
     * @param int $limit  Number of SKUs to look up in this batch
     * @return array{processed: int, skipped: int, updated: int, errors: int, messages: string[]}|WP_Error
     */
    public function sync_batch($offset, $limit) {
        $pairs = $this->get_cached_pairs();
        if (empty($pairs)) {
            $this->logger->warning('Manual Sync Batch: pair cache missing; rebuilding from dss_syndified meta.');
            $pairs = $this->get_woocommerce_article_pairs();
            if (!empty($pairs)) {
                $this->set_cached_pairs($pairs);
            }
        }
        if (empty($pairs)) {
            return new \WP_Error('no_cached_pairs', __('No Woo→Carfac article_id pairs found. Please start a new sync.', 'dsn-carfac'));
        }

        $offset = absint($offset);
        $limit = max(1, absint($limit));
        $total = count($pairs);

        $results = [
            'processed' => 0,
            'skipped'   => 0,
            'updated'   => 0,
            'errors'    => 0,
            'messages'  => [],
            'done'      => false,
            'total'     => $total,
            'next_offset' => $offset,
        ];

        if ($offset >= $total) {
            $this->logger->info(sprintf(
                'Manual Sync Batch: offset=%d is past total=%d; signalling done.',
                $offset,
                $total
            ));
            $results['done'] = true;
            $results['next_offset'] = $total;
            return $results;
        }

        $chunk = array_slice($pairs, $offset, $limit);
        $this->logger->info(sprintf(
            'Manual Sync Batch: offset=%d limit=%d chunk_count=%d total_pairs=%d',
            $offset,
            $limit,
            count($chunk),
            $total
        ));

        if (empty($chunk)) {
            $results['done'] = true;
            $results['next_offset'] = $total;
            return $results;
        }

        $article_ids = array_values(array_filter(array_map(function($pair) {
            return isset($pair['article_id']) ? (string) $pair['article_id'] : '';
        }, $chunk)));

        $parts = $this->api_handler->get_parts_by_skus($article_ids);
        if (is_wp_error($parts)) {
            $this->logger->error('Manual Sync Batch: Carfac lookup failed: ' . $parts->get_error_message());
            return $parts;
        }

        $byPartName = [];
        foreach ((array) $parts as $part) {
            $name = $part['CarfacPartName'] ?? $part['PartName'] ?? $part['partName'] ?? '';
            $name = is_string($name) ? trim($name) : '';
            if ($name !== '') {
                $byPartName[$name] = $part;
            }
        }

        foreach ($chunk as $pair) {
            if ($this->should_stop()) {
                $this->logger->warning('Manual Sync Batch: Stop flag detected; ending batch early.');
                break;
            }

            $position = $offset + $results['processed'] + 1;
            $results['processed']++;

            $article_id = isset($pair['article_id']) ? (string) $pair['article_id'] : '';
            $post_id = isset($pair['post_id']) ? (int) $pair['post_id'] : 0;

            if ($article_id === '' || $post_id <= 0) {
                $results['skipped']++;
                $this->update_manual_progress('skipped', ['CarfacPartName' => $article_id], 'invalid pair');
                continue;
            }

            $product_data = $byPartName[$article_id] ?? null;
            if (!$product_data) {
                $results['skipped']++;
                $this->update_manual_progress('skipped', ['CarfacPartName' => $article_id, 'PartName' => $article_id], 'no Carfac match');
                $this->logger->warning(sprintf(
                    'Skipped %d/%d: Carfac returned no PartName=%s (Woo post_id=%d).',
                    $position,
                    $total,
                    $article_id,
                    $post_id
                ));
                $results['messages'][] = sprintf(__('Skipped: %s (no Carfac match)', 'dsn-carfac'), $article_id);
                continue;
            }

            $sync_result = $this->sync_single_product($product_data, $post_id);

            if ($sync_result === false) {
                $results['skipped']++;
                $this->update_manual_progress('skipped', $product_data, 'skipped');
                $results['messages'][] = sprintf(__('Skipped: %s', 'dsn-carfac'), $article_id);
            } elseif (is_wp_error($sync_result)) {
                $results['errors']++;
                $this->update_manual_progress('errors', $product_data, 'error');
                $this->logger->error(sprintf(
                    'Error %d/%d: %s',
                    $position,
                    $total,
                    $sync_result->get_error_message()
                ));
                $results['messages'][] = sprintf(__('Error: %s', 'dsn-carfac'), $sync_result->get_error_message());
            } else {
                $results['updated']++;
                $this->update_manual_progress('updated', $product_data, 'updated');
                $this->logger->info(sprintf(
                    'Updated %d/%d: Woo product ID=%d (article_id=%s)',
                    $position,
                    $total,
                    (int) $sync_result,
                    $article_id
                ));
            }
        }

        $results['next_offset'] = min($total, $offset + $limit);
        $results['done'] = $results['next_offset'] >= $total;
        return $results;
    }

    /**
     * Clear cached SKU list + legacy product transient after sync completes.
     */
    public function clear_cached_products() {
        delete_transient('dsn_carfac_sync_products');
        $this->delete_cached_skus();
    }
}
