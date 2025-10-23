<?php
/**
 * Plugin Name:       Ferk Topten Connector
 * Plugin URI:        https://example.com/
 * Description:       Integrates WooCommerce with the TopTen platform and GetNet payments.
 * Version:           0.2.1
 * Author:            Ferk
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * Text Domain:       ferk-topten-connector
 * Domain Path:       /languages
 *
 * @package Ferk_Topten_Connector
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FTC_PLUGIN_FILE' ) ) {
define( 'FTC_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'FTC_PLUGIN_DIR' ) ) {
define( 'FTC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'FTC_PLUGIN_URL' ) ) {
define( 'FTC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'FTC_PLUGIN_VERSION' ) ) {
define( 'FTC_PLUGIN_VERSION', '0.2.1' );
}

/**
 * Load plugin textdomain.
 */
function ftc_load_textdomain() {
load_plugin_textdomain( 'ferk-topten-connector', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ftc_load_textdomain' );

require_once FTC_PLUGIN_DIR . 'includes/class-ftc-activator.php';
require_once FTC_PLUGIN_DIR . 'includes/class-ftc-deactivator.php';
require_once FTC_PLUGIN_DIR . 'includes/class-ftc-plugin.php';

register_activation_hook( __FILE__, array( 'FTC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FTC_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'FTC_Plugin', 'get_instance' ) );
