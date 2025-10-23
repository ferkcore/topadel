<?php
/**
 * Uninstall handler.
 *
 * @package Ferk_Topten_Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$option_name = 'ftc_settings';

delete_option( $option_name );

delete_site_option( $option_name );

$tables = array(
    $wpdb->prefix . 'ftc_maps',
    $wpdb->prefix . 'ftc_logs',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
