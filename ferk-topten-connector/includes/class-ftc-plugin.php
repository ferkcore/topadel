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
require_once FTC_PLUGIN_DIR . 'includes/order/class-ftc-order-meta.php';
require_once FTC_PLUGIN_DIR . 'includes/order/class-ftc-order-status.php';
require_once FTC_PLUGIN_DIR . 'includes/gateway/class-ftc-gateway-getnet.php';

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

        $this->settings_page = new FTC_Settings();
        $this->settings_page->hooks();

        $this->admin_assets = new FTC_Admin_Assets();
        $this->admin_assets->hooks();

        $this->webhooks = new FTC_Webhooks();
        $this->webhooks->hooks();

        $this->order_meta = new FTC_Order_Meta();
        $this->order_meta->hooks();

        add_filter( 'woocommerce_payment_gateways', array( $this, 'register_payment_gateway' ) );
        add_action( 'admin_init', array( $this, 'maybe_seed_settings' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_retry_notice' ) );
        add_action( 'update_option_' . FTC_Utils::option_name(), array( $this, 'refresh_settings_cache' ), 10, 2 );
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

        return new FTC_Client( $credentials );
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
        $customer_sync = new FTC_Customer_Sync();
        $cart_sync     = $this->cart_sync();
        $user_id       = $customer_sync->get_or_create_topten_user_from_order( $order );
        $cart_id       = $cart_sync->create_topten_cart_from_order( $order, $user_id );

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

        $client   = $this->get_client_from_order( $order );
        $response = $client->create_payment( $payload, array( 'idempotency_key' => FTC_Utils::uuid_v4() ) );

        $payment_id = isset( $response['payment_id'] ) ? $response['payment_id'] : ( isset( $response['id'] ) ? $response['id'] : '' );

        if ( empty( $payment_id ) ) {
            throw new Exception( __( 'No se recibió ID de pago.', 'ferk-topten-connector' ) );
        }

        if ( empty( $response['payment_url'] ) && empty( $response['redirect_url'] ) ) {
            throw new Exception( __( 'No se recibió URL de pago.', 'ferk-topten-connector' ) );
        }

        $payment_url = ! empty( $response['payment_url'] ) ? $response['payment_url'] : $response['redirect_url'];

        $order->update_meta_data( '_ftc_topten_payment_id', $payment_id );
        $order->update_meta_data( '_ftc_topten_payment_url', $payment_url );
        $order->save();

        FTC_Logger::instance()->info( 'payment_retry', __( 'Pago recreado desde administración.', 'ferk-topten-connector' ), array( 'order_id' => $order->get_id(), 'payment_id' => $payment_id ) );

        return $response;
    }
}
