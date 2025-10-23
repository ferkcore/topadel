<?php
/**
 * Plugin deactivation handler.
 *
 * @package Ferk_Topten_Connector\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
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
        self::clear_token_refresh();
    }

    /**
     * Clear scheduled auth token tasks.
     */
    protected static function clear_token_refresh() {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'ftc_auth_token_refresh' );
        }

        wp_clear_scheduled_hook( 'ftc_auth_token_refresh' );
    }
}
