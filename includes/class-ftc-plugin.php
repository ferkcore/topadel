<?php
/**
 * Core plugin controller.
 *
 * @package Ferk_Topten_Connector\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-validator.php';
require_once FTC_PLUGIN_DIR . 'includes/db/class-ftc-db.php';
require_once FTC_PLUGIN_DIR . 'includes/admin/class-ftc-settings.php';
require_once FTC_PLUGIN_DIR . 'includes/admin/class-ftc-admin-assets.php';
require_once FTC_PLUGIN_DIR . 'includes/api/class-ftc-client.php';
require_once FTC_PLUGIN_DIR . 'includes/api/class-ftc-webhooks.php';
require_once FTC_PLUGIN_DIR . 'includes/sync/class-ftc-action-scheduler.php';
require_once FTC_PLUGIN_DIR . 'includes/sync/class-ftc-customer-sync.php';
require_once FTC_PLUGIN_DIR . 'includes/sync/class-ftc-cart-sync.php';
require_once FTC_PLUGIN_DIR . 'includes/order/class-ftc-jsonpedido.php';
require_once FTC_PLUGIN_DIR . 'includes/order/class-ftc-order-meta.php';
require_once FTC_PLUGIN_DIR . 'includes/order/class-ftc-order-status.php';
require_once FTC_PLUGIN_DIR . 'includes/products/class-ftc-products-importer.php';
require_once FTC_PLUGIN_DIR . 'includes/products/class-ftc-product-meta.php';

/**
 * Main plugin class.
 */
class FTC_Plugin {
    /**
     * Singleton instance.
     *
     * @var FTC_Plugin|null
     */
    protected static $instance = null;

    /**
     * Settings handler.
     *
     * @var FTC_Settings
     */
    protected $settings_page;

    /**
     * Admin assets handler.
     *
     * @var FTC_Admin_Assets
     */
    protected $admin_assets;

    /**
     * Webhooks handler.
     *
     * @var FTC_Webhooks
     */
    protected $webhooks;

    /**
     * Order meta handler.
     *
     * @var FTC_Order_Meta
     */
    protected $order_meta;

    /**
     * Product meta handler.
     *
     * @var FTC_Product_Meta
     */
    protected $product_meta;

    /**
     * Gateway instance cache.
     *
     * @var FTC_Gateway_Getnet|null
     */
    protected $gateway_instance = null;

    /**
     * Database helper instance.
     *
     * @var FTC_DB|null
     */
    protected $db_instance = null;

    /**
     * Cart sync instance.
     *
     * @var FTC_Cart_Sync|null
     */
    protected $cart_sync_instance = null;

    /**
     * Products importer instance.
     *
     * @var FTC_Products_Importer|null
     */
    protected $products_importer_instance = null;

    /**
     * Missing dependencies.
     *
     * @var array
     */
    protected $missing_dependencies = array();

    /**
     * Plugin settings cache.
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Customer sync instance.
     *
     * @var FTC_Customer_Sync|null
     */
    protected $customer_sync_instance = null;

    /**
     * Get singleton instance.
     *
     * @return FTC_Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Alias for get_instance.
     *
     * @return FTC_Plugin
     */
    public static function instance() {
        return self::get_instance();
    }

    /**
     * Constructor.
     */
    protected function __construct() {
        $this->settings = get_option( FTC_Utils::option_name(), FTC_Settings::get_defaults() );

        $this->init_hooks();
    }

    /**
     * Initialise hooks.
     */
    protected function init_hooks() {
        if ( ! $this->check_dependencies() ) {
            add_action( 'admin_notices', array( $this, 'render_missing_dependencies_notice' ) );
            return;
        }

        require_once FTC_PLUGIN_DIR . 'includes/gateway/class-ftc-gateway-getnet.php';

        $this->settings_page = new FTC_Settings();
        $this->settings_page->hooks();

        $this->admin_assets = new FTC_Admin_Assets();
        $this->admin_assets->hooks();

        $this->webhooks = new FTC_Webhooks();
        $this->webhooks->hooks();

        $this->order_meta = new FTC_Order_Meta();
        $this->order_meta->hooks();

        $this->product_meta = new FTC_Product_Meta();
        $this->product_meta->hooks();

        add_filter( 'woocommerce_payment_gateways', array( $this, 'register_payment_gateway' ) );
        add_action( 'admin_init', array( $this, 'maybe_seed_settings' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_retry_notice' ) );
        add_action( 'update_option_' . FTC_Utils::option_name(), array( $this, 'refresh_settings_cache' ), 10, 2 );
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
        add_action( 'ftc_auth_token_refresh', array( $this, 'handle_token_refresh' ) );
        add_action( 'init', array( $this, 'maybe_schedule_token_refresh' ) );
    }

    /**
     * Check plugin dependencies.
     *
     * @return bool
     */
    protected function check_dependencies() {
        $this->missing_dependencies = array();

        if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
            $this->missing_dependencies[] = __( 'WordPress 6.0+', 'ferk-topten-connector' );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->missing_dependencies[] = __( 'WooCommerce 8.0+', 'ferk-topten-connector' );
        } elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.0', '<' ) ) {
            $this->missing_dependencies[] = __( 'WooCommerce 8.0+', 'ferk-topten-connector' );
        } elseif ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            $this->missing_dependencies[] = __( 'WooCommerce payment gateway framework', 'ferk-topten-connector' );
        }

