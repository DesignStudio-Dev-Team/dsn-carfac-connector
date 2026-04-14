<?php
namespace DSNWooPowerall;

class Stock_Helper {
    public const DEFAULT_MODE = 'FreeStock';

    /**
     * Get the supported stock tracking modes.
     *
     * @return array<string, string>
     */
    public static function get_available_modes() {
        return array(
            'FreeStock' => __('FreeStock Only', 'dsn-woo-powerall'),
            'EconomicalStock' => __('EconomicalStock Only', 'dsn-woo-powerall'),
            'ShelfStock' => __('ShelfStock Only', 'dsn-woo-powerall'),
            'FreeStock_ShelfStock' => __('FreeStock + ShelfStock', 'dsn-woo-powerall'),
            'all_combined' => __('All Combined (EconomicalStock + FreeStock + ShelfStock)', 'dsn-woo-powerall'),
        );
    }

    /**
     * Normalize the selected stock tracking mode.
     *
     * @param string $mode Mode from settings or runtime.
     * @return string
     */
    public static function normalize_mode($mode) {
        $modes = self::get_available_modes();

        return isset($modes[$mode]) ? $mode : self::DEFAULT_MODE;
    }

    /**
     * Get the currently selected stock tracking mode.
     *
     * @return string
     */
    public static function get_selected_mode() {
        return self::normalize_mode(get_option('dsn_woo_powerall_stock_tracking_mode', self::DEFAULT_MODE));
    }

    /**
     * Normalize a single warehouse stock payload.
     *
     * @param array $warehouse_stock
     * @return array<string, float>
     */
    public static function normalize_warehouse_stock(array $warehouse_stock) {
        return array(
            'EconomicalStock' => floatval($warehouse_stock['EconomicalStock'] ?? 0),
            'FreeStock' => floatval($warehouse_stock['FreeStock'] ?? 0),
            'ShelfStock' => floatval($warehouse_stock['ShelfStock'] ?? 0),
        );
    }

    /**
     * Calculate total stock for a set of warehouses.
     *
     * @param array $stock_per_warehouse
     * @param string|null $mode
     * @return float
     */
    public static function calculate_total_stock(array $stock_per_warehouse, $mode = null) {
        $mode = self::normalize_mode($mode ?: self::get_selected_mode());
        $total_stock = 0.0;

        foreach ($stock_per_warehouse as $warehouse_stock) {
            if (!is_array($warehouse_stock)) {
                continue;
            }

            $normalized_stock = self::normalize_warehouse_stock($warehouse_stock);

            switch ($mode) {
                case 'EconomicalStock':
                    $total_stock += $normalized_stock['EconomicalStock'];
                    break;
                case 'ShelfStock':
                    $total_stock += $normalized_stock['ShelfStock'];
                    break;
                case 'FreeStock_ShelfStock':
                    $total_stock += $normalized_stock['FreeStock'] + $normalized_stock['ShelfStock'];
                    break;
                case 'all_combined':
                    $total_stock += $normalized_stock['EconomicalStock'] + $normalized_stock['FreeStock'] + $normalized_stock['ShelfStock'];
                    break;
                case 'FreeStock':
                default:
                    $total_stock += $normalized_stock['FreeStock'];
                    break;
            }
        }

        return $total_stock;
    }

    /**
     * Calculate total stock directly from product data returned by Powerall.
     *
     * @param array $product_data
     * @param string|null $mode
     * @return float
     */
    public static function calculate_total_stock_from_product_data(array $product_data, $mode = null) {
        if (empty($product_data['StockPerWarehouse']) || !is_array($product_data['StockPerWarehouse'])) {
            return 0.0;
        }

        return self::calculate_total_stock($product_data['StockPerWarehouse'], $mode);
    }

    /**
     * Normalize the quantity before saving it to WooCommerce.
     *
     * @param float|int|string $quantity
     * @return float|int
     */
    public static function format_stock_quantity($quantity) {
        $quantity = floatval($quantity);

        if (function_exists('wc_stock_amount')) {
            return wc_stock_amount($quantity);
        }

        return $quantity;
    }
}
