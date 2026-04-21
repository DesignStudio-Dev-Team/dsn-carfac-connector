<?php
namespace DSNWooPowerall;

/**
 * Read-only WooCommerce product meta box showing the last Powerall sync info.
 *
 * Registers a side panel on product + product_variation edit screens that lists
 * what the plugin recorded during the most recent sync for that product:
 * last run time, result, price/stock diffs, raw Powerall snapshot, and the
 * warehouse breakdown (aggregated from variations for variable parents).
 *
 * Nothing in this meta box is editable — it's purely a transparency surface
 * so store managers can inspect "what did the sync do to this product?"
 * without digging through plugin logs.
 */
class Product_Meta_Box {

    const META_LAST_SYNC_AT       = '_dsn_powerall_last_sync_at';
    const META_LAST_SYNC_RESULT   = '_dsn_powerall_last_sync_result';
    const META_LAST_SYNC_CHANGES  = '_dsn_powerall_last_sync_changes';
    const META_LAST_SYNC_SNAPSHOT = '_dsn_powerall_last_sync_snapshot';
    const META_LAST_SYNC_MESSAGE  = '_dsn_powerall_last_sync_message';

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
    }

    /**
     * Register the meta box on product and product_variation edit screens.
     *
     * @return void
     */
    public function register_meta_box(): void {
        foreach (array('product', 'product_variation') as $screen) {
            add_meta_box(
                'dsn_powerall_product_sync_info',
                __('Powerall Sync Info', 'dsn-woo-powerall'),
                array($this, 'render_meta_box'),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the meta box contents.
     *
     * @param \WP_Post $post
     * @return void
     */
    public function render_meta_box($post): void {
        if (!$post || !isset($post->ID)) {
            return;
        }

        $product_id = (int) $post->ID;

        $last_at       = get_post_meta($product_id, self::META_LAST_SYNC_AT, true);
        $last_result   = get_post_meta($product_id, self::META_LAST_SYNC_RESULT, true);
        $last_message  = get_post_meta($product_id, self::META_LAST_SYNC_MESSAGE, true);
        $changes_json  = get_post_meta($product_id, self::META_LAST_SYNC_CHANGES, true);
        $snapshot_json = get_post_meta($product_id, self::META_LAST_SYNC_SNAPSHOT, true);

        $changes  = is_string($changes_json) && $changes_json !== '' ? json_decode($changes_json, true) : array();
        $snapshot = is_string($snapshot_json) && $snapshot_json !== '' ? json_decode($snapshot_json, true) : array();

        if (!is_array($changes)) {
            $changes = array();
        }
        if (!is_array($snapshot)) {
            $snapshot = array();
        }

        ?>
        <style>
            .dsn-ppmb { font-size: 12px; line-height: 1.5; }
            .dsn-ppmb h4 { margin: 12px 0 4px; font-size: 12px; text-transform: uppercase; color: #50575e; letter-spacing: .03em; }
            .dsn-ppmb dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 2px 10px; }
            .dsn-ppmb dt { color: #646970; }
            .dsn-ppmb dd { margin: 0; color: #1d2327; word-break: break-word; }
            .dsn-ppmb .dsn-ppmb-badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em; }
            .dsn-ppmb .dsn-ppmb-badge--updated { background: #d1e7dd; color: #0a3622; }
            .dsn-ppmb .dsn-ppmb-badge--synced  { background: #e7f1ff; color: #052c65; }
            .dsn-ppmb .dsn-ppmb-badge--failed  { background: #f8d7da; color: #58151c; }
            .dsn-ppmb .dsn-ppmb-diff { background: #fff7e6; padding: 1px 4px; border-radius: 3px; }
            .dsn-ppmb table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            .dsn-ppmb table th, .dsn-ppmb table td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #f0f0f1; font-size: 12px; }
            .dsn-ppmb table th { color: #646970; font-weight: 500; background: #fafafa; }
            .dsn-ppmb .dsn-ppmb-empty { color: #646970; font-style: italic; }
            .dsn-ppmb .dsn-ppmb-message { color: #646970; margin: 4px 0 0; }
            .dsn-ppmb .dsn-ppmb-arrow { color: #8c8f94; margin: 0 3px; }
        </style>

        <div class="dsn-ppmb">
            <?php if (empty($last_at)) : ?>
                <p class="dsn-ppmb-empty">
                    <?php esc_html_e('This product has not been synced from Powerall yet.', 'dsn-woo-powerall'); ?>
                </p>
            <?php else : ?>
                <dl>
                    <dt><?php esc_html_e('Last sync', 'dsn-woo-powerall'); ?></dt>
                    <dd><?php echo esc_html($this->format_timestamp((int) $last_at)); ?></dd>

                    <dt><?php esc_html_e('Result', 'dsn-woo-powerall'); ?></dt>
                    <dd><?php echo $this->render_result_badge((string) $last_result); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
                </dl>

                <?php if (!empty($last_message)) : ?>
                    <p class="dsn-ppmb-message"><?php echo esc_html($last_message); ?></p>
                <?php endif; ?>

                <h4><?php esc_html_e('Changes this run', 'dsn-woo-powerall'); ?></h4>
                <?php $this->render_changes($changes); ?>

                <h4><?php esc_html_e('Powerall payload', 'dsn-woo-powerall'); ?></h4>
                <?php $this->render_snapshot($snapshot); ?>
            <?php endif; ?>

            <h4><?php esc_html_e('Warehouse stock', 'dsn-woo-powerall'); ?></h4>
            <?php $this->render_warehouses($product_id); ?>
        </div>
        <?php
    }

    /**
     * Format a UTC unix timestamp as the site-local admin date string.
     *
     * @param int $ts
     * @return string
     */
    private function format_timestamp(int $ts): string {
        if ($ts <= 0) {
            return '—';
        }
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $local  = function_exists('wp_date') ? wp_date($format, $ts) : date_i18n($format, $ts);

        $diff = human_time_diff($ts, time());
        /* translators: 1: absolute local date/time, 2: relative time like "5 minutes" */
        return sprintf(__('%1$s (%2$s ago)', 'dsn-woo-powerall'), $local, $diff);
    }

    /**
     * Render the result badge (span).
     *
     * @param string $result
     * @return string
     */
    private function render_result_badge(string $result): string {
        $label = $result !== '' ? $result : 'unknown';
        $class = 'dsn-ppmb-badge dsn-ppmb-badge--' . sanitize_html_class($result ?: 'unknown');
        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Render the change list.
     *
     * @param array<string, mixed> $changes
     * @return void
     */
    private function render_changes(array $changes): void {
        if (empty($changes)) {
            echo '<p class="dsn-ppmb-empty">' . esc_html__('Nothing changed — the product already matched Powerall.', 'dsn-woo-powerall') . '</p>';
            return;
        }

        $rows = array();
        if (isset($changes['price'])) {
            $rows[] = array(
                __('Sale price', 'dsn-woo-powerall'),
                $this->format_diff($changes['price']['from'] ?? null, $changes['price']['to'] ?? null),
            );
        }
        if (isset($changes['stock'])) {
            $rows[] = array(
                __('Stock qty', 'dsn-woo-powerall'),
                $this->format_diff($changes['stock']['from'] ?? null, $changes['stock']['to'] ?? null),
            );
        }
        if (isset($changes['stock_status'])) {
            $rows[] = array(
                __('Stock status', 'dsn-woo-powerall'),
                $this->format_diff($changes['stock_status']['from'] ?? null, $changes['stock_status']['to'] ?? null),
            );
        }
        if (!empty($changes['manage_stock_enabled'])) {
            $rows[] = array(
                __('Manage stock', 'dsn-woo-powerall'),
                esc_html__('Enabled during this sync', 'dsn-woo-powerall'),
            );
        }

        if (empty($rows)) {
            echo '<p class="dsn-ppmb-empty">' . esc_html__('No tracked changes.', 'dsn-woo-powerall') . '</p>';
            return;
        }

        echo '<table><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th>' . esc_html($row[0]) . '</th><td>' . $row[1] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by format_diff
        }
        echo '</tbody></table>';
    }

    /**
     * Format a "from → to" diff cell.
     *
     * @param mixed $from
     * @param mixed $to
     * @return string Safe-to-echo HTML.
     */
    private function format_diff($from, $to): string {
        $from_str = $this->stringify($from);
        $to_str   = $this->stringify($to);

        return sprintf(
            '%s <span class="dsn-ppmb-arrow">→</span> <span class="dsn-ppmb-diff">%s</span>',
            esc_html($from_str),
            esc_html($to_str)
        );
    }

    /**
     * Render the Powerall payload snapshot as a small key/value table.
     *
     * @param array<string, mixed> $snapshot
     * @return void
     */
    private function render_snapshot(array $snapshot): void {
        if (empty($snapshot)) {
            echo '<p class="dsn-ppmb-empty">' . esc_html__('No Powerall snapshot recorded.', 'dsn-woo-powerall') . '</p>';
            return;
        }

        $labels = array(
            'product_code'         => __('Product code', 'dsn-woo-powerall'),
            'sku'                  => __('EAN / SKU', 'dsn-woo-powerall'),
            'product_name'         => __('Name', 'dsn-woo-powerall'),
            'sales_price'          => __('SalesPrice', 'dsn-woo-powerall'),
            'promotional_price'    => __('PromotionalPrice', 'dsn-woo-powerall'),
            'sales_price_inc_vat'  => __('Price inc. VAT?', 'dsn-woo-powerall'),
            'applied_price'        => __('Applied sale price', 'dsn-woo-powerall'),
            'applied_stock'        => __('Applied stock qty', 'dsn-woo-powerall'),
            'applied_stock_status' => __('Applied stock status', 'dsn-woo-powerall'),
            'stock_mode'           => __('Stock mode', 'dsn-woo-powerall'),
            'warehouse_count'      => __('Warehouses in payload', 'dsn-woo-powerall'),
        );

        echo '<table><tbody>';
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $snapshot)) {
                continue;
            }
            $value = $snapshot[$key];
            if ($value === null || $value === '') {
                continue;
            }
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($this->stringify($value)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Render the current warehouse stock list (aggregated for variable parents).
     *
     * @param int $product_id
     * @return void
     */
    private function render_warehouses(int $product_id): void {
        $warehouses = $this->load_warehouses_for_meta_box($product_id);

        if (empty($warehouses)) {
            echo '<p class="dsn-ppmb-empty">' . esc_html__('No warehouse data stored for this product.', 'dsn-woo-powerall') . '</p>';
            return;
        }

        $mode = Stock_Helper::get_selected_mode();

        echo '<table><thead><tr>';
        echo '<th>' . esc_html__('Location', 'dsn-woo-powerall') . '</th>';
        echo '<th>' . esc_html__('Free', 'dsn-woo-powerall') . '</th>';
        echo '<th>' . esc_html__('Econ.', 'dsn-woo-powerall') . '</th>';
        echo '<th>' . esc_html__('Shelf', 'dsn-woo-powerall') . '</th>';
        echo '<th>' . esc_html__('Counted', 'dsn-woo-powerall') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($warehouses as $wh) {
            if (!is_array($wh)) {
                continue;
            }

            $name      = Stock_Helper::get_warehouse_name($wh);
            $included  = Stock_Helper::is_warehouse_included($wh);
            $free      = isset($wh['FreeStock']) ? (float) $wh['FreeStock'] : 0.0;
            $econ      = isset($wh['EconomicalStock']) ? (float) $wh['EconomicalStock'] : 0.0;
            $shelf     = isset($wh['ShelfStock']) ? (float) $wh['ShelfStock'] : 0.0;
            $counted   = $included
                ? $this->pick_mode_value($mode, $free, $econ, $shelf)
                : null;

            echo '<tr>';
            echo '<td>' . esc_html($name !== '' ? $name : __('(unnamed)', 'dsn-woo-powerall')) . '</td>';
            echo '<td>' . esc_html($this->format_number($free)) . '</td>';
            echo '<td>' . esc_html($this->format_number($econ)) . '</td>';
            echo '<td>' . esc_html($this->format_number($shelf)) . '</td>';
            if ($included) {
                echo '<td><strong>' . esc_html($this->format_number((float) $counted)) . '</strong></td>';
            } else {
                echo '<td><em>' . esc_html__('ignored', 'dsn-woo-powerall') . '</em></td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Mirror of Stock_Display::warehouse_stock_for_mode but without rounding —
     * we want the raw per-mode number for display purposes.
     *
     * @param string $mode
     * @param float  $free
     * @param float  $econ
     * @param float  $shelf
     * @return float
     */
    private function pick_mode_value(string $mode, float $free, float $econ, float $shelf): float {
        switch ($mode) {
            case 'EconomicalStock':
                return $econ;
            case 'ShelfStock':
                return $shelf;
            case 'FreeStock_ShelfStock':
                return $free + $shelf;
            case 'all_combined':
                return $econ + $free + $shelf;
            case 'FreeStock':
            default:
                return $free;
        }
    }

    /**
     * Load the warehouse payload, aggregating across variations for variable parents.
     * Mirrors Stock_Display::load_warehouse_data so the meta box matches the
     * frontend renderer.
     *
     * @param int $product_id
     * @return array<int, array<string, mixed>>
     */
    private function load_warehouses_for_meta_box(int $product_id): array {
        $raw = get_post_meta($product_id, Stock_Display::META_KEY, true);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        if (!function_exists('wc_get_product')) {
            return array();
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return array();
        }

        $buckets = array();
        foreach ($product->get_children() as $vid) {
            $vraw = get_post_meta((int) $vid, Stock_Display::META_KEY, true);
            if (!$vraw) {
                continue;
            }
            $decoded = json_decode($vraw, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $wh) {
                if (!is_array($wh)) {
                    continue;
                }
                $key = Stock_Helper::get_warehouse_name($wh);
                if ($key === '') {
                    continue;
                }
                if (!isset($buckets[$key])) {
                    $buckets[$key] = array(
                        'Warehouse'       => $wh['Warehouse'] ?? array(),
                        'WarehouseName'   => $wh['WarehouseName'] ?? null,
                        'WarehouseCode'   => $wh['WarehouseCode'] ?? null,
                        'EconomicalStock' => 0,
                        'FreeStock'       => 0,
                        'ShelfStock'      => 0,
                    );
                }
                $buckets[$key]['EconomicalStock'] += (float) ($wh['EconomicalStock'] ?? 0);
                $buckets[$key]['FreeStock']       += (float) ($wh['FreeStock'] ?? 0);
                $buckets[$key]['ShelfStock']      += (float) ($wh['ShelfStock'] ?? 0);
            }
        }

        return array_values($buckets);
    }

    /**
     * Coerce any scalar/array value into a short display string.
     *
     * @param mixed $value
     * @return string
     */
    private function stringify($value): string {
        if (is_bool($value)) {
            return $value ? __('yes', 'dsn-woo-powerall') : __('no', 'dsn-woo-powerall');
        }
        if (is_null($value)) {
            return '—';
        }
        if (is_scalar($value)) {
            $str = (string) $value;
            return $str === '' ? '—' : $str;
        }
        $encoded = wp_json_encode($value);
        return is_string($encoded) ? $encoded : '—';
    }

    /**
     * Format a float for display: drop trailing zeros, keep up to 4 decimals.
     *
     * @param float $value
     * @return string
     */
    private function format_number(float $value): string {
        if ((float) (int) $value === $value) {
            return (string) (int) $value;
        }
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
