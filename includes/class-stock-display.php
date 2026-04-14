<?php
namespace DSNWooPowerall;

/**
 * Frontend stock display shortcode + automatic WooCommerce product page hooks.
 *
 * Shortcode usage:
 *   [dsn_powerall_stock]               — auto-detects product on single product pages
 *   [dsn_powerall_stock id="42"]       — explicit WooCommerce product ID
 *
 * Settings:
 *   dsn_woo_powerall_frontend_stock_enabled   — toggle on/off
 *   dsn_woo_powerall_frontend_stock_display   — 'combined' | 'per_warehouse'
 *   dsn_woo_powerall_frontend_stock_position  — 'disabled' | 'below_title' | 'after_price' | 'before_add_to_cart'
 */
class Stock_Display {

    /** Number of warehouses shown inline before the "Show more" link appears. */
    const PREVIEW_LIMIT = 3;

    /** Product meta key where raw Powerall warehouse data is stored. */
    const META_KEY = '_powerall_stock_warehouses';

    /**
     * WooCommerce woocommerce_single_product_summary hook priorities for each position.
     * Title=5, Rating/Price=10, Excerpt=20, Add to cart=30.
     */
    const POSITION_PRIORITIES = array(
        'below_title'       => 7,
        'after_price'       => 15,
        'before_add_to_cart' => 25,
    );

    public function __construct() {
        add_shortcode('dsn_powerall_stock', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        $this->register_auto_display_hook();
    }

    /**
     * Hook into the WooCommerce product summary at the position chosen in settings.
     *
     * @return void
     */
    private function register_auto_display_hook() {
        $position = self::get_auto_position();
        if ($position === 'disabled' || !isset(self::POSITION_PRIORITIES[$position])) {
            return;
        }

        add_action(
            'woocommerce_single_product_summary',
            array($this, 'render_auto_display'),
            self::POSITION_PRIORITIES[$position]
        );
    }

    /**
     * The auto-display position setting value.
     *
     * @return string  'disabled' | 'below_title' | 'after_price' | 'before_add_to_cart'
     */
    public static function get_auto_position() {
        $value = get_option('dsn_woo_powerall_frontend_stock_position', 'disabled');
        $valid  = array_merge(array('disabled'), array_keys(self::POSITION_PRIORITIES));
        return in_array($value, $valid, true) ? $value : 'disabled';
    }

    /**
     * Echoes the stock widget automatically on single product pages.
     * Called by the woocommerce_single_product_summary action.
     *
     * @return void
     */
    public function render_auto_display() {
        if (!self::is_enabled() || !is_product()) {
            return;
        }

        $product_id = get_the_ID();
        if (!$product_id) {
            return;
        }

        $html = (self::get_display_mode() === 'per_warehouse')
            ? $this->render_per_warehouse($product_id)
            : $this->render_combined($product_id);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside render methods
        echo $html;
    }

    /**
     * Whether the frontend stock display is enabled.
     *
     * @return bool
     */
    public static function is_enabled() {
        return (bool) get_option('dsn_woo_powerall_frontend_stock_enabled', false);
    }

    /**
     * The selected display mode: 'combined' or 'per_warehouse'.
     *
     * @return string
     */
    public static function get_display_mode() {
        $mode = get_option('dsn_woo_powerall_frontend_stock_display', 'combined');
        return in_array($mode, array('combined', 'per_warehouse'), true) ? $mode : 'combined';
    }

    /**
     * Enqueue frontend assets only when the feature is enabled.
     *
     * @return void
     */
    public function enqueue_assets() {
        if (!self::is_enabled()) {
            return;
        }

        wp_enqueue_style(
            'dsn-stock-display',
            plugins_url('../assets/css/stock-display.css', __FILE__),
            array(),
            DSN_WOO_POWERALL_VERSION
        );

        wp_enqueue_script(
            'dsn-stock-display',
            plugins_url('../assets/js/stock-display.js', __FILE__),
            array(),
            DSN_WOO_POWERALL_VERSION,
            true
        );
    }

    /**
     * Shortcode callback.
     *
     * @param array $atts
     * @return string
     */
    public function render_shortcode($atts) {
        if (!self::is_enabled()) {
            return '';
        }

        $atts       = shortcode_atts(array('id' => 0), $atts, 'dsn_powerall_stock');
        $product_id = $atts['id'] ? (int) $atts['id'] : get_the_ID();

        if (!$product_id) {
            return '';
        }

        if (self::get_display_mode() === 'per_warehouse') {
            return $this->render_per_warehouse($product_id);
        }

        return $this->render_combined($product_id);
    }

    /**
     * Render the combined (total) stock view.
     *
     * @param int $product_id
     * @return string
     */
    private function render_combined($product_id) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->managing_stock()) {
            return '';
        }

