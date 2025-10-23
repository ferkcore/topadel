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
            'callback_url' => array(
                'title'       => __( 'Callback URL (opcional)', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Si la API lo soporta, se enviará esta URL para recibir callbacks del pago.', 'ferk-topten-connector' ),
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
            'coge_id_pago' => array(
                'title'       => __( 'Coge_Id_Pago', 'ferk-topten-connector' ),
                'type'        => 'select',
                'description' => __( 'Identificador del cobrador en TopTen. Usa 27 para PlaceToPay o 28 para PlaceToPay Santander.', 'ferk-topten-connector' ),
                'default'     => '27',
                'options'     => array(
                    '27' => __( '27 - PlaceToPay', 'ferk-topten-connector' ),
                    '28' => __( '28 - PlaceToPay Santander', 'ferk-topten-connector' ),
                ),
            ),
            'mepa_id' => array(
                'title'       => __( 'Mepa_Id', 'ferk-topten-connector' ),
                'type'        => 'select',
                'description' => __( 'Identificador del medio de pago. Usa 1 (Visa), 2 (MasterCard) o 23 (Santander).', 'ferk-topten-connector' ),
                'default'     => '1',
                'options'     => array(
                    '1'  => __( '1 - Visa', 'ferk-topten-connector' ),
                    '2'  => __( '2 - MasterCard', 'ferk-topten-connector' ),
                    '23' => __( '23 - Santander (recomendado si usas PlaceToPay Santander)', 'ferk-topten-connector' ),
                ),
            ),
            'sucursal_id' => array(
                'title'       => __( 'Sucursal TopTen', 'ferk-topten-connector' ),
                'type'        => 'number',
                'description' => __( 'Identificador de sucursal para entrega/retira. 78 (Pocitos) por defecto.', 'ferk-topten-connector' ),
                'default'     => '78',
            ),
            'id_pais' => array(
                'title'       => __( 'País (Id)', 'ferk-topten-connector' ),
                'type'        => 'number',
                'description' => __( 'Identificador de país en TopTen. 186 corresponde a Uruguay.', 'ferk-topten-connector' ),
                'default'     => '186',
            ),
            'indicativo' => array(
                'title'       => __( 'Indicativo telefónico', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Código de país telefónico utilizado al construir el JsonPedido.', 'ferk-topten-connector' ),
                'default'     => '+598',
            ),
            'origen' => array(
                'title'       => __( 'Origen del pedido', 'ferk-topten-connector' ),
                'type'        => 'text',
                'description' => __( 'Se enviará en el campo "origen" del JsonPedido. Por defecto "Top padel".', 'ferk-topten-connector' ),
                'default'     => 'Top padel',
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

        $coge_id = (int) $this->get_option( 'coge_id_pago', 27 );
        $mepa_id = (int) $this->get_option( 'mepa_id', 1 );
        if ( $coge_id <= 0 || $mepa_id <= 0 ) {
            return false;
        }

        if ( $this->allowed_currency && strtoupper( get_woocommerce_currency() ) !== strtoupper( $this->allowed_currency ) ) {
            return false;
        }

        if ( $this->allowed_country ) {
            $country = '';

            $wc = function_exists( 'WC' ) ? WC() : null;

            if ( $wc && $wc->customer ) {
                $country = $wc->customer->get_billing_country();

                if ( ! $country ) {
                    $country = $wc->customer->get_shipping_country();
                }
            }

            if ( ! $country && $wc && isset( $wc->session ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $order_id = $wc->session->get( 'order_awaiting_payment' );
                if ( $order_id ) {
                    $order = wc_get_order( $order_id );
                    if ( $order ) {
                        $country = $order->get_billing_country() ?: $order->get_shipping_country();
                    }
                }
            }

            if ( ! $country ) {
                $base_location = wc_get_base_location();
                $country       = isset( $base_location['country'] ) ? $base_location['country'] : '';
            }

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

        $topten_user_id = (int) $order->get_meta( '_ftc_topten_user_id' );

        if ( $topten_user_id <= 0 ) {
            try {
                $topten_user_id = (int) $customer_sync->get_or_create_topten_user_from_order( $order );
                $order->update_meta_data( '_ftc_topten_user_id', $topten_user_id );
                $order->save();
            } catch ( \Throwable $e ) {
                FTC_Logger::instance()->error(
                    'gateway',
                    'create_user_failed',
                    array(
                        'order_id' => $order_id,
                        'error'    => $e->getMessage(),
                    )
                );
                wc_add_notice( __( 'No pudimos crear el usuario en TopTen. Intenta nuevamente o elige otro método de pago.', 'ferk-topten-connector' ), 'error' );

                return array( 'result' => 'fail' );
            }
        }

        $cart_id = (int) $order->get_meta( '_ftc_topten_cart_id' );

        if ( $cart_id <= 0 ) {
            try {
                $cart_id = (int) $cart_sync->create_topten_cart_from_order( $order, $topten_user_id );
                $order->update_meta_data( '_ftc_topten_cart_id', $cart_id );
                $order->save();
            } catch ( \Throwable $e ) {
                FTC_Logger::instance()->error(
                    'gateway',
                    'create_cart_failed',
                    array(
                        'order_id' => $order_id,
                        'error'    => $e->getMessage(),
                    )
                );

                wc_add_notice( __( 'No pudimos crear el carrito en TopTen. Intenta nuevamente o elige otro método de pago.', 'ferk-topten-connector' ), 'error' );

                return array( 'result' => 'fail' );
            }
        }

        $topten_user_id = (int) $order->get_meta( '_ftc_topten_user_id' );
        $cart_id        = (int) $order->get_meta( '_ftc_topten_cart_id' );

        if ( $topten_user_id <= 0 || $cart_id <= 0 ) {
            FTC_Logger::instance()->error(
                'gateway',
                'missing_ids',
                array(
                    'order_id' => $order_id,
                    'user_id'  => $topten_user_id,
                    'cart_id'  => $cart_id,
                )
            );
            wc_add_notice( __( 'Error preparando el pago. Intenta de nuevo.', 'ferk-topten-connector' ), 'error' );

            return array( 'result' => 'fail' );
        }

        $currency = $order->get_currency();
        $mone_id  = FTC_Utils::map_currency_to_mone_id( $currency );

        $coge_id  = (int) $this->get_option( 'coge_id_pago', 27 );
        $mepa_id  = (int) $this->get_option( 'mepa_id', 1 );
        $sucursal = (int) $this->get_option( 'sucursal_id', 78 );
        $id_pais  = (int) $this->get_option( 'id_pais', 186 );
        $indic    = (string) $this->get_option( 'indicativo', '+598' );
        $origen   = (string) $this->get_option( 'origen', get_bloginfo( 'name' ) );

        $json_obj = FTC_JsonPedido::build_from_order(
            $order,
            array(
                'usua_cod'     => $topten_user_id,
                'mone_id'      => $mone_id,
                'coge_id_pago' => $coge_id,
                'mepa_id'      => $mepa_id,
                'sucursal_id'  => $sucursal,
                'id_pais'      => $id_pais,
                'indicativo'   => $indic,
                'origen'       => $origen,
            )
        );
        $json_pedido_str = wp_json_encode( $json_obj, JSON_UNESCAPED_UNICODE );

        $return_url = add_query_arg(
            array(
                'order_id' => $order_id,
                'key'      => $order->get_order_key(),
            ),
            rest_url( 'ftc/v1/getnet/return' )
        );

        $payload = array(
            'Carr_Id'      => $cart_id,
            'Coge_Id_Pago' => $coge_id,
            'Mepa_Id'      => $mepa_id,
            'JsonPedido'   => $json_pedido_str,
            'UrlRedirect'  => $return_url,
        );

        $callback_url = esc_url_raw( (string) $this->get_option( 'callback_url', '' ) );
        if ( $callback_url ) {
            $callback_param = apply_filters( 'ftc_topten_payment_callback_param', 'UrlNotification', $order, $payload );
            if ( is_string( $callback_param ) && '' !== $callback_param ) {
                $payload[ $callback_param ] = $callback_url;
            }
        }

        try {
            $client = $plugin->client( $this->get_gateway_config() );
            $res    = $client->create_payment_placetopay( $payload );

            $order->update_meta_data( '_ftc_topten_payment_token', $res['token'] );
            $order->update_meta_data( '_ftc_topten_payment_url', $res['url_external'] );
            $order->update_meta_data( '_ftc_topten_payment_expiration_utc', $res['expiration_utc'] );
            $order->update_meta_data( '_ftc_topten_payment_idadquiria', $res['id_adquiria'] );
            $order->save();

            return array(
                'result'   => 'success',
                'redirect' => $res['url_external'],
            );
        } catch ( \Throwable $e ) {
            FTC_Logger::instance()->error(
                'gateway',
                'create_payment_failed',
                array(
                    'order_id' => $order_id,
                    'error'    => $e->getMessage(),
                    'payload'  => array(
                        'Carr_Id'      => $cart_id,
                        'Coge_Id_Pago' => $coge_id,
                        'Mepa_Id'      => $mepa_id,
                    ),
                )
            );
            wc_add_notice( __( 'No pudimos iniciar el pago. Intenta nuevamente o elige otro método.', 'ferk-topten-connector' ), 'error' );

            return array( 'result' => 'fail' );
        }
    }

    /**
     * Retrieve gateway configuration merged with global settings.
     *
     * @return array
     */
    public function get_gateway_config() {
        $defaults = array();
        if ( class_exists( 'FTC_Settings' ) ) {
            $settings_defaults = FTC_Settings::get_defaults();
            if ( isset( $settings_defaults['credentials'] ) && is_array( $settings_defaults['credentials'] ) ) {
                $defaults = $settings_defaults['credentials'];
            }
        }

        $stored_settings = get_option( FTC_Utils::option_name(), array() );
        $credentials      = array();

        if ( isset( $stored_settings['credentials'] ) && is_array( $stored_settings['credentials'] ) ) {
            $credentials = $stored_settings['credentials'];
        }

        $credentials = wp_parse_args( $credentials, $defaults );

        return array(
            'sandbox'             => isset( $credentials['sandbox'] ) ? $credentials['sandbox'] : 'yes',
            'base_url_sandbox'    => isset( $credentials['base_url_sandbox'] ) ? $credentials['base_url_sandbox'] : '',
            'base_url_production' => isset( $credentials['base_url_production'] ) ? $credentials['base_url_production'] : '',
            'api_key'             => isset( $credentials['api_key'] ) ? $credentials['api_key'] : '',
            'webhook_secret'      => isset( $credentials['webhook_secret'] ) ? $credentials['webhook_secret'] : '',
            'timeout'             => isset( $credentials['timeout'] ) ? $credentials['timeout'] : 30,
            'retries'             => isset( $credentials['retries'] ) ? $credentials['retries'] : 3,
        );
    }
}
