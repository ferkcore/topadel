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
                'productsMapUrl' => rest_url( 'ftc/v1/admin/products-map' ),
                'productsMaxRows' => FTC_Products_Importer::MAX_RESPONSE_ROWS,
                'editPostUrl' => admin_url( 'post.php' ),
                'messages' => array(
                    'testing' => __( 'Testing connection...', 'ferk-topten-connector' ),
                    'success' => __( 'Connection successful.', 'ferk-topten-connector' ),
                    'error'   => __( 'Connection failed.', 'ferk-topten-connector' ),
                    'userTesting' => __( 'Creando usuario de prueba...', 'ferk-topten-connector' ),
                    'userSuccess' => __( 'Usuario TopTen creado: %s', 'ferk-topten-connector' ),
                    'userError'   => __( 'No se pudo crear el usuario de prueba.', 'ferk-topten-connector' ),
                    'copied'      => __( 'Copiado', 'ferk-topten-connector' ),
                    'copyFallback'=> __( 'Copiar manualmente', 'ferk-topten-connector' ),
                ),
                'productsMessages' => array(
                    'running'       => __( 'Consultando productos...', 'ferk-topten-connector' ),
                    'applying'      => __( 'Aplicando cambios...', 'ferk-topten-connector' ),
                    'error'         => __( 'No se pudo completar el mapeo de productos.', 'ferk-topten-connector' ),
                    'empty'         => __( 'No se encontraron coincidencias.', 'ferk-topten-connector' ),
                    'previewDone'   => __( 'Vista previa generada.', 'ferk-topten-connector' ),
                    'applyDone'     => __( 'Cambios aplicados correctamente.', 'ferk-topten-connector' ),
                    'exportEmpty'   => __( 'No hay datos para exportar.', 'ferk-topten-connector' ),
                    'exportFile'    => __( 'mapeo-topten.csv', 'ferk-topten-connector' ),
                    'toptenSku'     => __( 'SKU TopTen', 'ferk-topten-connector' ),
                ),
                'productsSummaryLabels' => array(
                    'totalTopTen'     => __( 'Productos TopTen procesados', 'ferk-topten-connector' ),
                    'totalWooMatched' => __( 'Coincidencias WooCommerce', 'ferk-topten-connector' ),
                    'saved'           => __( 'Guardados', 'ferk-topten-connector' ),
                    'skipped'         => __( 'Omitidos', 'ferk-topten-connector' ),
                    'already_set'     => __( 'Con id_topten previo', 'ferk-topten-connector' ),
                    'conflicts'       => __( 'Conflictos', 'ferk-topten-connector' ),
                    'pagesProcessed'  => __( 'Páginas consultadas', 'ferk-topten-connector' ),
                ),
                'productsActionLabels' => array(
                    'saved'    => __( 'Guardado', 'ferk-topten-connector' ),
                    'skip'     => __( 'Omitido', 'ferk-topten-connector' ),
                    'conflict' => __( 'Conflicto', 'ferk-topten-connector' ),
                ),
                'productsNotesLabels' => array(
                    'dry_run'          => __( 'solo vista previa', 'ferk-topten-connector' ),
                    'already_set'      => __( 'ya tenía id_topten', 'ferk-topten-connector' ),
                    'overwritten'      => __( 'sobrescrito', 'ferk-topten-connector' ),
                    'multiple_matches' => __( 'múltiples coincidencias', 'ferk-topten-connector' ),
                ),
                'productsSourceLabels' => array(
                    'Producto'    => __( 'Producto', 'ferk-topten-connector' ),
                    'TerminosList'=> __( 'Variación (TerminosList)', 'ferk-topten-connector' ),
                    'variantOf'   => __( 'Var. de', 'ferk-topten-connector' ),
                ),
            )
        );
    }
}
