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

    /** Number of warehouses shown inline before the "View more" link appears. */
    const PREVIEW_LIMIT = 3;

    /** Product meta key where raw Powerall warehouse data is stored. */
    const META_KEY = '_powerall_stock_warehouses';

    /**
     * WooCommerce woocommerce_single_product_summary hook priorities for each position.
     * Title=5, Rating/Price=10, Excerpt=20, Add to cart=30.
     */
    const POSITION_PRIORITIES = array(
        'below_title'        => 7,
        'after_price'        => 15,
        'before_add_to_cart' => 25,
    );

    public function __construct() {
        add_shortcode( 'dsn_powerall_stock', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        $this->register_auto_display_hook();
    }

    // -------------------------------------------------------------------------
    // Settings helpers
    // -------------------------------------------------------------------------

    public static function is_enabled(): bool {
        return (bool) get_option( 'dsn_woo_powerall_frontend_stock_enabled', false );
    }

    public static function get_display_mode(): string {
        $mode = get_option( 'dsn_woo_powerall_frontend_stock_display', 'combined' );
        return in_array( $mode, array( 'combined', 'per_warehouse' ), true ) ? $mode : 'combined';
    }

    public static function get_auto_position(): string {
        $value = get_option( 'dsn_woo_powerall_frontend_stock_position', 'disabled' );
        $valid = array_merge( array( 'disabled' ), array_keys( self::POSITION_PRIORITIES ) );
        return in_array( $value, $valid, true ) ? $value : 'disabled';
    }

    // -------------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------------

    private function register_auto_display_hook(): void {
        $position = self::get_auto_position();
        if ( $position === 'disabled' || ! isset( self::POSITION_PRIORITIES[ $position ] ) ) {
            return;
        }
        add_action(
            'woocommerce_single_product_summary',
            array( $this, 'render_auto_display' ),
            self::POSITION_PRIORITIES[ $position ]
        );
    }

    public function enqueue_assets(): void {
        if ( ! self::is_enabled() ) {
            return;
        }
        wp_enqueue_style(
            'dsn-stock-display',
            plugins_url( '../assets/css/stock-display.css', __FILE__ ),
            array(),
            DSN_WOO_POWERALL_VERSION
        );
        wp_enqueue_script(
            'dsn-stock-display',
            plugins_url( '../assets/js/stock-display.js', __FILE__ ),
            array(),
            DSN_WOO_POWERALL_VERSION,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Entry points
    // -------------------------------------------------------------------------

    public function render_auto_display(): void {
        if ( ! self::is_enabled() || ! is_product() ) {
            return;
        }
        $product_id = get_the_ID();
        if ( ! $product_id ) {
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render methods
        echo $this->render( $product_id );
    }

    public function render_shortcode( $atts ): string {
        if ( ! self::is_enabled() ) {
            return '';
        }
        $atts       = shortcode_atts( array( 'id' => 0 ), $atts, 'dsn_powerall_stock' );
        $product_id = $atts['id'] ? (int) $atts['id'] : get_the_ID();
        return $product_id ? $this->render( $product_id ) : '';
    }

    private function render( int $product_id ): string {
        return self::get_display_mode() === 'per_warehouse'
            ? $this->render_per_warehouse( $product_id )
            : $this->render_combined( $product_id );
    }

    // -------------------------------------------------------------------------
    // Combined view
    // -------------------------------------------------------------------------

    private function render_combined( int $product_id ): string {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->managing_stock() ) {
            return '';
        }

        $stock      = (int) $product->get_stock_quantity();
        $backorder  = $this->backorder_label( $product, $stock );

        ob_start();
        ?>
        <div class="dsn-stock dsn-stock--combined">
            <?php
            printf(
                /* translators: 1: stock quantity number, 2: backorder notice e.g. " (can be backordered)" or empty string */
                esc_html__( '%1$d Available in stock%2$s', 'dsn-woo-powerall' ),
                $stock,
                $backorder ? ' ' . esc_html( $backorder ) : ''
            );
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Per-warehouse view
    // -------------------------------------------------------------------------

    private function render_per_warehouse( int $product_id ): string {
        // Always read warehouse meta from the original/source product — WPML
        // translated products don't have this meta since sync writes to the original.
        $source_id = $this->get_source_product_id( $product_id );
        $raw       = get_post_meta( $source_id, self::META_KEY, true );

        if ( ! $raw ) {
            return $this->render_combined( $product_id );
        }

        $warehouses = json_decode( $raw, true );
        if ( ! is_array( $warehouses ) || empty( $warehouses ) ) {
            return $this->render_combined( $product_id );
        }

        $product   = wc_get_product( $product_id );
        $mode      = Stock_Helper::get_selected_mode();
        $locations = array();

        foreach ( $warehouses as $wh ) {
            $qty = $this->warehouse_stock_for_mode( $wh, $mode );
            $locations[] = array(
                'name'      => $this->format_warehouse_name( $wh ),
                'stock'     => $qty,
                'backorder' => $product ? $this->backorder_label( $product, (int) $qty ) : '',
            );
        }

        $preview   = array_slice( $locations, 0, self::PREVIEW_LIMIT );
        $remaining = array_slice( $locations, self::PREVIEW_LIMIT );
        $modal_id  = 'dsn-stock-modal-' . $product_id;

        ob_start();
        ?>
        <div class="dsn-stock dsn-stock--per-warehouse">

            <ul class="dsn-stock__list">
                <?php foreach ( $preview as $loc ) : ?>
                <li class="dsn-stock__item">
                    <?php echo $this->location_line( $loc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( ! empty( $remaining ) ) : ?>
            <a href="#<?php echo esc_attr( $modal_id ); ?>"
               class="dsn-stock__view-more"
               data-modal="<?php echo esc_attr( $modal_id ); ?>">
                <?php
                printf(
                    /* translators: %d: number of additional warehouse locations not yet visible. Example: "View 2 more locations" */
                    esc_html__( 'View %d more locations', 'dsn-woo-powerall' ),
                    count( $remaining )
                );
                ?>
            </a>

            <div id="<?php echo esc_attr( $modal_id ); ?>"
                 class="dsn-stock-modal"
                 role="dialog"
                 aria-modal="true"
                 aria-label="<?php
                    /* translators: Accessible name for the modal dialog that lists all warehouse stock locations */
                    esc_attr_e( 'Stock by location', 'dsn-woo-powerall' );
                 ?>"
                 hidden>
                <div class="dsn-stock-modal__backdrop"></div>
                <div class="dsn-stock-modal__box">
                    <button type="button"
                            class="dsn-stock-modal__close"
                            aria-label="<?php
                                /* translators: Accessible label for the × button that closes the stock modal */
                                esc_attr_e( 'Close', 'dsn-woo-powerall' );
                            ?>">
                        &times;
                    </button>
                    <!-- translators: Heading inside the modal listing all warehouse stock locations -->
                    <h3 class="dsn-stock-modal__title"><?php esc_html_e( 'Stock by location', 'dsn-woo-powerall' ); ?></h3>
                    <ul class="dsn-stock__list">
                        <?php foreach ( $locations as $loc ) : ?>
                        <li class="dsn-stock__item">
                            <?php echo $this->location_line( $loc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
     * Build the translatable text line for a single location row.
     * Output: "Herent: <strong>34</strong> Available in stock (can be backordered)"
     *
     * Already fully escaped — safe to echo directly.
     *
     * @param array{name:string, stock:int|float, backorder:string} $loc
     * @return string
     */
    private function location_line( array $loc ): string {
        $name      = esc_html( $loc['name'] );
        $qty       = (int) $loc['stock'];
        $backorder = $loc['backorder'] !== '' ? ' ' . esc_html( $loc['backorder'] ) : '';

        return sprintf(
            /* translators: 1: warehouse/location name, 2: stock quantity number, 3: backorder notice e.g. " (can be backordered)" or empty */
            '<span class="dsn-stock__location-name">%1$s:</span> <strong class="dsn-stock__qty">%2$d</strong> <span class="dsn-stock__label">%3$s%4$s</span>',
            $name,
            $qty,
            esc_html__( 'Available in stock', 'dsn-woo-powerall' ),
            $backorder
        );
    }

    // -------------------------------------------------------------------------
    // WPML helpers
    // -------------------------------------------------------------------------

    /**
     * When WPML is active, return the source-language product ID so meta stored
     * during sync (which runs against the original product) is always found.
     * Falls back to the supplied ID when WPML is not present.
     *
     * @param int $product_id  The current (possibly translated) product ID.
     * @return int
     */
    private function get_source_product_id( int $product_id ): int {
        if ( ! function_exists( 'apply_filters' ) ) {
            return $product_id;
        }

        // `wpml_object_id` filter: returns the ID in the requested language.
        // Passing the default language gives us the original/source post.
        $default_lang = apply_filters( 'wpml_default_language', null );
        if ( $default_lang === null ) {
            // WPML not active.
            return $product_id;
        }

        $original_id = (int) apply_filters( 'wpml_object_id', $product_id, 'product', true, $default_lang );

        return $original_id ?: $product_id;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the backorder notice string when relevant, or an empty string.
     *
     * @param \WC_Product $product
     * @param int         $qty
     * @return string
     */
    private function backorder_label( \WC_Product $product, int $qty ): string {
        $backorders = $product->get_backorders(); // 'no' | 'notify' | 'yes'
        if ( $backorders === 'no' || $qty > 0 ) {
            return '';
        }
        /* translators: Shown after the stock count when the product stock is 0 but backorders are allowed. Include the parentheses. */
        return __( '(can be backordered)', 'dsn-woo-powerall' );
    }

    /**
     * Resolve a human-readable warehouse name from a raw Powerall warehouse entry.
     * Prefers Warehouse.Description, falls back to WarehouseCode, then a generic label.
     * Converts ALL-CAPS strings to title case (e.g. "HERENT" → "Herent").
     *
     * @param array $wh Raw warehouse entry from Powerall.
     * @return string
     */
    private function format_warehouse_name( array $wh ): string {
        $raw = $wh['Warehouse']['Description']
            ?? $wh['WarehouseName']
            ?? ( isset( $wh['Warehouse']['WarehouseCode'] ) ? (string) $wh['Warehouse']['WarehouseCode'] : null )
            ?? $wh['WarehouseCode']
            /* translators: Fallback label for a Powerall warehouse when no description or code is available */
            ?? __( 'Location', 'dsn-woo-powerall' );

        $raw = trim( (string) $raw );

        // If the string is entirely uppercase (e.g. "HERENT"), convert to title case.
        if ( $raw === mb_strtoupper( $raw, 'UTF-8' ) ) {
            return mb_convert_case( $raw, MB_CASE_TITLE, 'UTF-8' );
        }

        return $raw;
    }

    /**
     * Extract the stock value for the active tracking mode from a raw warehouse entry.
     *
     * @param array  $wh   Raw warehouse array from Powerall.
     * @param string $mode Stock tracking mode key.
     * @return float|int
     */
    private function warehouse_stock_for_mode( array $wh, string $mode ) {
        $free       = floatval( $wh['FreeStock'] ?? 0 );
        $economical = floatval( $wh['EconomicalStock'] ?? 0 );
        $shelf      = floatval( $wh['ShelfStock'] ?? 0 );

        switch ( $mode ) {
            case 'EconomicalStock':
                return wc_stock_amount( $economical );
            case 'ShelfStock':
                return wc_stock_amount( $shelf );
            case 'FreeStock_ShelfStock':
                return wc_stock_amount( $free + $shelf );
            case 'all_combined':
                return wc_stock_amount( $economical + $free + $shelf );
            case 'FreeStock':
            default:
                return wc_stock_amount( $free );
        }
    }
}
