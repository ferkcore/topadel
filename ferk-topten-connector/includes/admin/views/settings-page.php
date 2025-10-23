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

    <form method="post" action="options.php">
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
                            <th scope="row"><?php esc_html_e( 'API Key', 'ferk-topten-connector' ); ?></th>
                            <td>
                                <input type="password" class="regular-text" name="ftc_settings[credentials][api_key]" value="<?php echo esc_attr( FTC_Utils::array_get( $settings, 'credentials.api_key', '' ) ); ?>" autocomplete="off" />
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
            <?php elseif ( 'tools' === $current_tab ) : ?>
                <p><?php esc_html_e( 'Utiliza estas herramientas para diagnosticar el conector.', 'ferk-topten-connector' ); ?></p>
                <p>
                    <button type="button" class="button button-secondary" id="ftc-test-connection">
                        <?php esc_html_e( 'Testear conexión', 'ferk-topten-connector' ); ?>
                    </button>
                    <span class="spinner" id="ftc-test-spinner"></span>
                    <span id="ftc-test-result" class="ftc-test-message" aria-live="polite"></span>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
                    <?php wp_nonce_field( 'ftc_export_logs' ); ?>
                    <input type="hidden" name="action" value="ftc_export_logs" />
                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Exportar logs CSV', 'ferk-topten-connector' ); ?></button>
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
