<?php
/**
 * Plugin Name: DSN Carfac
 * Plugin URI: https://designstudio.com
 * Description: Connects WooCommerce with Carfac for products and orders synchronization
 * Version: 1.1.0
 * Author: DesignStudio Network Inc
 * Author URI: https://designstudio.com
 * Text Domain: dsn-carfac
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
define('DSN_CARFAC_VERSION', '1.1.0');
define('DSN_CARFAC_PLUGIN_FILE', __FILE__);
define('DSN_CARFAC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DSN_CARFAC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    // Check if the class is in our namespace
    if (strpos($class, 'DSNCarfac\\') !== 0) {
        return;
    }

    // Remove namespace from class name
    $class = str_replace('DSNCarfac\\', '', $class);

    // Convert class name to file name
    $file = str_replace('_', '-', strtolower($class));
    $file = DSN_CARFAC_PLUGIN_DIR . 'includes/class-' . $file . '.php';

    // Load the file if it exists
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize plugin
function DSN_CARFAC_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="error">
                <p><?php _e('DSN Carfac requires WooCommerce to be installed and active.', 'dsn-carfac'); ?></p>
            </div>
            <?php
        });
        return;
    }

    // Initialize HPOS compatibility
    new \DSNCarfac\HPOS_Compatibility();

    // Initialize main plugin class
    $plugin = new \DSNCarfac\DSN_CARFAC();
    $plugin->init();
}
add_action('plugins_loaded', 'DSN_CARFAC_init');

// Activation hook
register_activation_hook(__FILE__, function() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('DSN Carfac requires PHP 8.1 or higher.', 'dsn-carfac'));
    }

    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '6.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('DSN Carfac requires WordPress 6.0 or higher.', 'dsn-carfac'));
    }

    // Check WooCommerce version
    if (version_compare(WC_VERSION, '9.8.5', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('DSN Carfac requires WooCommerce 9.8.5 or higher.', 'dsn-carfac'));
    }
}); 
