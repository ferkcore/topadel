<?php
/**
 * Order status mapping.
 *
 * @package Ferk_Topten_Connector\Includes\Order
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handle mapping between TopTen and WooCommerce statuses.
 */
class FTC_Order_Status {
    /**
     * Map TopTen status to WooCommerce status.
     *
     * @param string   $status Status from TopTen.
     * @param WC_Order $order  Order instance.
     *
     * @return string
     */
    public static function map_topten_status_to_wc( $status, $order ) {
        $status = strtolower( (string) $status );

        switch ( $status ) {
            case 'approved':
            case 'paid':
                if ( $order->has_downloadable_item() || $order->is_download_permitted() ) {
                    return 'completed';
                }

                return 'processing';
            case 'pending':
                return 'on-hold';
            case 'rejected':
                return 'failed';
            case 'canceled':
            case 'cancelled':
                return 'cancelled';
            default:
                return $order->get_status();
        }
    }
}
