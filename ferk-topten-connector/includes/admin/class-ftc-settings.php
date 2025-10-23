<?php
/**
 * Admin settings page.
 *
 * @package Ferk_Topten_Connector\Includes\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';

/**
 * Settings page controller.
 */
class FTC_Settings {
    /**
     * Option name.
     */
    const OPTION_NAME = 'ftc_settings';

    /**
     * Capability required.
     */
    const CAPABILITY = 'manage_woocommerce';

    /**
     * Hook settings.
     */
    public function hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ), 99 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_ftc_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_ftc_export_logs', array( $this, 'ajax_export_logs' ) );
        add_action( 'admin_post_ftc_test_create_cart', array( $this, 'handle_test_create_cart' ) );
        add_action( 'admin_post_ftc_test_create_payment', array( $this, 'handle_test_create_payment' ) );
    }

    /**
     * Default settings structure.
     *
     * @return array
     */
    public static function get_defaults() {
        return array(
            'credentials'   => array(
                'sandbox'        => 'yes',
                'base_url_sandbox' => '',
                'base_url_production' => '',
                'api_key'        => '',
                'webhook_secret' => '',
                'timeout'        => 30,
                'retries'        => 3,
                'debug_mode'     => 'no',
            ),
            'sync'          => array(),
            'tools'         => array(),
            'logs'          => array(),
        );
    }

    /**
     * Register submenu.
     */
    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'TopTen Connector', 'ferk-topten-connector' ),
            __( 'TopTen Connector', 'ferk-topten-connector' ),
            self::CAPABILITY,
            'ftc-settings',
            array( $this, 'render_page' )
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting(
            'ftc_settings_group',
            self::OPTION_NAME,
            array( $this, 'sanitize_settings' )
        );
    }

    /**
     * Sanitize settings callback.
     *
     * @param array $input Input data.
     *
     * @return array
     */
    public function sanitize_settings( $input ) {
        $defaults = self::get_defaults();
        $clean    = wp_parse_args( $input, $defaults );

        $clean['credentials']['sandbox']            = FTC_Utils::sanitize_checkbox( FTC_Utils::array_get( $input, 'credentials.sandbox' ) );
        $clean['credentials']['base_url_sandbox']   = esc_url_raw( FTC_Utils::array_get( $input, 'credentials.base_url_sandbox', '' ) );
        $clean['credentials']['base_url_production'] = esc_url_raw( FTC_Utils::array_get( $input, 'credentials.base_url_production', '' ) );
        $clean['credentials']['api_key']            = FTC_Utils::sanitize_text( FTC_Utils::array_get( $input, 'credentials.api_key', '' ) );
        $clean['credentials']['webhook_secret']     = FTC_Utils::sanitize_text( FTC_Utils::array_get( $input, 'credentials.webhook_secret', '' ) );
        $clean['credentials']['timeout']            = max( 5, FTC_Utils::sanitize_int( FTC_Utils::array_get( $input, 'credentials.timeout', 30 ) ) );
        $clean['credentials']['retries']            = min( 5, max( 0, FTC_Utils::sanitize_int( FTC_Utils::array_get( $input, 'credentials.retries', 3 ) ) ) );
        $clean['credentials']['debug_mode']         = FTC_Utils::sanitize_checkbox( FTC_Utils::array_get( $input, 'credentials.debug_mode' ) );

        FTC_Logger::instance()->info( 'settings', __( 'Settings updated.', 'ferk-topten-connector' ) );

        return $clean;
    }

    /**
     * Render settings page.
     */
    public function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ferk-topten-connector' ) );
        }

        $settings = get_option( self::OPTION_NAME, self::get_defaults() );
        $tabs     = $this->get_tabs();

        include FTC_PLUGIN_DIR . 'includes/admin/views/settings-page.php';
    }

    /**
     * Returns available tabs.
     *
     * @return array
     */
    public function get_tabs() {
        return array(
            'credentials' => __( 'Credenciales', 'ferk-topten-connector' ),
            'sync'        => __( 'Sincronización', 'ferk-topten-connector' ),
            'tools'       => __( 'Herramientas', 'ferk-topten-connector' ),
            'logs'        => __( 'Logs', 'ferk-topten-connector' ),
        );
    }

    /**
     * Handle AJAX to test connection.
     */
    public function ajax_test_connection() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( __( 'Permission denied.', 'ferk-topten-connector' ) );
        }

        check_ajax_referer( 'ftc_tools_action', 'nonce' );

        $settings = get_option( self::OPTION_NAME, self::get_defaults() );

        if ( class_exists( 'FTC_Plugin' ) ) {
            $client = FTC_Plugin::instance()->get_client( $settings['credentials'] );
        } else {
            require_once FTC_PLUGIN_DIR . 'includes/api/class-ftc-client.php';
            $client = new FTC_Client( $settings['credentials'] );
        }

        try {
            $response = $client->health();
            wp_send_json_success( array(
                'message'  => __( 'Connection successful.', 'ferk-topten-connector' ),
                'response' => $response,
            ) );
        } catch ( Exception $e ) {
            FTC_Logger::instance()->error( 'test_connection', $e->getMessage() );
            wp_send_json_error( array(
                'message' => $e->getMessage(),
            ) );
        }
    }

    /**
     * Handle AJAX to export logs as CSV.
     */
    public function ajax_export_logs() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied.', 'ferk-topten-connector' ) );
        }

        check_admin_referer( 'ftc_export_logs' );

        global $wpdb;
        $table = $wpdb->prefix . 'ftc_logs';
        $logs  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1000", ARRAY_A );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=ftc-logs.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'id', 'level', 'context', 'message', 'payload', 'created_at' ) );
        foreach ( $logs as $log ) {
            fputcsv(
                $output,
                array(
                    $log['id'],
                    $log['level'],
                    $log['context'],
                    $log['message'],
                    $log['payload_json'],
                    $log['created_at'],
                )
            );
        }
        fclose( $output );
        exit;
    }

    /**
     * Handle sandbox cart creation test.
     */
    public function handle_test_create_cart() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied.', 'ferk-topten-connector' ) );
        }

        check_admin_referer( 'ftc_tools_create_cart', 'ftc_tools_create_cart_nonce' );

        $user_id = isset( $_POST['ftc_tool_user_id'] ) ? absint( wp_unslash( $_POST['ftc_tool_user_id'] ) ) : 0;
        $prod_id = isset( $_POST['ftc_tool_prod_id'] ) ? absint( wp_unslash( $_POST['ftc_tool_prod_id'] ) ) : 0;
        $qty     = isset( $_POST['ftc_tool_qty'] ) ? absint( wp_unslash( $_POST['ftc_tool_qty'] ) ) : 1;

        $redirect = add_query_arg(
            array(
                'page' => 'ftc-settings',
                'tab'  => 'tools',
            ),
            admin_url( 'admin.php' )
        );

        if ( $user_id <= 0 || $prod_id <= 0 ) {
            $redirect = add_query_arg(
                array(
                    'ftc_cart_tool'         => 'error',
                    'ftc_cart_tool_message' => rawurlencode( __( 'Debes ingresar un usuario y producto de prueba válidos.', 'ferk-topten-connector' ) ),
                ),
                $redirect
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $qty = max( 1, $qty );

        $settings    = get_option( self::OPTION_NAME, self::get_defaults() );
        $credentials = isset( $settings['credentials'] ) ? $settings['credentials'] : self::get_defaults()['credentials'];
        $credentials['sandbox'] = 'yes';

        $client = FTC_Plugin::instance()->client( $credentials );

        $payload = array(
            'Usua_Cod'     => $user_id,
            'CartProducts' => array(
                array(
                    'Prod_Id'  => $prod_id,
                    'Quantity' => $qty,
                ),
            ),
        );

        try {
            $cart_id = (int) $client->create_cart_external( $payload, array( 'sandbox' => true ) );

            if ( $cart_id <= 0 ) {
                throw new Exception( 'TopTen AddCartProductExternal retornó 0.' );
            }

            $redirect = add_query_arg(
                array(
                    'ftc_cart_tool'    => 'success',
                    'ftc_cart_tool_id' => $cart_id,
                ),
                $redirect
            );
        } catch ( Exception $e ) {
            FTC_Logger::instance()->error( 'tools', 'sandbox_cart_failed', array( 'error' => $e->getMessage() ) );
            $redirect = add_query_arg(
                array(
                    'ftc_cart_tool'         => 'error',
                    'ftc_cart_tool_message' => rawurlencode( $e->getMessage() ),
                ),
                $redirect
            );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Handle sandbox payment creation test.
     */
    public function handle_test_create_payment() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'ferk-topten-connector' ) );
        }

        $nonce = isset( $_POST['ftc_tools_create_payment_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ftc_tools_create_payment_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'ftc_tools_create_payment' ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'ferk-topten-connector' ) );
        }

        $redirect = add_query_arg(
            array(
                'page' => 'ftc-settings',
                'tab'  => 'tools',
            ),
            admin_url( 'admin.php' )
        );

        $carr_id  = isset( $_POST['ftc_tool_carr_id'] ) ? absint( wp_unslash( $_POST['ftc_tool_carr_id'] ) ) : 0;
        $coge_id  = isset( $_POST['ftc_tool_coge_id'] ) ? absint( wp_unslash( $_POST['ftc_tool_coge_id'] ) ) : 0;
        $mepa_id  = isset( $_POST['ftc_tool_mepa_id'] ) ? absint( wp_unslash( $_POST['ftc_tool_mepa_id'] ) ) : 0;
        $usua_cod = isset( $_POST['ftc_tool_usua_cod'] ) ? absint( wp_unslash( $_POST['ftc_tool_usua_cod'] ) ) : 0;
        $sucursal = isset( $_POST['ftc_tool_sucursal'] ) ? absint( wp_unslash( $_POST['ftc_tool_sucursal'] ) ) : 0;

        if ( $carr_id <= 0 || $coge_id <= 0 || $mepa_id <= 0 || $usua_cod <= 0 ) {
            $redirect = add_query_arg(
                array(
                    'ftc_payment_tool'         => 'error',
                    'ftc_payment_tool_message' => rawurlencode( __( 'Completa todos los campos obligatorios.', 'ferk-topten-connector' ) ),
                ),
                $redirect
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $settings    = get_option( self::OPTION_NAME, self::get_defaults() );
        $credentials = isset( $settings['credentials'] ) ? $settings['credentials'] : self::get_defaults()['credentials'];
        $client      = FTC_Plugin::instance()->client( $credentials );

        $sucursal_id  = $sucursal > 0 ? $sucursal : 78;
        $origen_label = get_bloginfo( 'name' );

        $json_pedido = array(
            'request'   => array(
                'infoPago'          => array(
                    'mone_Id'            => 2,
                    'installments'       => 0,
                    'captureDataIframe'  => false,
                    'paymentMethodId'    => '',
                    'tokenPayment'       => '',
                    'nombreCompletoPago' => 'Sandbox Tester',
                    'documento'          => '99999999',
                    'tipoDocumento'      => 'Cédula de identidad',
                    'email'              => 'sandbox@example.com',
                    'coge_Id_Pago'       => $coge_id,
                    'mepa_Id'            => $mepa_id,
                    'valid'              => true,
                ),
                'facturacionPedido' => array(
                    'nombres'         => 'Sandbox',
                    'apellidos'       => 'Tester',
                    'tipoDocumento'   => 'Cédula de identidad',
                    'numeroDocumento' => '99999999',
                    'telefono'        => '29000000',
                    'indicativo'      => '+598',
                    'rut'             => false,
                    'idPais'          => 186,
                    'razonSocial'     => '',
                    'numRut'          => '',
                ),
                'entregaUsuario'    => array(
                    'sucu_Id'           => $sucursal_id,
                    'personaRetiro'     => 'SANDBOX TESTER',
                    'direccionId'       => null,
                    'coes_Id_Logistica' => null,
                    'ventanaHoraria'    => '',
                    'coma_Id'           => null,
                    'DiasEnvio'         => array(),
                ),
                'ipUsuario'         => '127.0.0.1',
                'infoExtra'         => 'Prueba sandbox desde herramientas',
                'mone_Id'           => 2,
                'codigoCupon'       => '',
                'usua_Cod'          => $usua_cod,
                'origen'            => $origen_label,
                'productosPedido'   => array(),
            ),
            'cartItems' => new stdClass(),
        );

        $payload = array(
            'Carr_Id'      => $carr_id,
            'Coge_Id_Pago' => $coge_id,
            'Mepa_Id'      => $mepa_id,
            'JsonPedido'   => wp_json_encode( $json_pedido, JSON_UNESCAPED_UNICODE ),
        );

        try {
            $response = $client->create_payment_placetopay( $payload );

            $redirect = add_query_arg(
                array(
                    'ftc_payment_tool'         => 'success',
                    'ftc_payment_tool_message' => rawurlencode( __( 'Sesión de pago creada correctamente.', 'ferk-topten-connector' ) ),
                    'ftc_payment_tool_token'   => rawurlencode( $response['token'] ),
                    'ftc_payment_tool_url'     => rawurlencode( $response['url_external'] ),
                ),
                $redirect
            );
        } catch ( Exception $e ) {
            FTC_Logger::instance()->error(
                'tools',
                'create_payment_failed',
                array(
                    'error'   => $e->getMessage(),
                    'payload' => array(
                        'Carr_Id'      => $carr_id,
                        'Coge_Id_Pago' => $coge_id,
                        'Mepa_Id'      => $mepa_id,
                    ),
                )
            );

            $redirect = add_query_arg(
                array(
                    'ftc_payment_tool'         => 'error',
                    'ftc_payment_tool_message' => rawurlencode( $e->getMessage() ),
                ),
                $redirect
            );
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
