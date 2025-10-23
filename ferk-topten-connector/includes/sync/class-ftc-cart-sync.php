<?php
/**
 * Cart synchronization.
 *
 * @package Ferk_Topten_Connector\Includes\Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';

/**
 * Sync WooCommerce carts with TopTen.
 */
class FTC_Cart_Sync {
    /**
     * Create TopTen cart from WooCommerce order.
     *
     * @param WC_Order $order          Order.
     * @param string   $topten_user_id TopTen user ID.
     *
     * @return string
     * @throws Exception When creation fails.
     */
    public function create_topten_cart_from_order( $order, $topten_user_id ) {
        $items = array();

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku     = $product ? $product->get_sku() : '';
            $items[] = array(
                'sku'       => $sku ? $sku : (string) $item->get_product_id(),
                'name'      => $item->get_name(),
                'quantity'  => (int) $item->get_quantity(),
                'unit_price'=> (float) ( $item->get_subtotal() / max( 1, $item->get_quantity() ) ),
                'total'     => (float) $item->get_total(),
            );
        }

        $payload = array(
            'user_id'        => $topten_user_id,
            'currency'       => $order->get_currency(),
            'items'          => $items,
            'discount_total' => (float) $order->get_discount_total(),
            'shipping_total' => (float) $order->get_shipping_total(),
            'tax_total'      => (float) $order->get_total_tax(),
            'order_reference'=> $order->get_order_key(),
        );

        $payload = apply_filters( 'ftc_create_cart_payload', $payload, $order );

        $client   = FTC_Plugin::instance()->get_client_from_order( $order );
        $response = $client->create_cart( $payload );

        if ( empty( $response['id'] ) ) {
            throw new Exception( __( 'No se pudo crear el carrito TopTen.', 'ferk-topten-connector' ) );
        }

        $cart_id = $response['id'];
        $order->update_meta_data( '_ftc_topten_cart_id', $cart_id );
        $order->save();

        FTC_Logger::instance()->info( 'cart_sync', __( 'Carrito TopTen creado.', 'ferk-topten-connector' ), array( 'order_id' => $order->get_id(), 'cart_id' => $cart_id ) );

        return $cart_id;
    }
}
