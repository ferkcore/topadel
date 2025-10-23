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
}
