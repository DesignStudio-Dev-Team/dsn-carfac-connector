<?php
namespace DSNWooPowerall;

class Stock_Helper {
    public const DEFAULT_MODE = 'FreeStock';
    public const KNOWN_WAREHOUSES_OPTION = 'dsn_woo_powerall_known_warehouses';
    public const EXCLUDED_WAREHOUSES_OPTION = 'dsn_woo_powerall_excluded_warehouses';

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

            if (!self::is_warehouse_included($warehouse_stock)) {
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

    /**
     * Extract a warehouse's display name (Description) from a raw Powerall warehouse entry.
     *
     * @param array $warehouse
     * @return string
     */
    public static function get_warehouse_name(array $warehouse) {
        $raw = $warehouse['Warehouse']['Description']
            ?? $warehouse['WarehouseName']
            ?? ($warehouse['Warehouse']['WarehouseCode'] ?? null)
            ?? ($warehouse['WarehouseCode'] ?? '');

        return trim((string) $raw);
    }

    /**
     * Warehouse Descriptions that the admin has excluded from stock calculations.
     *
     * @return array<int, string>
     */
    public static function get_excluded_warehouses() {
        $list = get_option(self::EXCLUDED_WAREHOUSES_OPTION, array());
        if (!is_array($list)) {
            return array();
        }

        return array_values(array_filter(array_map('strval', $list), function ($v) {
            return $v !== '';
        }));
    }

    /**
     * Warehouse Descriptions seen across products, used to populate the admin settings UI.
     *
     * @return array<int, string>
     */
    public static function get_known_warehouses() {
        $list = get_option(self::KNOWN_WAREHOUSES_OPTION, array());
        if (!is_array($list)) {
            return array();
        }

        $list = array_values(array_unique(array_filter(array_map('strval', $list), function ($v) {
            return $v !== '';
        })));
        sort($list);

        return $list;
    }

    /**
     * Whether a warehouse entry counts toward stock totals / frontend display.
     *
     * @param array $warehouse
     * @return bool
     */
    public static function is_warehouse_included(array $warehouse) {
        $name = self::get_warehouse_name($warehouse);
        if ($name === '') {
            return true;
        }

        return !in_array($name, self::get_excluded_warehouses(), true);
    }

    /**
     * Add any previously unseen warehouse descriptions from a product payload to the known list.
     *
     * @param array $stock_per_warehouse
     * @return void
     */
    public static function register_known_warehouses(array $stock_per_warehouse) {
        $known  = self::get_known_warehouses();
        $before = $known;

        foreach ($stock_per_warehouse as $warehouse) {
            if (!is_array($warehouse)) {
                continue;
            }
            $name = self::get_warehouse_name($warehouse);
            if ($name !== '' && !in_array($name, $known, true)) {
                $known[] = $name;
            }
        }

        if ($known !== $before) {
            sort($known);
            update_option(self::KNOWN_WAREHOUSES_OPTION, $known);
        }
    }

    /**
     * Rebuild the known warehouses list by scanning persisted product meta.
     *
     * @return int Number of warehouses discovered.
     */
    public static function scan_known_warehouses() {
        global $wpdb;

        $names = array();

        if ($wpdb) {
            $rows = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_powerall_stock_warehouses'");
            if (is_array($rows)) {
                foreach ($rows as $raw) {
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    foreach ($decoded as $warehouse) {
                        if (!is_array($warehouse)) {
                            continue;
                        }
                        $name = self::get_warehouse_name($warehouse);
                        if ($name !== '' && !in_array($name, $names, true)) {
                            $names[] = $name;
                        }
                    }
                }
            }
        }

        sort($names);
        update_option(self::KNOWN_WAREHOUSES_OPTION, $names);

        return count($names);
    }
}
