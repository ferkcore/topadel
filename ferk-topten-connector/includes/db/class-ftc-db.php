<?php
/**
 * Database helpers.
 *
 * @package Ferk_Topten_Connector\Includes\DB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';

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

    /**
     * Find map entry.
     *
     * @param string     $entity_type   Entity type.
     * @param int        $wc_id         WooCommerce identifier.
     * @param string|int $emailFallback Email fallback.
     *
     * @return array|null
     */
    public function find_map( $entity_type, $wc_id, $emailFallback = null ) {
        global $wpdb;

        $table       = $wpdb->prefix . 'ftc_maps';
        $entity_type = sanitize_key( $entity_type );
        $wc_id       = (int) $wc_id;

        if ( $wc_id > 0 ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE entity_type = %s AND wc_id = %d LIMIT 1",
                    $entity_type,
                    $wc_id
                ),
                ARRAY_A
            );

            if ( $row ) {
                return $row;
            }
        }

        $emailFallback = is_string( $emailFallback ) ? trim( $emailFallback ) : '';
        if ( '' === $emailFallback ) {
            return null;
        }

        $hash = FTC_Utils::hash_identity( $emailFallback );
        if ( '' === $hash ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE entity_type = %s AND hash = %s LIMIT 1",
                $entity_type,
                $hash
            ),
            ARRAY_A
        );

        return $row ? $row : null;
    }

    /**
     * Upsert map entry.
     *
     * @param string $entity_type Entity type.
     * @param int    $wc_id       WooCommerce identifier.
     * @param string $external_id External identifier.
     * @param string $hash        Hash of identity.
     * @param array  $meta        Additional metadata.
     */
    public function upsert_map( $entity_type, $wc_id, $external_id, $hash, $meta = array() ) {
        global $wpdb;

        $table       = $wpdb->prefix . 'ftc_maps';
        $entity_type = sanitize_key( $entity_type );
        $wc_id       = (int) $wc_id;
        $hash        = $hash ? substr( (string) $hash, 0, 64 ) : null;
        $external_id = substr( (string) $external_id, 0, 64 );

        $data_json = ! empty( $meta ) ? wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) : null;

        $wpdb->replace(
            $table,
            array(
                'entity_type' => $entity_type,
                'wc_id'       => $wc_id,
                'external_id' => $external_id,
                'hash'        => $hash,
                'data_json'   => $data_json,
                'created_at'  => current_time( 'mysql', true ),
                'updated_at'  => current_time( 'mysql', true ),
            ),
            array(
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );
    }
}
