<?php
/**
 * WooCommerce payment gateway for GetNet (TopTen).
 *
 * @package Ferk_Topten_Connector\Includes\Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';
require_once FTC_PLUGIN_DIR . 'includes/sync/class-ftc-customer-sync.php';
require_once FTC_PLUGIN_DIR . 'includes/sync/class-ftc-cart-sync.php';

/**
 * Payment gateway implementation.
 */
class FTC_Gateway_Getnet extends WC_Payment_Gateway {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ftc_getnet_topten';
        $this->method_title       = __( 'GetNet (TopTen)', 'ferk-topten-connector' );
        $this->method_description = __( 'Procesa pagos mediante la pasarela GetNet integrada con TopTen.', 'ferk-topten-connector' );
        $this->has_fields         = false;
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled        = $this->get_option( 'enabled', 'no' );
        $this->title          = $this->get_option( 'title', __( 'GetNet (TopTen)', 'ferk-topten-connector' ) );
        $this->description    = $this->get_option( 'description', __( 'Paga a través de GetNet con la integración TopTen.', 'ferk-topten-connector' ) );
        $this->sandbox_mode   = $this->get_option( 'sandbox_mode', 'default' );
        $this->api_key        = $this->get_option( 'api_key', '' );
        $this->webhook_secret = $this->get_option( 'webhook_secret', '' );
        $this->base_url_sandbox = $this->get_option( 'base_url_sandbox', '' );
        $this->base_url_production = $this->get_option( 'base_url_production', '' );
        $this->allowed_country = $this->get_option( 'allowed_country', '' );
        $this->allowed_currency = $this->get_option( 'allowed_currency', '' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Habilitar/Deshabilitar', 'ferk-topten-connector' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar GetNet (TopTen)', 'ferk-topten-connector' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Título', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Controla el título que ve el cliente durante el checkout.', 'ferk-topten-connector' ),
                'default'     => __( 'GetNet (TopTen)', 'ferk-topten-connector' ),
            ),
            'description' => array(
                'title'       => __( 'Descripción', 'ferk-topten-connector' ),
                'type'        => 'textarea',
                'description' => __( 'Texto que se muestra durante el checkout.', 'ferk-topten-connector' ),
                'default'     => __( 'Serás redirigido a la plataforma GetNet para completar tu pago.', 'ferk-topten-connector' ),
            ),
            'sandbox_mode' => array(
                'title'       => __( 'Modo Sandbox', 'ferk-topten-connector' ),
                'type'        => 'select',
                'description' => __( 'Define si este método debe usar el entorno sandbox.', 'ferk-topten-connector' ),
                'default'     => 'default',
                'options'     => array(
                    'default' => __( 'Usar configuración global', 'ferk-topten-connector' ),
                    'yes'     => __( 'Forzar Sandbox', 'ferk-topten-connector' ),
                    'no'      => __( 'Forzar Producción', 'ferk-topten-connector' ),
                ),
            ),
            'base_url_sandbox' => array(
                'title'       => __( 'Base URL Sandbox', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Sobrescribe la URL base sandbox.', 'ferk-topten-connector' ),
            ),
            'base_url_production' => array(
                'title'       => __( 'Base URL Producción', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Sobrescribe la URL base producción.', 'ferk-topten-connector' ),
            ),
            'api_key' => array(
                'title'       => __( 'API Key', 'ferk-topten-connector' ),
                'type'        => 'password',
                'description' => __( 'Opcional: sobrescribe la API Key global.', 'ferk-topten-connector' ),
            ),
            'webhook_secret' => array(
                'title'       => __( 'Webhook Secret', 'ferk-topten-connector' ),
                'type'        => 'password',
                'description' => __( 'Opcional: sobrescribe el Webhook Secret global.', 'ferk-topten-connector' ),
            ),
            'allowed_country' => array(
                'title'       => __( 'Permitir solo país', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Código de país (ISO2) permitido. Dejar vacío para todos.', 'ferk-topten-connector' ),
            ),
            'allowed_currency' => array(
                'title'       => __( 'Permitir solo moneda', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Código de moneda permitido. Dejar vacío para todos.', 'ferk-topten-connector' ),
            ),
        );
    }

    /**
     * Check availability.
     *
     * @return bool
     */
    public function is_available() {
        if ( 'yes' !== $this->enabled ) {
            return false;
        }

        $config = $this->get_gateway_config();
        if ( empty( $config['api_key'] ) ) {
            return false;
        }

        $base_url = ( 'yes' === $config['sandbox'] ) ? $config['base_url_sandbox'] : $config['base_url_production'];
        if ( empty( $base_url ) ) {
            return false;
        }

        if ( $this->allowed_currency && strtoupper( get_woocommerce_currency() ) !== strtoupper( $this->allowed_currency ) ) {
            return false;
        }

        if ( $this->allowed_country ) {
            $base_location = wc_get_base_location();
            $country       = isset( $base_location['country'] ) ? $base_location['country'] : '';
            if ( strtoupper( $country ) !== strtoupper( $this->allowed_country ) ) {
                return false;
            }
        }

        if ( ! parent::is_available() ) {
            return false;
        }

        return true;
    }

    /**
     * Process payment.
     *
     * @param int $order_id Order ID.
     *
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Pedido inválido.', 'ferk-topten-connector' ), 'error' );
            return array( 'result' => 'fail' );
        }

        $plugin        = FTC_Plugin::instance();
        $customer_sync = $plugin->customer_sync();
        $cart_sync     = $plugin->cart_sync();

        $topten_user_id = '';

        try {
            $topten_user_id = $customer_sync->get_or_create_topten_user_from_order( $order );
            $order->update_meta_data( '_ftc_topten_user_id', $topten_user_id );
            $order->save();
        } catch ( \Throwable $e ) {
            FTC_Logger::instance()->error( 'gateway', 'create_user_failed: ' . $e->getMessage(), array( 'order_id' => $order_id ) );
            wc_add_notice( __( 'No pudimos crear el usuario en TopTen. Intenta nuevamente o elige otro método de pago.', 'ferk-topten-connector' ), 'error' );

            return array( 'result' => 'fail' );
        }

        try {
            $cart_id = $cart_sync->create_topten_cart_from_order( $order, $topten_user_id );

            $return_url  = add_query_arg(
                array(
                    'order_id' => $order->get_id(),
                    'key'      => $order->get_order_key(),
                ),
                rest_url( 'ftc/v1/getnet/return' )
            );
            $callback_url = rest_url( 'ftc/v1/getnet/webhook' );

            $payload = array(
                'cart_id'     => $cart_id,
                'return_url'  => $return_url,
                'callback_url'=> $callback_url,
                'metadata'    => array(
                    'wc_order_id'  => $order->get_id(),
                    'wc_order_key' => $order->get_order_key(),
                ),
            );

            $payload = apply_filters( 'ftc_create_payment_payload', $payload, $order );

            $client = FTC_Plugin::instance()->get_client_from_order( $order );
            $response = $client->create_payment( $payload, array( 'idempotency_key' => FTC_Utils::uuid_v4() ) );

            if ( empty( $response['payment_url'] ) && empty( $response['redirect_url'] ) ) {
                throw new Exception( __( 'No se recibió URL de pago.', 'ferk-topten-connector' ) );
            }

            $payment_id  = isset( $response['payment_id'] ) ? $response['payment_id'] : ( isset( $response['id'] ) ? $response['id'] : '' );
            $payment_url = ! empty( $response['payment_url'] ) ? $response['payment_url'] : $response['redirect_url'];

            if ( empty( $payment_id ) ) {
                throw new Exception( __( 'No se recibió ID de pago.', 'ferk-topten-connector' ) );
            }

            $order->update_meta_data( '_ftc_topten_payment_id', $payment_id );
            $order->update_meta_data( '_ftc_topten_payment_url', $payment_url );
            $order->save();

            FTC_Logger::instance()->info( 'payment', __( 'Pago TopTen creado.', 'ferk-topten-connector' ), array( 'order_id' => $order_id, 'payment_id' => $payment_id ) );

            return array(
                'result'   => 'success',
                'redirect' => $payment_url,
            );
        } catch ( Exception $e ) {
            FTC_Logger::instance()->error( 'process_payment', $e->getMessage(), array( 'order_id' => $order_id ) );
            wc_add_notice( $e->getMessage(), 'error' );

            return array( 'result' => 'fail' );
        }
    }

    /**
     * Retrieve gateway configuration merged with global settings.
     *
     * @return array
     */
    public function get_gateway_config() {
        $defaults = class_exists( 'FTC_Settings' ) ? FTC_Settings::get_defaults()['credentials'] : array();
        $settings = get_option( FTC_Utils::option_name(), array( 'credentials' => $defaults ) );
        $credentials = isset( $settings['credentials'] ) ? $settings['credentials'] : $defaults;

        $sandbox = $credentials['sandbox'];
        if ( 'yes' === $this->sandbox_mode || 'no' === $this->sandbox_mode ) {
            $sandbox = ( 'yes' === $this->sandbox_mode ) ? 'yes' : 'no';
        }

        $config = array(
            'sandbox'            => $sandbox,
            'base_url_sandbox'   => $this->base_url_sandbox ? $this->base_url_sandbox : $credentials['base_url_sandbox'],
            'base_url_production'=> $this->base_url_production ? $this->base_url_production : $credentials['base_url_production'],
            'api_key'            => $this->api_key ? $this->api_key : $credentials['api_key'],
            'webhook_secret'     => $this->webhook_secret ? $this->webhook_secret : $credentials['webhook_secret'],
            'timeout'            => $credentials['timeout'],
            'retries'            => $credentials['retries'],
        );

        return $config;
    }
}
