<?php
/**
 * Logger helper for the plugin.
 *
 * @package Ferk_Topten_Connector\Includes\Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simple logger that writes into the custom database table.
 */
class FTC_Logger {
    /**
     * Singleton instance.
     *
     * @var FTC_Logger|null
     */
    protected static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return FTC_Logger
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Write debug message.
     *
     * @param string $context Context string.
     * @param string $message Message.
     * @param array  $payload Optional payload.
     */
    public function debug( $context, $message, $payload = array() ) {
        $this->log( 'debug', $context, $message, $payload );
    }

    /**
     * Write info message.
     *
     * @param string $context Context string.
     * @param string $message Message.
     * @param array  $payload Optional payload.
     */
    public function info( $context, $message, $payload = array() ) {
        $this->log( 'info', $context, $message, $payload );
    }

    /**
     * Write warn message.
     *
     * @param string $context Context string.
     * @param string $message Message.
     * @param array  $payload Optional payload.
     */
    public function warn( $context, $message, $payload = array() ) {
        $this->log( 'warn', $context, $message, $payload );
    }

    /**
     * Write error message.
     *
     * @param string $context Context string.
     * @param string $message Message.
     * @param array  $payload Optional payload.
     */
    public function error( $context, $message, $payload = array() ) {
        $this->log( 'error', $context, $message, $payload );
    }

    /**
     * Log message in database and error log when debug.
     *
     * @param string $level   Level.
     * @param string $context Context.
     * @param string $message Message.
     * @param array  $payload Payload.
     */
    public function log( $level, $context, $message, $payload = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ftc_logs';
        $wpdb->insert(
            $table,
            array(
                'level'        => substr( sanitize_key( $level ), 0, 10 ),
                'context'      => substr( sanitize_text_field( $context ), 0, 50 ),
                'message'      => wp_kses_post( $message ),
                'payload_json' => ! empty( $payload ) ? wp_json_encode( $this->mask_payload( $payload ) ) : null,
                'created_at'   => current_time( 'mysql', true ),
            )
        );

        $settings  = get_option( FTC_Utils::option_name(), array() );
        $debug_mode = ( 'yes' === FTC_Utils::array_get( $settings, 'credentials.debug_mode', 'no' ) );

        if ( $debug_mode ) {
            error_log( sprintf( '[FTC:%s] %s - %s', strtoupper( $level ), $context, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Mask payload removing secrets.
     *
     * @param array $payload Payload.
     *
     * @return array
     */
    protected function mask_payload( $payload ) {
        foreach ( $payload as $key => $value ) {
            $lower = strtolower( (string) $key );
            if ( is_array( $value ) ) {
                $payload[ $key ] = $this->mask_payload( $value );
            } elseif ( false !== strpos( $lower, 'secret' ) || false !== strpos( $lower, 'key' ) ) {
                $payload[ $key ] = '***';
            }
        }

        return $payload;
    }
}
