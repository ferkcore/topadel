<?php
/**
 * Order status helpers.
 *
 * @package Ferk_Topten_Connector\Includes\Order
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WooCommerce order status transitions triggered by webhooks.
 */
class FTC_Order_Status {
    /**
     * Apply webhook status to an order.
     *
     * @param \WC_Order $order   Order instance.
     * @param string    $status  Raw status received from webhook.
     * @param array     $context Additional context (transaction_id, etc.).
     */
    public static function apply_webhook_status( \WC_Order $order, string $status, array $context = array() ) {
        $s = strtolower( trim( $status ) );

        $map = apply_filters(
            'ftc_topten_webhook_status_map',
            array(
                'approved'   => 'paid',
                'paid'       => 'paid',
                'success'    => 'paid',
                'completed'  => 'paid',
                'pending'    => 'on-hold',
                'in_process' => 'on-hold',
                'authorized' => 'on-hold',
                'rejected'   => 'failed',
                'failed'     => 'failed',
                'canceled'   => 'cancelled',
                'cancelled'  => 'cancelled',
            ),
            $order,
            $status,
            $context
        );

        $action = isset( $map[ $s ] ) ? $map[ $s ] : 'on-hold';

        if ( 'paid' === $action ) {
            $transaction_id = '';
            if ( isset( $context['transaction_id'] ) && '' !== $context['transaction_id'] ) {
                $transaction_id = (string) $context['transaction_id'];
            } else {
                $transaction_id = (string) ( $order->get_meta( '_ftc_topten_payment_token' ) ?: '' );
            }

            $order->payment_complete( $transaction_id );
            $order->add_order_note( sprintf( __( 'Pago confirmado por webhook (status: %s).', 'ferk-topten-connector' ), $s ) );
        } elseif ( 'on-hold' === $action ) {
            $order->update_status( 'on-hold', sprintf( __( 'Pago en proceso/pendiente (status: %s).', 'ferk-topten-connector' ), $s ), true );
        } elseif ( 'failed' === $action ) {
            $order->update_status( 'failed', sprintf( __( 'Pago rechazado/fallido (status: %s).', 'ferk-topten-connector' ), $s ), true );
        } elseif ( 'cancelled' === $action || 'canceled' === $action ) {
            $order->update_status( 'cancelled', sprintf( __( 'Pago cancelado (status: %s).', 'ferk-topten-connector' ), $s ), true );
        } else {
            $order->add_order_note( sprintf( __( 'Webhook recibido con estado no mapeado: %s', 'ferk-topten-connector' ), $s ) );
        }

        $order->update_meta_data( '_ftc_topten_payment_status', $s );
        $order->update_meta_data( '_ftc_topten_last_status', $s );
        $order->update_meta_data( '_ftc_topten_last_status_at', time() );
        $order->save();
    }
}

