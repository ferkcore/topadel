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
     * Default TopTen entity id.
     */
    const FTCTOPTEN_ENTITY_ID = 51;

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

    /**
     * Generate random password.
     *
     * @param int $length Length.
     *
     * @return string
     */
    public static function random_password( $length = 24 ) {
        $length = max( 8, (int) $length );

        return wp_generate_password( $length, true, true );
    }

    /**
     * Hash identity (email) for lookup.
     *
     * @param string $email Email.
     *
     * @return string
     */
    public static function hash_identity( $email ) {
        $normalized = trim( (string) $email );
        if ( function_exists( 'mb_strtolower' ) ) {
            $normalized = mb_strtolower( $normalized, 'UTF-8' );
        } else {
            $normalized = strtolower( $normalized );
        }

        return sha1( $normalized );
    }

    /**
     * Split raw phone into DDI and local number.
     *
     * @param string $raw_phone Raw phone.
     * @param string $country   Country ISO2.
     *
     * @return array
     */
    public static function split_phone( $raw_phone, $country ) {
        $raw_phone = trim( (string) $raw_phone );
        $country   = strtoupper( trim( (string) $country ) );

        if ( '' === $raw_phone ) {
            return array( '', '' );
        }

        $clean = preg_replace( '/[\s\-\(\)]/', '', $raw_phone );

        if ( 0 === strpos( $clean, '+' ) ) {
            if ( preg_match( '/^\+(\d{1,4})(\d*)$/', $clean, $matches ) ) {
                $ddi   = '+' . $matches[1];
                $local = $matches[2];

                return array( $ddi, $local );
            }
        }

        $map = apply_filters(
            'ftc_topten_country_ddi_map',
            array(
                'UY' => '+598',
                'AR' => '+54',
                'BR' => '+55',
            )
        );

        $ddi = isset( $map[ $country ] ) ? (string) $map[ $country ] : '';
        $local = preg_replace( '/\D+/', '', $clean );

        return array( $ddi, $local );
    }

    /**
     * Normalise date/datetime to ISO8601 without timezone.
     *
     * @param mixed $value Value.
     *
     * @return string|null
     */
    public static function normalize_datetime_nullable( $value ) {
        if ( null === $value ) {
            return null;
        }

        $value = trim( (string) $value );
        if ( '' === $value ) {
            return null;
        }

        if ( is_numeric( $value ) ) {
            $timestamp = (int) $value;
            if ( $timestamp > 0 && $timestamp < 10000000000 ) {
                return gmdate( 'Y-m-d\TH:i:s', $timestamp );
            }
        }

        $parsed = strtotime( $value );
        if ( false === $parsed ) {
            return null;
        }

        return gmdate( 'Y-m-d\TH:i:s', $parsed );
    }
}