        $stock = $product->get_stock_quantity();
        if ($stock === null) {
            return '';
        }

        ob_start();
        ?>
        <div class="dsn-stock dsn-stock--combined">
            <span class="dsn-stock__label"><?php esc_html_e('Available:', 'dsn-woo-powerall'); ?></span>
            <span class="dsn-stock__value"><?php echo esc_html(wc_stock_amount($stock)); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the per-warehouse stock list, with a "Show more" modal when >3 locations.
     *
     * @param int $product_id
     * @return string
     */
    private function render_per_warehouse($product_id) {
        $raw = get_post_meta($product_id, self::META_KEY, true);

        if (!$raw) {
            return $this->render_combined($product_id);
        }

        $warehouses = json_decode($raw, true);
        if (!is_array($warehouses) || empty($warehouses)) {
            return $this->render_combined($product_id);
        }

        $mode      = Stock_Helper::get_selected_mode();
        $locations = array();

        foreach ($warehouses as $wh) {
            $locations[] = array(
                'name'  => $wh['WarehouseName'] ?? $wh['WarehouseCode'] ?? __('Location', 'dsn-woo-powerall'),
                'stock' => $this->warehouse_stock_for_mode($wh, $mode),
            );
        }

        $preview   = array_slice($locations, 0, self::PREVIEW_LIMIT);
        $remaining = array_slice($locations, self::PREVIEW_LIMIT);
        $modal_id  = 'dsn-stock-modal-' . $product_id;

        ob_start();
        ?>
        <div class="dsn-stock dsn-stock--per-warehouse">
            <ul class="dsn-stock__list">
                <?php foreach ($preview as $loc) : ?>
                <li class="dsn-stock__item">
                    <span class="dsn-stock__location"><?php echo esc_html($loc['name']); ?></span>
                    <span class="dsn-stock__value"><?php echo esc_html($loc['stock']); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if (!empty($remaining)) : ?>
            <button type="button"
                    class="dsn-stock__show-more"
                    data-modal="<?php echo esc_attr($modal_id); ?>">
                <?php
                printf(
                    /* translators: %d: number of additional warehouse locations */
                    esc_html__('Show more (%d more locations)', 'dsn-woo-powerall'),
                    count($remaining)
                );
                ?>
            </button>

            <div id="<?php echo esc_attr($modal_id); ?>"
                 class="dsn-stock-modal"
                 role="dialog"
                 aria-modal="true"
                 aria-label="<?php esc_attr_e('All warehouse locations', 'dsn-woo-powerall'); ?>"
                 hidden>
                <div class="dsn-stock-modal__backdrop"></div>
                <div class="dsn-stock-modal__content">
                    <button type="button"
                            class="dsn-stock-modal__close"
                            aria-label="<?php esc_attr_e('Close', 'dsn-woo-powerall'); ?>">
                        &times;
                    </button>
                    <h3 class="dsn-stock-modal__title"><?php esc_html_e('All locations', 'dsn-woo-powerall'); ?></h3>
                    <ul class="dsn-stock__list">
                        <?php foreach ($locations as $loc) : ?>
                        <li class="dsn-stock__item">
                            <span class="dsn-stock__location"><?php echo esc_html($loc['name']); ?></span>
                            <span class="dsn-stock__value"><?php echo esc_html($loc['stock']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Extract the relevant stock value from a raw Powerall warehouse entry
     * based on the currently active stock tracking mode.
     *
     * @param array  $wh   Raw warehouse array from Powerall.
     * @param string $mode Stock tracking mode key.
     * @return float|int
     */
    private function warehouse_stock_for_mode(array $wh, string $mode) {
        $free        = floatval($wh['FreeStock'] ?? 0);
        $economical  = floatval($wh['EconomicalStock'] ?? 0);
        $shelf       = floatval($wh['ShelfStock'] ?? 0);

        switch ($mode) {
            case 'EconomicalStock':
                return wc_stock_amount($economical);
            case 'ShelfStock':
                return wc_stock_amount($shelf);
            case 'FreeStock_ShelfStock':
                return wc_stock_amount($free + $shelf);
            case 'all_combined':
                return wc_stock_amount($economical + $free + $shelf);
            case 'FreeStock':
            default:
                return wc_stock_amount($free);
        }
    }
}
