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

        register_rest_route(
            'ftc/v1',
            '/admin/test-user',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_admin_test_user' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );

        register_rest_route(
            'ftc/v1',
            '/admin/products-map',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_admin_products_map' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
    }

    /**
     * Permission callback for admin endpoints.
     *
     * @return bool
     */
    public function check_admin_permission() {
        return current_user_can( 'manage_woocommerce' );
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
     * Handle GetNet webhook.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_getnet_webhook( WP_REST_Request $request ) {
        $raw_body  = $request->get_body();
        $signature = $request->get_header( 'X-Topten-Signature' );
        $timestamp = $request->get_header( 'X-Topten-Timestamp' );

        if ( '' === $raw_body ) {
            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'empty_body' ), 400 );
        }

        $defaults = class_exists( 'FTC_Settings' ) ? FTC_Settings::get_defaults() : array();
        $settings = get_option( FTC_Utils::option_name(), $defaults );
        $secret   = (string) FTC_Utils::array_get( $settings, 'credentials.webhook_secret', '' );

        if ( '' !== $secret ) {
            $validation = $this->validate_signature( $raw_body, (string) $signature, $secret );
            if ( is_wp_error( $validation ) ) {
                $status_code = (int) $validation->get_error_data( 'status' );
                if ( $status_code <= 0 ) {
                    $status_code = 401;
                }

                FTC_Logger::instance()->warn( 'webhook', 'Webhook rejected: ' . $validation->get_error_code() );

                return new WP_REST_Response(
                    array(
                        'ok'     => false,
                        'reason' => $validation->get_error_code(),
                    ),
                    $status_code
                );
            }
        } else {
            FTC_Logger::instance()->warn(
                'webhook',
                'Webhook secret missing; accepting request in dev mode.',
                array( 'signature_header' => ( $signature ? 'present' : 'absent' ) )
            );
        }

        if ( ! $this->validate_timestamp_window( $timestamp ) ) {
            FTC_Logger::instance()->warn( 'webhook', 'Webhook timestamp outside allowed window.' );

            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_timestamp' ), 401 );
        }

        $decoded = $this->parse_json_payload( $raw_body );
        if ( ! is_array( $decoded ) ) {
            FTC_Logger::instance()->warn( 'webhook', 'Webhook payload is not a valid JSON object.' );

            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_json' ), 400 );
        }

        $identifiers = $this->extract_identifiers( $decoded );
        if ( empty( $identifiers['status'] ) ) {
            $identifiers['status'] = 'pending';
        }

        $order = $this->find_order_by_identifiers( $identifiers );

        if ( ! $order ) {
            FTC_Logger::instance()->warn(
                'webhook',
                'Order not found for webhook identifiers.',
                array(
                    'token'      => isset( $identifiers['token'] ) ? $this->mask_identifier( $identifiers['token'] ) : null,
                    'idadquiria' => isset( $identifiers['idadquiria'] ) ? $identifiers['idadquiria'] : null,
                    'cart_id'    => isset( $identifiers['cart_id'] ) ? $identifiers['cart_id'] : null,
                )
            );

            return rest_ensure_response(
                array(
                    'received'     => true,
                    'order_found'  => false,
                )
            );
        }

        $incoming_status = strtolower( trim( (string) $identifiers['status'] ) );
        $last_status     = strtolower( trim( (string) $order->get_meta( '_ftc_topten_last_status', true ) ) );

        if ( '' !== $incoming_status && $incoming_status === $last_status ) {
            FTC_Logger::instance()->info(
                'webhook',
                'Duplicate webhook status ignored.',
                array(
                    'order_id' => $order->get_id(),
                    'status'   => $incoming_status,
                )
            );

            return rest_ensure_response(
                array(
                    'received'     => true,
                    'order_found'  => true,
                    'duplicate'    => true,
                )
            );
        }

        $context = array(
            'transaction_id' => isset( $identifiers['token'] ) && '' !== $identifiers['token']
                ? (string) $identifiers['token']
                : ( isset( $identifiers['idadquiria'] ) ? (string) $identifiers['idadquiria'] : '' ),
            'amount'          => isset( $identifiers['amount'] ) ? $identifiers['amount'] : null,
        );

        try {
            FTC_Order_Status::apply_webhook_status( $order, (string) $identifiers['status'], $context );
        } catch ( \Throwable $e ) {
            FTC_Logger::instance()->error(
                'webhook',
                'Error applying webhook status: ' . $e->getMessage(),
                array( 'order_id' => $order->get_id() )
            );

            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'processing_error' ), 500 );
        }

        FTC_Logger::instance()->info(
            'webhook',
            'Webhook processed successfully.',
            array(
                'order_id' => $order->get_id(),
                'status'   => $incoming_status,
            )
        );

        return rest_ensure_response(
            array(
                'received'     => true,
                'order_found'  => true,
            )
        );
    }

    /**
     * Handle admin products map action.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_admin_products_map( WP_REST_Request $request ) {
        $nonce = $request->get_param( 'nonce' );
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'ftc_products_map' ) ) {
            return new WP_Error( 'ftc_products_map_nonce', __( 'Nonce inválido.', 'ferk-topten-connector' ), array( 'status' => 403 ) );
        }

        try {
            $result = FTC_Plugin::instance()->products_importer()->map_by_sku( array() );
        } catch ( Exception $exception ) {
            FTC_Logger::instance()->error(
                'products-map',
                'Products map failed',
                array( 'error' => $exception->getMessage() )
            );

            return new WP_Error( 'ftc_products_map_failed', $exception->getMessage(), array( 'status' => 500 ) );
        }

        $response = array(
            'summary'   => isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array(),
            'rows'      => isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array(),
            'truncated' => ! empty( $result['truncated'] ),
        );

        if ( count( $response['rows'] ) > FTC_Products_Importer::MAX_RESPONSE_ROWS ) {
            $response['rows'] = array_slice( $response['rows'], 0, FTC_Products_Importer::MAX_RESPONSE_ROWS );
            $response['truncated'] = true;
        }

        return rest_ensure_response( $response );
    }

    /**
     * Handle return URL from GetNet.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|void
     */
    public function handle_getnet_return( WP_REST_Request $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );
        $order_key = sanitize_text_field( (string) $request->get_param( 'key' ) );

        if ( empty( $order_id ) || empty( $order_key ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'missing_params' ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'order_not_found' ), 404 );
        }

        if ( $order->get_order_key() !== $order_key ) {
            return new WP_REST_Response( array( 'ok' => false, 'reason' => 'forbidden' ), 403 );
        }

        $token      = sanitize_text_field( (string) $request->get_param( 'token' ) );
        $idadquiria = sanitize_text_field( (string) $request->get_param( 'idadquiria' ) );

        if ( $token || $idadquiria ) {
            FTC_Logger::instance()->info(
                'return',
                'Return handler accessed.',
                array(
                    'order_id'  => $order->get_id(),
                    'token'     => $token ? $this->mask_identifier( $token ) : null,
                    'idadquiria'=> $idadquiria ? $idadquiria : null,
                )
            );
        }

        wp_safe_redirect( $order->get_checkout_order_received_url() );
        exit;
    }

    /**
     * Handle admin test user creation.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_admin_test_user( WP_REST_Request $request ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error( 'ftc_forbidden', __( 'No tienes permisos para esta acción.', 'ferk-topten-connector' ), array(
                'status' => 403,
            ) );
        }

        $timestamp = gmdate( 'YmdHis' );
        $random    = wp_rand( 1000, 9999 );
        $email     = sprintf( 'ftc-sandbox+%s%s@example.com', $timestamp, $random );
        $password  = FTC_Utils::random_password(
            10,
            array(
                'require_uppercase' => true,
                'require_lowercase' => true,
                'require_number'    => true,
                'require_special'   => true,
            )
        );

        $payload = array(
            'Nombre'     => 'Sandbox',
            'Apellido'   => 'Test',
            'Correo'     => $email,
            'Clave'      => $password,
            'Enti_Id'    => apply_filters( 'ftc_topten_entity_id', FTC_Utils::FTCTOPTEN_ENTITY_ID ),
            'ExternalId' => $email,
        );

        try {
            $client = FTC_Plugin::instance()->client();
            $id     = (int) $client->create_user_newregister( $payload );

            if ( $id <= 0 ) {
                throw new Exception( 'TopTen NewRegister retornó 0 (error creando usuario)' );
            }
        } catch ( Exception $e ) {
            FTC_Logger::instance()->error( 'tools', 'test_user_failed: ' . $e->getMessage() );

            return new WP_Error( 'ftc_test_user_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'id'    => $id,
                'email' => $email,
            )
        );
    }

    /**
     * Parse JSON payload with basic sanitisation.
     *
     * @param string $raw Raw body.
     *
     * @return array|null
     */
    protected function parse_json_payload( $raw ) {
        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE === json_last_error() ) {
            return $decoded;
        }

        $clean = wp_check_invalid_utf8( $raw, true );
        if ( $clean !== $raw ) {
            $decoded = json_decode( $clean, true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                return $decoded;
            }
        }

        $stripped = preg_replace( '/[\x00-\x1F\x7F]/u', '', $raw );
        if ( is_string( $stripped ) && $stripped !== $raw ) {
            $decoded = json_decode( $stripped, true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract identifiers from decoded payload.
     *
     * @param array $decoded Decoded payload.
     *
     * @return array
     */
    protected function extract_identifiers( array $decoded ) {
        $identifiers = array();

        $token = $this->find_value( $decoded, array( 'token', 'placetopaytoken', 'paymenttoken', 'topten_token' ) );
        if ( null !== $token && '' !== $token ) {
            $identifiers['token'] = (string) $token;
        }

        $idadquiria = $this->find_value( $decoded, array( 'idadquiria', 'id_adquiria', 'idacquirer' ) );
        if ( null !== $idadquiria && '' !== $idadquiria ) {
            $identifiers['idadquiria'] = is_numeric( $idadquiria ) ? (int) $idadquiria : (string) $idadquiria;
        }

        $cart_id = $this->find_value( $decoded, array( 'carr_id', 'cartid', 'cart_id' ) );
        if ( null !== $cart_id && '' !== $cart_id ) {
            $identifiers['cart_id'] = is_numeric( $cart_id ) ? (int) $cart_id : (string) $cart_id;
        }

        $status = $this->find_value( $decoded, array( 'status', 'estado', 'state', 'result', 'statuscode', 'status_code' ) );
        if ( null !== $status && '' !== $status ) {
            $identifiers['status'] = (string) $status;
        }

        $amount = $this->find_value( $decoded, array( 'amount', 'valor', 'total', 'amountvalue' ) );
        if ( null !== $amount && '' !== $amount ) {
            $identifiers['amount'] = is_numeric( $amount ) ? (float) $amount : (string) $amount;
        }

        return $identifiers;
    }

    /**
     * Find value by trying multiple keys recursively.
     *
     * @param array $payload Payload.
     * @param array $keys    Keys to search.
     *
     * @return mixed|null
     */
    protected function find_value( $payload, array $keys ) {
        if ( ! is_array( $payload ) ) {
            return null;
        }

        $keys = array_map( 'strtolower', $keys );
        foreach ( $payload as $k => $value ) {
            if ( is_string( $k ) && in_array( strtolower( $k ), $keys, true ) ) {
                if ( is_scalar( $value ) ) {
                    return $value;
                }
            }

            if ( is_array( $value ) ) {
                $found = $this->find_value( $value, $keys );
                if ( null !== $found ) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find order using webhook identifiers.
     *
     * @param array $identifiers Identifiers.
     *
     * @return \WC_Order|false
     */
    protected function find_order_by_identifiers( array $identifiers ) {
        if ( ! empty( $identifiers['token'] ) ) {
            $order = $this->find_order_by_meta( '_ftc_topten_payment_token', (string) $identifiers['token'] );
            if ( $order ) {
                return $order;
            }
        }

        if ( isset( $identifiers['idadquiria'] ) ) {
            $value = (string) $identifiers['idadquiria'];
            $order = $this->find_order_by_meta( '_ftc_topten_payment_idadquiria', $value );
            if ( $order ) {
                return $order;
            }
        }

        if ( isset( $identifiers['cart_id'] ) ) {
            $value = (string) $identifiers['cart_id'];
            $order = $this->find_order_by_meta( '_ftc_topten_cart_id', $value );
            if ( $order ) {
                return $order;
            }
        }

        return false;
    }

    /**
     * Helper to query order by meta key/value.
     *
     * @param string $meta_key   Meta key.
     * @param string $meta_value Meta value.
     *
     * @return \WC_Order|false
     */
    protected function find_order_by_meta( $meta_key, $meta_value ) {
        if ( '' === $meta_value ) {
            return false;
        }

        $orders = wc_get_orders(
            array(
                'type'      => 'shop_order',
                'limit'     => 1,
                'orderby'   => 'date',
                'order'     => 'DESC',
                'meta_key'  => $meta_key,
                'meta_value'=> $meta_value,
                'return'    => 'objects',
                'status'    => array_keys( wc_get_order_statuses() ),
            )
        );

        if ( ! empty( $orders ) && $orders[0] instanceof \WC_Order ) {
            return $orders[0];
        }

        return false;
    }

    /**
     * Validate webhook signature.
     *
     * @param string $raw_body  Raw request body.
     * @param string $signature Signature header.
     * @param string $secret    Secret key.
     *
     * @return true|WP_Error
     */
    protected function validate_signature( $raw_body, $signature, $secret ) {
        if ( '' === $signature ) {
            return new WP_Error( 'missing_signature', 'Signature header missing.', array( 'status' => 401 ) );
        }

        $computed = base64_encode( hash_hmac( 'sha256', $raw_body, $secret, true ) );
        if ( ! hash_equals( $computed, $signature ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid webhook signature.', array( 'status' => 401 ) );
        }

        return true;
    }

    /**
     * Validate optional timestamp header.
     *
     * @param string $timestamp Timestamp header.
     *
     * @return bool
     */
    protected function validate_timestamp_window( $timestamp ) {
        if ( empty( $timestamp ) ) {
            return true;
        }

        if ( is_numeric( $timestamp ) ) {
            $ts = (int) $timestamp;
        } else {
            $ts = strtotime( (string) $timestamp );
        }

        if ( ! $ts ) {
            return false;
        }

        $now = time();

        return abs( $now - $ts ) <= 600;
    }

    /**
     * Mask identifier values before logging.
     *
     * @param string $value Identifier value.
     *
     * @return string
     */
    protected function mask_identifier( $value ) {
        $value = (string) $value;
        $length = strlen( $value );

        if ( $length <= 6 ) {
            return $value;
        }

        return substr( $value, 0, 3 ) . '...' . substr( $value, -3 );
    }
}

