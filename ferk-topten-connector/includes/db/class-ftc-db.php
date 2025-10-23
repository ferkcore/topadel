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
     * Cached table name.
     *
     * @var string|null
     */
    protected $maps_table = null;

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
     * Find mapping row.
     *
     * @param string      $entity_type    Entity type.
     * @param int         $wc_id          WooCommerce identifier.
     * @param string|null $email_fallback Optional email fallback for customers.
     *
     * @return array|null
     */
    public function find_map( $entity_type, $wc_id, $email_fallback = null ) {
        global $wpdb;

        $entity_type = sanitize_key( $entity_type );
        $wc_id       = absint( $wc_id );
        $table       = $this->get_maps_table();

        if ( $wc_id > 0 ) {
            $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE entity_type = %s AND wc_id = %d",
                    $entity_type,
                    $wc_id
                ),
                ARRAY_A
            );

            if ( $row ) {
                return $row;
            }
        }

        if ( null !== $email_fallback ) {
            $hash = is_email( $email_fallback ) ? md5( strtolower( $email_fallback ) ) : sanitize_text_field( $email_fallback );

            if ( ! empty( $hash ) ) {
                $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE entity_type = %s AND hash = %s",
                        $entity_type,
                        $hash
                    ),
                    ARRAY_A
                );

                if ( $row ) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Insert or update map row.
     *
     * @param string $entity_type Entity type.
     * @param int    $wc_id       WooCommerce ID.
     * @param mixed  $external_id External identifier.
     * @param array  $args        Extra arguments.
     */
    public function upsert_map( $entity_type, $wc_id, $external_id, $args = array() ) {
        global $wpdb;

        $entity_type = sanitize_key( $entity_type );
        $wc_id       = absint( $wc_id );
        $hash        = '';

        if ( isset( $args['hash'] ) ) {
            $hash = sanitize_text_field( $args['hash'] );
        } elseif ( isset( $args['email'] ) && is_email( $args['email'] ) ) {
            $hash = md5( strtolower( $args['email'] ) );
        }

        $data_json = null;
        if ( isset( $args['data_json'] ) ) {
            $data_json = is_scalar( $args['data_json'] ) ? (string) $args['data_json'] : wp_json_encode( $args['data_json'] );
        } elseif ( isset( $args['data'] ) ) {
            $data_json = wp_json_encode( $args['data'] );
        }

        $now   = current_time( 'mysql', true );
        $table = $this->get_maps_table();

        $wpdb->replace(
            $table,
            array(
                'entity_type' => $entity_type,
                'wc_id'       => $wc_id,
                'external_id' => (string) $external_id,
                'hash'        => ! empty( $hash ) ? $hash : null,
                'data_json'   => $data_json,
                'created_at'  => $now,
                'updated_at'  => $now,
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

    /**
     * Get maps table name.
     *
     * @return string
     */
    protected function get_maps_table() {
        if ( null === $this->maps_table ) {
            global $wpdb;
            $this->maps_table = $wpdb->prefix . 'ftc_maps';
        }

        return $this->maps_table;
    }
}
