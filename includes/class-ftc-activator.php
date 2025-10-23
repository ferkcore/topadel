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
        self::schedule_token_refresh();
    }

    /**
     * Schedule auth token refresh task.
     */
    protected static function schedule_token_refresh() {
        if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
            if ( ! as_has_scheduled_action( 'ftc_auth_token_refresh' ) ) {
                as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 'ftc_auth_token_refresh' );
            }

            return;
        }

        if ( ! wp_next_scheduled( 'ftc_auth_token_refresh' ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ftc_half_hour', 'ftc_auth_token_refresh' );
        }
    }
}
