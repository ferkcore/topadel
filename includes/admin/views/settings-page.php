<?php
/**
 * Settings page view.
 *
 * @package Ferk_Topten_Connector\Includes\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'credentials'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! isset( $tabs[ $current_tab ] ) ) {
    $current_tab = 'credentials';
}
?>
<div class="wrap ftc-settings">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
            <?php
            $url   = add_query_arg(
                array(
                    'page' => 'ftc-settings',
                    'tab'  => $tab_key,
                ),
                admin_url( 'admin.php' )
            );
            $class = ( $current_tab === $tab_key ) ? ' nav-tab-active' : '';
            ?>
            <a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo esc_attr( $class ); ?>">
                <?php echo esc_html( $tab_label ); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <form method="post" action="options.php" enctype="multipart/form-data">
        <?php settings_fields( 'ftc_settings_group' ); ?>
        <input type="hidden" name="ftc_settings[current_tab]" value="<?php echo esc_attr( $current_tab ); ?>" />

        <div class="ftc-tab-content">
            <?php if ( 'credentials' === $current_tab ) : ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Sandbox', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ftc_settings[credentials][sandbox]" value="yes" <?php checked( 'yes', FTC_Utils::array_get( $settings, 'credentials.sandbox', 'yes' ) ); ?> />
                                    <?php esc_html_e( 'Usar entorno sandbox', 'ferk-topten-connector' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Base URL Sandbox', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <input type="url" class="regular-text" name="ftc_settings[credentials][base_url_sandbox]" value="<?php echo esc_attr( FTC_Utils::array_get( $settings, 'credentials.base_url_sandbox', '' ) ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Base URL Producción', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <input type="url" class="regular-text" name="ftc_settings[credentials][base_url_production]" value="<?php echo esc_attr( FTC_Utils::array_get( $settings, 'credentials.base_url_production', '' ) ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Webhook Secret', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <input type="password" class="regular-text" name="ftc_settings[credentials][webhook_secret]" value="<?php echo esc_attr( FTC_Utils::array_get( $settings, 'credentials.webhook_secret', '' ) ); ?>" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Timeout (segundos)', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <input type="number" min="5" max="120" name="ftc_settings[credentials][timeout]" value="<?php echo esc_attr( (int) FTC_Utils::array_get( $settings, 'credentials.timeout', 30 ) ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Reintentos', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <input type="number" min="0" max="5" name="ftc_settings[credentials][retries]" value="<?php echo esc_attr( (int) FTC_Utils::array_get( $settings, 'credentials.retries', 3 ) ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Modo depuración', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ftc_settings[credentials][debug_mode]" value="yes" <?php checked( 'yes', FTC_Utils::array_get( $settings, 'credentials.debug_mode', 'no' ) ); ?> />
                                    <?php esc_html_e( 'Registrar información adicional en el log.', 'ferk-topten-connector' ); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php elseif ( 'sync' === $current_tab ) : ?>
                <p><?php esc_html_e( 'Pronto podrás gestionar opciones de sincronización adicionales.', 'ferk-topten-connector' ); ?></p>
            <?php elseif ( 'products' === $current_tab ) : ?>
                <?php wp_nonce_field( 'ftc_products_map', 'ftc_products_map_nonce' ); ?>
                <div class="ftc-products-tab">
                    <p><?php esc_html_e( 'Consulta TopTen para encontrar coincidencias por SKU y guardar el metadato id_topten en productos o variaciones.', 'ferk-topten-connector' ); ?></p>
                    <p class="description"><?php esc_html_e( 'Se procesarán todas las páginas disponibles utilizando siempre la entidad 51 y los resultados se escribirán automáticamente en id_topten.', 'ferk-topten-connector' ); ?></p>
                    <div class="ftc-products-actions">
                        <button type="button" class="button button-secondary" id="ftc-products-map-run"><?php esc_html_e( 'Traer y mapear por SKU', 'ferk-topten-connector' ); ?></button>
                        <span class="spinner" id="ftc-products-spinner"></span>
                        <button type="button" class="button" id="ftc-products-export" disabled><?php esc_html_e( 'Exportar CSV de mapeo', 'ferk-topten-connector' ); ?></button>
                    </div>
                    <div id="ftc-products-summary" class="ftc-products-summary" aria-live="polite"></div>
                    <div id="ftc-products-truncated" class="notice notice-warning" style="display:none;">
                        <p><?php printf( esc_html__( 'El resultado fue truncado a %d filas. Ajusta la búsqueda o exporta múltiples veces si es necesario.', 'ferk-topten-connector' ), (int) FTC_Products_Importer::MAX_RESPONSE_ROWS ); ?></p>
                    </div>
                    <div class="ftc-products-table-wrapper">
                        <table class="widefat striped" id="ftc-products-results">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'WC Product ID', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'SKU Woo', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'id_topten encontrado', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'Fuente', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'Acción', 'ferk-topten-connector' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5"><?php esc_html_e( 'Aún no hay resultados.', 'ferk-topten-connector' ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ( 'matcher' === $current_tab ) : ?>
                <?php wp_nonce_field( 'ftc_products_matcher', 'ftc_products_matcher_nonce' ); ?>
                <div class="ftc-matcher-tab" id="ftc-matcher-tab">
                    <p><?php esc_html_e( 'Sube un archivo con columnas SKU e ID TopTen para actualizar el id_topten de tus productos.', 'ferk-topten-connector' ); ?></p>
                    <p class="description"><?php esc_html_e( 'Se aceptan archivos CSV o XLSX con encabezados reconocibles. El ID debe corresponder al producto en TopTen.', 'ferk-topten-connector' ); ?></p>
                    <div class="ftc-matcher-upload">
                        <label for="ftc-matcher-file" class="ftc-matcher-label"><?php esc_html_e( 'Archivo', 'ferk-topten-connector' ); ?></label>
                        <input type="file" id="ftc-matcher-file" accept=".csv,.xlsx" />
                        <p class="description"><?php esc_html_e( 'Incluye al menos las columnas SKU e ID TopTen. El límite de archivo es 5MB.', 'ferk-topten-connector' ); ?></p>
                    </div>
                    <div class="ftc-matcher-actions">
                        <button type="button" class="button button-secondary" id="ftc-matcher-run"><?php esc_html_e( 'Subir y machear', 'ferk-topten-connector' ); ?></button>
                        <span class="spinner" id="ftc-matcher-spinner"></span>
                    </div>
                    <div id="ftc-matcher-status" class="ftc-matcher-status"></div>
                    <div id="ftc-matcher-error" class="notice notice-error" style="display:none;">
                        <p></p>
                    </div>
                    <div id="ftc-matcher-summary" class="ftc-matcher-summary"></div>
                    <div class="ftc-matcher-table-wrapper">
                        <table class="widefat striped" id="ftc-matcher-results">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Fila', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'SKU', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'ID TopTen', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'Producto WooCommerce', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'Resultado', 'ferk-topten-connector' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5"><?php esc_html_e( 'Aún no hay resultados.', 'ferk-topten-connector' ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ( 'search' === $current_tab ) : ?>
                <?php wp_nonce_field( 'ftc_products_search', 'ftc_products_search_nonce' ); ?>
                <div class="ftc-search-tab">
                    <p><?php esc_html_e( 'Consulta el catálogo público de TopTen sin modificar los productos de WooCommerce.', 'ferk-topten-connector' ); ?></p>
                    <div class="ftc-search-controls">
                        <div class="ftc-search-field">
                            <label for="ftc-search-term"><?php esc_html_e( 'Términos', 'ferk-topten-connector' ); ?></label>
                            <input type="text" id="ftc-search-term" class="regular-text" placeholder="<?php esc_attr_e( 'SKU, nombre o código interno', 'ferk-topten-connector' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Separa múltiples términos con comas.', 'ferk-topten-connector' ); ?></p>
                        </div>
                        <div class="ftc-search-field ftc-search-field-small">
                            <label for="ftc-search-page"><?php esc_html_e( 'Página', 'ferk-topten-connector' ); ?></label>
                            <input type="number" id="ftc-search-page" class="small-text" min="1" value="1" />
                        </div>
                    </div>
                    <div class="ftc-search-actions">
                        <button type="button" class="button button-secondary" id="ftc-search-run"><?php esc_html_e( 'Buscar productos', 'ferk-topten-connector' ); ?></button>
                        <span class="spinner" id="ftc-search-spinner"></span>
                        <span id="ftc-search-status" class="description"></span>
                    </div>
                    <div id="ftc-search-error" class="notice notice-error" style="display:none;">
                        <p></p>
                    </div>
                    <div id="ftc-search-meta" class="description"></div>
                    <div class="ftc-search-table-wrapper">
                        <table class="widefat striped" id="ftc-search-results">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Prod_Id', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'SKU', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'Nombre', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'Marca', 'ferk-topten-connector' ); ?></th>
                                    <th><?php esc_html_e( 'Precio', 'ferk-topten-connector' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5"><?php esc_html_e( 'Aún no hay resultados.', 'ferk-topten-connector' ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ( 'tools' === $current_tab ) : ?>
                <p><?php esc_html_e( 'Utiliza estas herramientas para diagnosticar el conector.', 'ferk-topten-connector' ); ?></p>
                <?php
                $webhook_url = rest_url( 'ftc/v1/getnet/webhook' );
                $return_url  = rest_url( 'ftc/v1/getnet/return' );
                ?>
                <h3><?php esc_html_e( 'URLs de integración', 'ferk-topten-connector' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Webhook URL', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <div class="ftc-copy-field">
                                    <input type="text" id="ftc-webhook-url" class="regular-text code" readonly value="<?php echo esc_attr( $webhook_url ); ?>" />
                                    <button type="button" class="button button-secondary ftc-copy-button" data-copy-target="#ftc-webhook-url">
                                        <?php esc_html_e( 'Copiar', 'ferk-topten-connector' ); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Return URL', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <div class="ftc-copy-field">
                                    <input type="text" id="ftc-return-url" class="regular-text code" readonly value="<?php echo esc_attr( $return_url ); ?>" />
                                    <button type="button" class="button button-secondary ftc-copy-button" data-copy-target="#ftc-return-url">
                                        <?php esc_html_e( 'Copiar', 'ferk-topten-connector' ); ?>
                                    </button>
                                </div>
                                <p class="description"><?php esc_html_e( 'Configura esta URL como webhook en TopTen / PlaceToPay para recibir confirmaciones de pago.', 'ferk-topten-connector' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e( 'Si la API permite callbacks por request, puedes habilitarlo desde los ajustes del gateway.', 'ferk-topten-connector' ); ?></p>
                <p>
                    <button type="button" class="button button-secondary" id="ftc-test-connection">
                        <?php esc_html_e( 'Testear conexión', 'ferk-topten-connector' ); ?>
                    </button>
                    <span class="spinner" id="ftc-test-spinner"></span>
                    <span id="ftc-test-result" class="ftc-test-message" aria-live="polite"></span>
                </p>
                <p>
                    <button type="button" class="button button-secondary" id="ftc-test-user">
                        <?php esc_html_e( 'Probar creación de usuario (sandbox)', 'ferk-topten-connector' ); ?>
                    </button>
                    <span class="spinner" id="ftc-test-user-spinner"></span>
                    <span id="ftc-test-user-result" class="ftc-test-message" aria-live="polite"></span>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
                    <?php wp_nonce_field( 'ftc_export_logs' ); ?>
                    <input type="hidden" name="action" value="ftc_export_logs" />
                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Exportar logs CSV', 'ferk-topten-connector' ); ?></button>
                </form>
                <?php
                $tool_status          = isset( $_GET['ftc_cart_tool'] ) ? sanitize_key( wp_unslash( $_GET['ftc_cart_tool'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $tool_message         = isset( $_GET['ftc_cart_tool_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ftc_cart_tool_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $tool_cart_id         = isset( $_GET['ftc_cart_tool_id'] ) ? absint( wp_unslash( $_GET['ftc_cart_tool_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $payment_tool_status  = isset( $_GET['ftc_payment_tool'] ) ? sanitize_key( wp_unslash( $_GET['ftc_payment_tool'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $payment_tool_message = isset( $_GET['ftc_payment_tool_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ftc_payment_tool_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $payment_tool_token   = isset( $_GET['ftc_payment_tool_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ftc_payment_tool_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $payment_tool_url     = isset( $_GET['ftc_payment_tool_url'] ) ? esc_url_raw( wp_unslash( $_GET['ftc_payment_tool_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

                if ( 'success' === $tool_status && $tool_cart_id ) {
                    printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( sprintf( __( 'Carrito de prueba creado correctamente. ID: %d', 'ferk-topten-connector' ), $tool_cart_id ) ) );
                } elseif ( 'error' === $tool_status && $tool_message ) {
                    printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $tool_message ) );
                }
                if ( 'success' === $payment_tool_status ) {
                    echo '<div class="notice notice-success"><p>' . esc_html( $payment_tool_message ? $payment_tool_message : __( 'Sesión de pago creada correctamente.', 'ferk-topten-connector' ) ) . '</p>';
                    if ( $payment_tool_token ) {
                        echo '<p><strong>' . esc_html__( 'Token:', 'ferk-topten-connector' ) . '</strong> ' . esc_html( $payment_tool_token ) . '</p>';
                    }
                    if ( $payment_tool_url ) {
                        echo '<p><a href="' . esc_url( $payment_tool_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir URL de pago', 'ferk-topten-connector' ) . '</a></p>';
                    }
                    echo '</div>';
                } elseif ( 'error' === $payment_tool_status && $payment_tool_message ) {
                    printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $payment_tool_message ) );
                }
                ?>
                <h3><?php esc_html_e( 'Probar creación de carrito (sandbox)', 'ferk-topten-connector' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'ftc_tools_create_cart', 'ftc_tools_create_cart_nonce' ); ?>
                    <input type="hidden" name="action" value="ftc_test_create_cart" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="ftc_tool_user_id"><?php esc_html_e( 'Usuario TopTen (Usua_Cod)', 'ferk-topten-connector' ); ?></label></th>
                                <td><input type="number" min="1" name="ftc_tool_user_id" id="ftc_tool_user_id" value="" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ftc_tool_prod_id"><?php esc_html_e( 'Prod_Id de prueba', 'ferk-topten-connector' ); ?></label></th>
                                <td><input type="number" min="1" name="ftc_tool_prod_id" id="ftc_tool_prod_id" value="" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ftc_tool_qty"><?php esc_html_e( 'Cantidad', 'ferk-topten-connector' ); ?></label></th>
                                <td><input type="number" min="1" name="ftc_tool_qty" id="ftc_tool_qty" value="1" class="small-text" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Crear carrito de prueba', 'ferk-topten-connector' ); ?></button>
                    </p>
                </form>
                <h3><?php esc_html_e( 'Probar creación de pago (sandbox)', 'ferk-topten-connector' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'ftc_tools_create_payment', 'ftc_tools_create_payment_nonce' ); ?>
                    <input type="hidden" name="action" value="ftc_test_create_payment" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="ftc_tool_carr_id"><?php esc_html_e( 'Carr_Id', 'ferk-topten-connector' ); ?></label></th>
                                <td><input type="number" min="1" name="ftc_tool_carr_id" id="ftc_tool_carr_id" value="" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ftc_tool_coge_id"><?php esc_html_e( 'Coge_Id_Pago', 'ferk-topten-connector' ); ?></label></th>
                                <td>
                                    <select name="ftc_tool_coge_id" id="ftc_tool_coge_id">
                                        <option value="27">27 - PlaceToPay</option>
                                        <option value="28">28 - PlaceToPay Santander</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ftc_tool_mepa_id"><?php esc_html_e( 'Mepa_Id', 'ferk-topten-connector' ); ?></label></th>
                                <td>
                                    <select name="ftc_tool_mepa_id" id="ftc_tool_mepa_id">
                                        <option value="1">1 - Visa</option>
                                        <option value="2">2 - MasterCard</option>
                                        <option value="23">23 - Santander</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ftc_tool_usua_cod"><?php esc_html_e( 'Usuario TopTen (Usua_Cod)', 'ferk-topten-connector' ); ?></label></th>
                                <td><input type="number" min="1" name="ftc_tool_usua_cod" id="ftc_tool_usua_cod" value="" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ftc_tool_sucursal"><?php esc_html_e( 'Sucursal (opcional)', 'ferk-topten-connector' ); ?></label></th>
                                <td><input type="number" min="1" name="ftc_tool_sucursal" id="ftc_tool_sucursal" value="" class="regular-text" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Crear sesión de pago de prueba', 'ferk-topten-connector' ); ?></button>
                    </p>
                </form>
            <?php elseif ( 'logs' === $current_tab ) : ?>
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'ftc_logs';
                $logs  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 50" );
                ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'ferk-topten-connector' ); ?></th>
                            <th><?php esc_html_e( 'Nivel', 'ferk-topten-connector' ); ?></th>
                            <th><?php esc_html_e( 'Contexto', 'ferk-topten-connector' ); ?></th>
                            <th><?php esc_html_e( 'Mensaje', 'ferk-topten-connector' ); ?></th>
                            <th><?php esc_html_e( 'Fecha', 'ferk-topten-connector' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $logs ) ) : ?>
                            <?php foreach ( $logs as $log ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $log->id ); ?></td>
                                    <td><?php echo esc_html( strtoupper( $log->level ) ); ?></td>
                                    <td><?php echo esc_html( $log->context ); ?></td>
                                    <td>
                                        <strong><?php echo esc_html( $log->message ); ?></strong><br />
                                        <code><?php echo esc_html( $log->payload_json ); ?></code>
                                    </td>
                                    <td><?php echo esc_html( get_date_from_gmt( $log->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="5"><?php esc_html_e( 'Sin registros por el momento.', 'ferk-topten-connector' ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if ( in_array( $current_tab, array( 'credentials', 'sync' ), true ) ) : ?>
            <?php submit_button(); ?>
        <?php endif; ?>
    </form>
</div>