        return empty( $this->missing_dependencies );
    }

    /**
     * Render admin notice for missing dependencies.
     */
    public function render_missing_dependencies_notice() {
        if ( empty( $this->missing_dependencies ) ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html( sprintf( __( 'Ferk Topten Connector requiere: %s', 'ferk-topten-connector' ), implode( ', ', $this->missing_dependencies ) ) )
        );
    }

    /**
     * Register payment gateway.
     *
     * @param array $gateways Gateways.
     *
     * @return array
     */
    public function register_payment_gateway( $gateways ) {
        $gateways[] = 'FTC_Gateway_Getnet';

        return $gateways;
    }

    /**
     * Ensure settings exist.
     */
    public function maybe_seed_settings() {
        $current = get_option( FTC_Utils::option_name(), array() );
        if ( empty( $current ) ) {
            update_option( FTC_Utils::option_name(), FTC_Settings::get_defaults() );
        }
    }

    /**
     * Refresh settings cache when option updated.
     *
     * @param mixed $old_value Old value.
     * @param mixed $value     New value.
     */
    public function refresh_settings_cache( $old_value, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $this->settings = is_array( $value ) ? $value : FTC_Settings::get_defaults();
    }

    /**
     * Show retry notices.
     */
    public function maybe_show_retry_notice() {
        if ( ! isset( $_GET['ftc_retry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $status  = sanitize_key( wp_unslash( $_GET['ftc_retry'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $message = isset( $_GET['ftc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ftc_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'success' === $status ) {
            printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Pago recreado correctamente.', 'ferk-topten-connector' ) );
        } elseif ( 'error' === $status ) {
            if ( empty( $message ) ) {
                $message = __( 'Ocurrió un error al recrear el pago.', 'ferk-topten-connector' );
            }
            printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
        }
    }

    /**
     * Get plugin settings.
     *
     * @return array
     */
    public function get_settings() {
        if ( empty( $this->settings ) ) {
            $this->settings = get_option( FTC_Utils::option_name(), FTC_Settings::get_defaults() );
        }

        return $this->settings;
    }

    /**
     * Get setting value by path.
     *
     * @param string $path    Path.
     * @param mixed  $default Default value.
     *
     * @return mixed
     */
    public function get_setting( $path, $default = null ) {
        return FTC_Utils::array_get( $this->get_settings(), $path, $default );
    }

    /**
     * Get client using provided credentials.
     *
     * @param array|null $credentials Credentials.
     *
     * @return FTC_Client
     */
    public function get_client( $credentials = null ) {
        if ( null === $credentials ) {
            $credentials = $this->get_setting( 'credentials', FTC_Settings::get_defaults()['credentials'] );
        }

        return new FTC_Client( $credentials );
    }

    /**
     * Register custom cron schedules.
     *
     * @param array $schedules Schedules.
     *
     * @return array
     */
    public function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['ftc_half_hour'] ) ) {
            $schedules['ftc_half_hour'] = array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => __( 'Cada 30 minutos', 'ferk-topten-connector' ),
            );
        }

        return $schedules;
    }

    /**
     * Ensure token refresh action is scheduled.
     */
    public function maybe_schedule_token_refresh() {
        if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
            if ( ! as_has_scheduled_action( 'ftc_auth_token_refresh' ) ) {
                as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 'ftc_auth_token_refresh' );
            }

            return;
        }

        if ( ! wp_next_scheduled( 'ftc_auth_token_refresh' ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ftc_half_hour', 'ftc_auth_token_refresh' );
        }
    }

    /**
     * Handle scheduled token refresh.
     */
    public function handle_token_refresh() {
        $client = $this->client();
        $logger = FTC_Logger::instance();
        $now    = time();

        foreach ( array( true, false ) as $sandbox ) {
            try {
                $data = $client->get_token( false, array( 'sandbox' => $sandbox ) );
                $exp  = isset( $data['expiration'] ) ? (int) $data['expiration'] : 0;

                if ( $exp <= $now + 300 ) {
                    $client->get_token( true, array( 'sandbox' => $sandbox ) );
                }
            } catch ( Exception $e ) {
                $reason = wp_strip_all_tags( $e->getMessage() );
                if ( function_exists( 'mb_substr' ) ) {
                    $reason = mb_substr( $reason, 0, 160 );
                } else {
                    $reason = substr( $reason, 0, 160 );
                }

                $logger->debug(
                    'auth_token',
                    'Token refresh skipped',
                    array(
                        'sandbox' => $sandbox ? 'yes' : 'no',
                        'reason'  => $reason,
                    )
                );
            }
        }
    }

    /**
     * Alias for get_client.
     *
     * @param array|null $credentials Credentials override.
     *
     * @return FTC_Client
     */
    public function client( $credentials = null ) {
        return $this->get_client( $credentials );
    }

    /**
     * Get client configured for a specific order.
     *
     * @param WC_Order $order Order.
     *
     * @return FTC_Client
     */
    public function get_client_from_order( $order ) {
        $credentials = $this->get_setting( 'credentials', FTC_Settings::get_defaults()['credentials'] );

        if ( $order && 'ftc_getnet_topten' === $order->get_payment_method() ) {
            $credentials = $this->get_gateway_instance()->get_gateway_config();
        }

        return $this->client( $credentials );
    }

    /**
     * Get database helper instance.
     *
     * @return FTC_DB
     */
    public function db() {
        if ( null === $this->db_instance ) {
            $this->db_instance = new FTC_DB();
        }

        return $this->db_instance;
    }

    /**
     * Get cart sync instance.
     *
     * @return FTC_Cart_Sync
     */
    public function cart_sync() {
        if ( null === $this->cart_sync_instance ) {
            $this->cart_sync_instance = new FTC_Cart_Sync();
        }

        return $this->cart_sync_instance;
    }

    /**
     * Get products importer instance.
     *
     * @return FTC_Products_Importer
     */
    public function products_importer() {
        if ( null === $this->products_importer_instance ) {
            $this->products_importer_instance = new FTC_Products_Importer();
        }

        return $this->products_importer_instance;
    }

    /**
     * Get cached gateway instance.
     *
     * @return FTC_Gateway_Getnet
     */
    protected function get_gateway_instance() {
        if ( null === $this->gateway_instance ) {
            $this->gateway_instance = new FTC_Gateway_Getnet();
        }

        return $this->gateway_instance;
    }

    /**
     * Recreate payment for order.
     *
     * @param WC_Order $order Order.
     *
     * @return array
     * @throws Exception When fails.
     */
    public function recreate_payment_for_order( $order ) {
        $gateway      = $this->get_gateway_instance();
        $customer_sync = $this->customer_sync();
        $cart_sync     = $this->cart_sync();
        $user_id       = (int) $customer_sync->get_or_create_topten_user_from_order( $order );
        $order->update_meta_data( '_ftc_topten_user_id', $user_id );
        $order->save();

        $cart_id = (int) $cart_sync->create_topten_cart_from_order( $order, $user_id );

        $currency = $order->get_currency();
        $mone_id  = FTC_Utils::map_currency_to_mone_id( $currency );

        $coge_id  = (int) $gateway->get_option( 'coge_id_pago', 27 );
        $mepa_id  = (int) $gateway->get_option( 'mepa_id', 1 );
        $sucursal = (int) $gateway->get_option( 'sucursal_id', 78 );
        $id_pais  = (int) $gateway->get_option( 'id_pais', 186 );
        $indic    = (string) $gateway->get_option( 'indicativo', '+598' );
        $origen   = (string) $gateway->get_option( 'origen', get_bloginfo( 'name' ) );

        $json_obj = FTC_JsonPedido::build_from_order(
            $order,
            array(
                'usua_cod'     => $user_id,
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
                'order_id' => $order->get_id(),
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

        $client   = $this->client( $gateway->get_gateway_config() );
        $response = $client->create_payment_placetopay( $payload );

        $order->update_meta_data( '_ftc_topten_payment_token', $response['token'] );
        $order->update_meta_data( '_ftc_topten_payment_url', $response['url_external'] );
        $order->update_meta_data( '_ftc_topten_payment_expiration_utc', $response['expiration_utc'] );
        $order->update_meta_data( '_ftc_topten_payment_idadquiria', $response['id_adquiria'] );
        $order->save();

        FTC_Logger::instance()->info(
            'payment_retry',
            __( 'Pago recreado desde administración.', 'ferk-topten-connector' ),
            array(
                'order_id' => $order->get_id(),
                'token'    => $response['token'],
            )
        );

        return $response;
    }

    /**
     * Get customer sync helper.
     *
     * @return FTC_Customer_Sync
     */
    public function customer_sync() {
        if ( null === $this->customer_sync_instance ) {
            $this->customer_sync_instance = new FTC_Customer_Sync( $this->db() );
        }

        return $this->customer_sync_instance;
    }

}
