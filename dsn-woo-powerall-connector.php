<?php
/**
 * Plugin Name: DSN WooCommerce to Powerall Connector
 * Plugin URI: https://designstudionetwork.com
 * Description: Connects WooCommerce with Powerall CRM for product and order synchronization
 * Version: 1.1.2
 * Author: DesignStudio Network Inc
 * Author URI: https://designstudionetwork.com
 * Text Domain: dsn-woo-powerall
 * Domain Path: /languages
 * Requires at least: 6.8.0
 * Requires PHP: 8.1
 * WC requires at least: 9.8.5
 * WC tested up to: 9.8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
// Define plugin constants
define('DSN_WOO_POWERALL_VERSION', '1.1.2');
define('DSN_WOO_POWERALL_PLUGIN_FILE', __FILE__);
define('DSN_WOO_POWERALL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DSN_WOO_POWERALL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    // Check if the class is in our namespace
    if (strpos($class, 'DSNWooPowerall\\') !== 0) {
        return;
    }

    // Remove namespace from class name
    $class = str_replace('DSNWooPowerall\\', '', $class);

    // Convert class name to file name
    $file = str_replace('_', '-', strtolower($class));
    $file = DSN_WOO_POWERALL_PLUGIN_DIR . 'includes/class-' . $file . '.php';

    // Load the file if it exists
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize plugin
function dsn_woo_powerall_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="error">
                <p><?php _e('DSN Woo To Powerall requires WooCommerce to be installed and active.', 'dsn-woo-powerall'); ?></p>
            </div>
            <?php
        });
        return;
    }

    // Initialize HPOS compatibility
    new \DSNWooPowerall\HPOS_Compatibility();

    // Initialize main plugin class
    $plugin = new \DSNWooPowerall\DSN_Woo_Powerall();
    $plugin->init();
}
add_action('plugins_loaded', 'dsn_woo_powerall_init');

/**
 * Load plugin text domain so translations in /languages are applied.
 * Must run on 'init' — running it on 'plugins_loaded' is too early for
 * some translation plugins (e.g. WPML, Loco Translate).
 */
function dsn_woo_powerall_load_textdomain() {
    load_plugin_textdomain(
        'dsn-woo-powerall',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}
add_action( 'init', 'dsn_woo_powerall_load_textdomain' );

/**
 * When WPML switches the active language it may do so after the textdomain
 * has already been loaded with the previous locale. Unload and reload so the
 * correct .mo file is used for the new language.
 */
add_action( 'wpml_language_has_switched', function () {
    unload_textdomain( 'dsn-woo-powerall' );
    dsn_woo_powerall_load_textdomain();
} );

// Activation hook
register_activation_hook(__FILE__, function() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('DSN Woo To Powerall requires PHP 8.1 or higher.', 'dsn-woo-powerall'));
    }

    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '6.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('DSN Woo To Powerall requires WordPress 6.0 or higher.', 'dsn-woo-powerall'));
    }

    // Check WooCommerce version
    if (version_compare(WC_VERSION, '9.8.5', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('DSN Woo To Powerall requires WooCommerce 9.8.5 or higher.', 'dsn-woo-powerall'));
    }
}); 