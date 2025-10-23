<?php
/**
 * Admin assets handler.
 *
 * @package Ferk_Topten_Connector\Includes\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue admin assets.
 */
class FTC_Admin_Assets {
    /**
     * Register hooks.
     */
    public function hooks() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Enqueue scripts and styles when needed.
     *
     * @param string $hook Current screen hook.
     */
    public function enqueue( $hook ) {
        if ( 'woocommerce_page_ftc-settings' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'ftc-admin',
            FTC_PLUGIN_URL . 'includes/admin/css/ftc-admin.css',
            array(),
            FTC_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'ftc-admin',
            FTC_PLUGIN_URL . 'includes/admin/js/ftc-admin.js',
            array( 'jquery' ),
            FTC_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            'ftc-admin',
            'ftcAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ftc_tools_action' ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'testUserUrl' => rest_url( 'ftc/v1/admin/test-user' ),
                'testAuthUrl' => rest_url( 'ftc/v1/admin/test-auth' ),
                'messages' => array(
                    'testing' => __( 'Testing connection...', 'ferk-topten-connector' ),
                    'success' => __( 'Connection successful.', 'ferk-topten-connector' ),
                    'error'   => __( 'Connection failed.', 'ferk-topten-connector' ),
                    'userTesting' => __( 'Creando usuario de prueba...', 'ferk-topten-connector' ),
                    'userSuccess' => __( 'Usuario TopTen creado: %s', 'ferk-topten-connector' ),
                    'userError'   => __( 'No se pudo crear el usuario de prueba.', 'ferk-topten-connector' ),
                    'authTesting' => __( 'Solicitando token...', 'ferk-topten-connector' ),
                    'authSuccess' => __( 'Token obtenido (%1$s..). Expira en %2$d segundos.', 'ferk-topten-connector' ),
                    'authError'   => __( 'No se pudo obtener el token de autenticación.', 'ferk-topten-connector' ),
                    'copied'      => __( 'Copiado', 'ferk-topten-connector' ),
                    'copyFallback'=> __( 'Copiar manualmente', 'ferk-topten-connector' ),
                ),
            )
        );
    }
}
