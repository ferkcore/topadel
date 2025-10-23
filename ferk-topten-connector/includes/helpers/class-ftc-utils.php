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

    /**
     * Resolve TopTen product ID from WooCommerce product.
     *
     * @param \WC_Product $product Product instance.
     *
     * @return int|null
     */
    public static function resolve_topten_product_id( \WC_Product $product ) {
        $meta_key = apply_filters( 'ftc_topten_product_meta_key', '_ftc_topten_prod_id', $product );
        $meta     = get_post_meta( $product->get_id(), $meta_key, true );

        if ( is_numeric( $meta ) && (int) $meta > 0 ) {
            return (int) $meta;
        }

        $db  = \FTC_Plugin::instance()->db();
        $map = $db ? $db->find_map( 'product', (int) $product->get_id() ) : null;

        if ( $map && ! empty( $map['external_id'] ) && is_numeric( $map['external_id'] ) ) {
            return (int) $map['external_id'];
        }

        $sku = (string) $product->get_sku();
        $mapped = apply_filters( 'ftc_topten_resolve_prod_id_by_sku', null, $sku, $product );
        if ( is_numeric( $mapped ) && (int) $mapped > 0 ) {
            return (int) $mapped;
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
}
