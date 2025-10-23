<?php
/**
 * Cart synchronization.
 *
 * @package Ferk_Topten_Connector\Includes\Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';

/**
 * Sync WooCommerce carts with TopTen.
 */
class FTC_Cart_Sync {
    /**
     * Create TopTen cart from WooCommerce order.
     *
     * @param \WC_Order $order          Order instance.
     * @param string    $topten_user_id TopTen user identifier.
     *
     * @return string
     * @throws \Exception When creation fails.
     */
    public function create_topten_cart_from_order( \WC_Order $order, $topten_user_id ) {
        $existing = $order->get_meta( '_ftc_topten_cart_id' );
        if ( ! empty( $existing ) ) {
            $order->delete_meta_data( '_ftc_topten_missing_products' );
            $order->save();

            return (string) $existing;
        }

        $user_code = (int) $topten_user_id;
        if ( $user_code <= 0 ) {
            throw new \Exception( __( 'No se pudo vincular el usuario TopTen para este pedido.', 'ferk-topten-connector' ) );
        }

        $items = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $prod_id = \FTC_Utils::resolve_topten_product_id( $product );
            if ( ! $prod_id ) {
                $missing = $order->get_meta( '_ftc_topten_missing_products', true );
                if ( ! is_array( $missing ) ) {
                    $missing = array();
                }
                $missing[ $product->get_id() ] = $product->get_name();
                $order->update_meta_data( '_ftc_topten_missing_products', $missing );
                $order->save();

                throw new \Exception( sprintf( 'Falta mapear Prod_Id TopTen para el producto "%s" (ID %d).', $product->get_name(), $product->get_id() ) );
            }

            $qty = (int) $item->get_quantity();
            list( $chosen_ids, $chosen_text ) = \FTC_Utils::resolve_chosen_terms_for_item( $product, $item );

            $items[] = array_filter(
                array(
                    'Prod_Id'         => (int) $prod_id,
                    'Quantity'        => max( 1, $qty ),
                    'ChosenTerms'     => $chosen_ids ? $chosen_ids : null,
                    'ChosenTermsText' => $chosen_text ? $chosen_text : null,
                ),
                static function ( $value ) {
                    return null !== $value && '' !== $value;
                }
            );
        }

        if ( empty( $items ) ) {
            throw new \Exception( __( 'El pedido no contiene ítems válidos para crear el carrito.', 'ferk-topten-connector' ) );
        }

        $payload = array(
            'Usua_Cod'     => $user_code,
            'CartProducts' => $items,
        );

        /** @var FTC_Client $client */
        $client  = \FTC_Plugin::instance()->get_client_from_order( $order );
        $cart_id = (int) $client->create_cart_external( $payload );

        if ( $cart_id <= 0 ) {
            throw new \Exception( 'TopTen AddCartProductExternal retornó 0 (error creando carrito).' );
        }

        $order->delete_meta_data( '_ftc_topten_missing_products' );
        $order->update_meta_data( '_ftc_topten_cart_id', (string) $cart_id );
        $order->save();

        return (string) $cart_id;
    }
}
