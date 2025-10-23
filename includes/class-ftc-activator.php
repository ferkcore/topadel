<?php
/**
 * Plugin activation handler.
 *
 * @package Ferk_Topten_Connector\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/db/class-ftc-db.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';

/**
 * Handles plugin activation tasks.
 */
class FTC_Activator {
    /**
     * Activate the plugin.
     */
    public static function activate() {
        FTC_DB::create_tables();
        FTC_Logger::instance()->info( 'activation', __( 'Ferk Topten Connector activated.', 'ferk-topten-connector' ) );
    }
}
