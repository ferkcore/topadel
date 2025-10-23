<?php
/**
 * General utilities for the plugin.
 *
 * @package Ferk_Topten_Connector\Includes\Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Utility helpers.
 */
class FTC_Utils {
    /**
     * Generate a UUID v4 string.
     *
     * @return string
     */
    public static function uuid_v4() {
        $data = wp_generate_uuid4();

        return is_string( $data ) ? $data : wp_generate_uuid4();
    }

    /**
     * Sanitize checkbox value.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    public static function sanitize_checkbox( $value ) {
        return ( ! empty( $value ) ) ? 'yes' : 'no';
    }

    /**
     * Sanitize text field.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    public static function sanitize_text( $value ) {
        return is_scalar( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
    }

    /**
     * Sanitize textarea field.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    public static function sanitize_textarea( $value ) {
        return is_scalar( $value ) ? wp_kses_post( wp_unslash( $value ) ) : '';
    }

    /**
     * Sanitize integer.
     *
     * @param mixed $value Raw value.
     *
     * @return int
     */
    public static function sanitize_int( $value ) {
        return absint( $value );
    }

    /**
     * Returns array value using dot notation fallback.
     *
     * @param array  $array   Array.
     * @param string $key     Key.
     * @param mixed  $default Default.
     *
     * @return mixed
     */
    public static function array_get( $array, $key, $default = null ) {
        if ( isset( $array[ $key ] ) ) {
            return $array[ $key ];
        }

        $segments = explode( '.', (string) $key );
        foreach ( $segments as $segment ) {
            if ( is_array( $array ) && array_key_exists( $segment, $array ) ) {
                $array = $array[ $segment ];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * Return plugin option name.
     *
     * @return string
     */
    public static function option_name() {
        return 'ftc_settings';
    }
}
