<?php
/**
 * Customer synchronization.
 *
 * @package Ferk_Topten_Connector\Includes\Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';

/**
 * Sync WooCommerce customers with TopTen.
 */
class FTC_Customer_Sync {
    /**
     * Obtain or create TopTen user based on order.
     *
     * @param WC_Order $order Order.
     *
     * @return string
     * @throws Exception When creation fails.
     */
    public function get_or_create_topten_user_from_order( $order ) {
        $user_id      = $order->get_user_id();
        $email        = $order->get_billing_email();
        $hash         = $email ? md5( strtolower( $email ) ) : '';
        $wc_identifier = $user_id ? (int) $user_id : $this->get_guest_identifier( $email );

        $db  = FTC_Plugin::instance()->db();
        $map = $db ? $db->find_map( 'customer', $wc_identifier, $email ) : null;

        if ( $map && ! empty( $map['external_id'] ) ) {
            $order->update_meta_data( '_ftc_topten_user_id', $map['external_id'] );
            $order->save();

            return $map['external_id'];
        }

        $client  = FTC_Plugin::instance()->get_client_from_order( $order );
        $payload = array(
            'email'          => $email,
            'first_name'     => $order->get_billing_first_name(),
            'last_name'      => $order->get_billing_last_name(),
            'phone'          => $order->get_billing_phone(),
            'billing_address' => array(
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'state'     => $order->get_billing_state(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
            ),
        );

        $payload = apply_filters( 'ftc_create_user_payload', $payload, $order );

        $response = $client->create_user( $payload );
        if ( empty( $response['id'] ) ) {
            throw new Exception( __( 'Respuesta inválida al crear usuario TopTen.', 'ferk-topten-connector' ) );
        }

        $external_id = $response['id'];
        $data_json   = wp_json_encode( $response );

        if ( $db ) {
            $db->upsert_map(
                'customer',
                $wc_identifier ? $wc_identifier : 0,
                $external_id,
                array(
                    'hash'      => $hash,
                    'data_json' => $data_json,
                )
            );
        }

        $order->update_meta_data( '_ftc_topten_user_id', $external_id );
        $order->save();

        FTC_Logger::instance()->info( 'customer_sync', __( 'Usuario TopTen vinculado.', 'ferk-topten-connector' ), array( 'order_id' => $order->get_id(), 'topten_user_id' => $external_id ) );

        return $external_id;
}

    /**
     * Generate guest identifier using email hash.
     *
     * @param string $email Email.
     *
     * @return int
     */
    protected function get_guest_identifier( $email ) {
        if ( empty( $email ) ) {
            return 0;
        }

        $crc = sprintf( '%u', crc32( strtolower( $email ) ) );

        return (int) $crc;
    }
}
