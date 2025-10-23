<?php
/**
 * Database helpers.
 *
 * @package Ferk_Topten_Connector\Includes\DB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles table creation.
 */
class FTC_DB {
    /**
     * Create plugin tables if they do not exist.
     */
    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $maps_table      = $wpdb->prefix . 'ftc_maps';
        $logs_table      = $wpdb->prefix . 'ftc_logs';

        $sql = "CREATE TABLE {$maps_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(20) NOT NULL,
            wc_id bigint(20) unsigned NOT NULL DEFAULT 0,
            external_id varchar(64) NOT NULL,
            hash varchar(64) DEFAULT NULL,
            data_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entity_wc (entity_type, wc_id),
            KEY external_id (external_id)
        ) {$charset_collate};";

        dbDelta( $sql );

        $sql_logs = "CREATE TABLE {$logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(10) NOT NULL,
            context varchar(50) NOT NULL,
            message text NULL,
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        dbDelta( $sql_logs );
    }
}
