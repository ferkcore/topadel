<?php
/**
 * REST API endpoints.
 *
 * @package Ferk_Topten_Connector\Includes\API
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-validator.php';
require_once FTC_PLUGIN_DIR . 'includes/order/class-ftc-order-status.php';

/**
 * Registers REST endpoints for webhooks and callbacks.
 */
class FTC_Webhooks {
    /**
     * Register hooks.
     */
    public function hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST routes.
     */
    public function register_routes() {
        register_rest_route(
            'ftc/v1',
            '/ping',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_ping' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'ftc/v1',
            '/getnet/webhook',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_getnet_webhook' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'ftc/v1',
            '/getnet/return',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_getnet_return' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle ping.
     *
     * @return array
     */
    public function handle_ping() {
        return array(
            'status'  => 'ok',
            'time'    => current_time( 'mysql' ),
            'version' => FTC_PLUGIN_VERSION,
        );
    }

    /**
     * Handle webhook.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_getnet_webhook( WP_REST_Request $request ) {
        $defaults = class_exists( 'FTC_Settings' ) ? FTC_Settings::get_defaults() : array();
        $settings = get_option( FTC_Utils::option_name(), $defaults );
        $secret   = FTC_Utils::array_get( $settings, 'credentials.webhook_secret', '' );
        $payload  = $request->get_body();
        $signature = $request->get_header( 'X-Topten-Signature' );

        if ( ! FTC_Validator::validate_signature( $payload, $signature, $secret ) ) {
            FTC_Logger::instance()->warn( 'webhook', __( 'Firma inválida en webhook.', 'ferk-topten-connector' ) );

            return new WP_Error( 'ftc_invalid_signature', __( 'Firma inválida.', 'ferk-topten-connector' ), array( 'status' => 401 ) );
        }

        $data = json_decode( $payload, true );
        if ( empty( $data['data']['payment_id'] ) ) {
            return new WP_Error( 'ftc_invalid_payload', __( 'Payload incompleto.', 'ferk-topten-connector' ), array( 'status' => 400 ) );
        }

        $payment_id = $data['data']['payment_id'];
        $status     = isset( $data['data']['status'] ) ? sanitize_key( $data['data']['status'] ) : '';

        if ( ! FTC_Validator::is_valid_status( $status ) ) {
            $status = 'pending';
        }

        $order = $this->find_order_by_payment_id( $payment_id );
        if ( ! $order ) {
            FTC_Logger::instance()->error( 'webhook', __( 'Pedido no encontrado para el pago.', 'ferk-topten-connector' ), array( 'payment_id' => $payment_id ) );

            return new WP_Error( 'ftc_order_not_found', __( 'Pedido no encontrado.', 'ferk-topten-connector' ), array( 'status' => 404 ) );
        }

        $new_status = FTC_Order_Status::map_topten_status_to_wc( $status, $order );

        $this->update_order_status( $order, $new_status, $payment_id, $status, $data );

        return rest_ensure_response( array( 'received' => true ) );
    }

    /**
     * Handle return URL.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function handle_getnet_return( WP_REST_Request $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );
        $order_key = sanitize_text_field( (string) $request->get_param( 'key' ) );
        $payment_id = sanitize_text_field( (string) $request->get_param( 'payment_id' ) );

        $checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url();

        if ( empty( $order_id ) || empty( $order_key ) ) {
            wp_safe_redirect( $checkout_url );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $order_key ) {
            wp_safe_redirect( $checkout_url );
            exit;
        }

        if ( ! empty( $payment_id ) ) {
            try {
                $client  = FTC_Plugin::instance()->get_client_from_order( $order );
                $payment = $client->get_payment( $payment_id );
                if ( ! empty( $payment['status'] ) ) {
                    $new_status = FTC_Order_Status::map_topten_status_to_wc( $payment['status'], $order );
                    $this->update_order_status( $order, $new_status, $payment_id, $payment['status'], $payment );
                }
            } catch ( Exception $e ) {
                FTC_Logger::instance()->warn( 'return', $e->getMessage(), array( 'order_id' => $order_id ) );
            }
        }

        $redirect = $order->get_checkout_order_received_url();

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Update order status and add note.
     *
     * @param WC_Order $order      Order object.
     * @param string   $status     Target status.
     * @param string   $payment_id Payment ID.
     * @param string   $raw_status Raw status.
     * @param array    $payload    Payload.
     */
    protected function update_order_status( $order, $status, $payment_id, $raw_status, $payload ) {
        $note = sprintf(
            /* translators: 1: payment id, 2: status */
            __( 'Actualización TopTen. Pago %1$s con estado %2$s.', 'ferk-topten-connector' ),
            $payment_id,
            $raw_status
        );

        if ( 'completed' === $status || 'processing' === $status ) {
            $order->payment_complete( $payment_id );
        } elseif ( 'on-hold' === $status ) {
            $order->update_status( 'on-hold', $note );
        } elseif ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
            $order->update_status( $status, $note );
        } else {
            $order->add_order_note( $note );
        }

        $order->update_meta_data( '_ftc_topten_payment_status', sanitize_text_field( $raw_status ) );
        $order->save();

        FTC_Logger::instance()->info( 'webhook', __( 'Pedido actualizado desde webhook.', 'ferk-topten-connector' ), array(
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
            'status'     => $raw_status,
            'payload'    => $payload,
        ) );
    }

    /**
     * Find order by payment id.
     *
     * @param string $payment_id Payment ID.
     *
     * @return WC_Order|false
     */
    protected function find_order_by_payment_id( $payment_id ) {
        global $wpdb;
        $order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", '_ftc_topten_payment_id', $payment_id ) );

        if ( $order_id ) {
            return wc_get_order( $order_id );
        }

        return false;
    }
}
