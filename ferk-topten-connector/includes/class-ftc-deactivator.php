<?php
/**
 * Plugin deactivation handler.
 *
 * @package Ferk_Topten_Connector\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';

/**
 * Handles plugin deactivation tasks.
 */
class FTC_Deactivator {
    /**
     * Deactivate the plugin.
     */
    public static function deactivate() {
        FTC_Logger::instance()->info( 'deactivation', __( 'Ferk Topten Connector deactivated.', 'ferk-topten-connector' ) );
    }
}
