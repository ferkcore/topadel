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
     * Generate a cryptographically secure password.
     *
     * @param int   $length  Desired length.
     * @param array $options Generation options.
     *
     * @return string
     */
    public static function random_password( $length = 12, $options = array() ) {
        $length  = max( 1, (int) $length );
        $options = wp_parse_args(
            is_array( $options ) ? $options : array(),
            array(
                'require_uppercase' => false,
                'require_lowercase' => false,
                'require_number'    => false,
                'require_special'   => false,
                'max_attempts'      => 20,
            )
        );

        $max_attempts = max( 1, (int) $options['max_attempts'] );

        for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
            $password = wp_generate_password( $length, true, true );

            if ( $options['require_uppercase'] && ! preg_match( '/[A-Z]/', $password ) ) {
                continue;
            }

            if ( $options['require_lowercase'] && ! preg_match( '/[a-z]/', $password ) ) {
                continue;
            }

            if ( $options['require_number'] && ! preg_match( '/\d/', $password ) ) {
                continue;
            }

            if ( $options['require_special'] && ! preg_match( '/[^\da-zA-Z]/', $password ) ) {
                continue;
            }

            return $password;
        }

        return wp_generate_password( $length, true, true );
    }

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
     * Resolve TopTen product identifier from WooCommerce product.
     *
     * Returns the SKU when available; otherwise falls back to stored
     * identifiers compatible with previous integrations.
     *
     * @param \WC_Product $product Product instance.
     *
     * @return string|int|null
     */
    public static function resolve_topten_product_id( \WC_Product $product ) {
        $targets = array();

        $targets[] = array(
            'id'      => (int) $product->get_id(),
            'product' => $product,
        );

        if ( $product->is_type( 'variation' ) ) {
            $parent_id = (int) $product->get_parent_id();
            if ( $parent_id > 0 ) {
                $parent_product = wc_get_product( $parent_id );
                $targets[]      = array(
                    'id'      => $parent_id,
                    'product' => $parent_product,
                );
            }
        }

        foreach ( $targets as $target ) {
            $product_obj = isset( $target['product'] ) && $target['product'] instanceof \WC_Product ? $target['product'] : $product;

            if ( ! $product_obj ) {
                continue;
            }

            $sku = trim( (string) $product_obj->get_sku() );
            if ( '' !== $sku ) {
                $legacy_mapped = apply_filters( 'ftc_topten_resolve_prod_id_by_sku', null, $sku, $product_obj );

                if ( is_string( $legacy_mapped ) || is_numeric( $legacy_mapped ) ) {
                    $legacy_mapped = trim( (string) $legacy_mapped );
                    if ( '' !== $legacy_mapped ) {
                        return $legacy_mapped;
                    }
                }

                return $sku;
            }
        }

        foreach ( $targets as $target ) {
            $meta = get_post_meta( $target['id'], 'id_topten', true );
            if ( is_numeric( $meta ) && (int) $meta > 0 ) {
                return (int) $meta;
            }
        }

        foreach ( $targets as $target ) {
            $product_obj = isset( $target['product'] ) && $target['product'] instanceof \WC_Product ? $target['product'] : $product;
            $legacy_key  = apply_filters( 'ftc_topten_product_meta_key', '_ftc_topten_prod_id', $product_obj );
            $legacy      = get_post_meta( $target['id'], $legacy_key, true );

            if ( is_numeric( $legacy ) && (int) $legacy > 0 ) {
                return (int) $legacy;
            }
        }

        $db = \FTC_Plugin::instance()->db();
        if ( $db ) {
            foreach ( $targets as $target ) {
                $map = $db->find_map( 'product', (int) $target['id'] );
                if ( $map && ! empty( $map['external_id'] ) && is_numeric( $map['external_id'] ) ) {
                    return (int) $map['external_id'];
                }
            }
        }

        return null;
    }

    /**
     * Resolve chosen terms for order item.
     *
     * @param \WC_Product            $product Product.
     * @param \WC_Order_Item_Product $item    Order item.
     *
     * @return array
     */
    public static function resolve_chosen_terms_for_item( \WC_Product $product, \WC_Order_Item_Product $item ) {
        $text_parts = array();
        $terms_ids  = array();

        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            $attrs  = $product->get_attributes();

            foreach ( $attrs as $attr_key => $attr_val ) {
                $label = wc_attribute_label( str_replace( 'attribute_', '', $attr_key ), $parent );
                $text_parts[] = sprintf( '%s: %s', $label, $attr_val );

                $maybe_ids = apply_filters( 'ftc_topten_map_chosen_terms', array(), $attr_key, $attr_val, $product, $item );
                if ( is_array( $maybe_ids ) ) {
                    foreach ( $maybe_ids as $id ) {
                        if ( is_numeric( $id ) ) {
                            $terms_ids[] = (int) $id;
                        }
                    }
                }
            }
        }

        $chosen_text   = ! empty( $text_parts ) ? implode( ', ', $text_parts ) : null;
        $chosen_ids_csv = ! empty( $terms_ids ) ? implode( ',', $terms_ids ) : null;

        return array( $chosen_ids_csv, $chosen_text );
    }

    /**
     * Map WooCommerce currency code to TopTen mone_Id.
     *
     * @param string $wc_currency WooCommerce currency code.
     *
     * @return int
     */
    public static function map_currency_to_mone_id( string $wc_currency ) : int {
        $map        = array(
            'USD' => 1,
            'UYU' => 2,
        );
        $wc_currency = strtoupper( trim( $wc_currency ) );
        $id          = isset( $map[ $wc_currency ] ) ? $map[ $wc_currency ] : 2;

        return (int) apply_filters( 'ftc_topten_map_mone_id', $id, $wc_currency );
    }

    /**
     * Normalise a date/time string and return null when empty or invalid.
     *
     * @param mixed  $value  Raw value.
     * @param string $format Output format (defaults to Y-m-d).
     *
     * @return string|null
     */
    public static function normalize_datetime_nullable( $value, $format = 'Y-m-d' ) {
        if ( ! is_scalar( $value ) ) {
            return null;
        }

        $value = trim( (string) $value );
        if ( '' === $value ) {
            return null;
        }

        $timestamp = strtotime( $value );
        if ( false === $timestamp ) {
            return null;
        }

        return gmdate( $format, $timestamp );
    }

    /**
     * Generate a stable hash for an identity value (email, document, etc.).
     *
     * @param mixed $value Raw identifier.
     *
     * @return string
     */
    public static function hash_identity( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $raw = trim( (string) $value );
        if ( '' === $raw ) {
            return '';
        }

        if ( is_email( $raw ) ) {
            return md5( strtolower( $raw ) );
        }

        return hash( 'sha256', strtolower( $raw ) );
    }
}
