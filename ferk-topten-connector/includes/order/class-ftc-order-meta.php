<?php
/**
 * Order metadata helpers.
 *
 * @package Ferk_Topten_Connector\Includes\Order
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WooCommerce order meta integration.
 */
class FTC_Order_Meta {
    /**
     * Register hooks.
     */
    public function hooks() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_columns' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_columns' ), 10, 2 );
        add_action( 'admin_post_ftc_retry_payment', array( $this, 'handle_retry_payment' ) );
    }

    /**
     * Add meta box.
     */
    public function add_meta_box() {
        add_meta_box(
            'ftc-topten-meta',
            __( 'TopTen Connector', 'ferk-topten-connector' ),
            array( $this, 'render_meta_box' ),
            'shop_order',
            'side'
        );
    }

    /**
     * Render meta box.
     *
     * @param WP_Post $post Post.
     */
    public function render_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) {
            esc_html_e( 'Pedido no disponible.', 'ferk-topten-connector' );
            return;
        }

        $user_id     = $order->get_meta( '_ftc_topten_user_id' );
        $cart_id     = $order->get_meta( '_ftc_topten_cart_id' );
        $payment_id  = $order->get_meta( '_ftc_topten_payment_id' );
        $payment_url = $order->get_meta( '_ftc_topten_payment_url' );
        $status      = $order->get_meta( '_ftc_topten_payment_status' );

        ?>
        <p><strong><?php esc_html_e( 'Usuario TopTen:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $user_id ? $user_id : '—' ); ?></p>
        <p><strong><?php esc_html_e( 'Carrito TopTen:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $cart_id ? $cart_id : '—' ); ?></p>
        <p><strong><?php esc_html_e( 'Pago TopTen:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $payment_id ? $payment_id : '—' ); ?></p>
        <p><strong><?php esc_html_e( 'Estado pago TopTen:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $status ? $status : '—' ); ?></p>
        <?php if ( $payment_url ) : ?>
            <p><a class="button button-secondary" href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir pago en TopTen', 'ferk-topten-connector' ); ?></a></p>
        <?php endif; ?>
        <p>
            <button class="button" name="ftc-retry-payment" value="1" form="ftc-retry-payment-form"><?php esc_html_e( 'Reintentar crear pago', 'ferk-topten-connector' ); ?></button>
        </p>
        <form id="ftc-retry-payment-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ftc_retry_payment" />
            <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
            <?php wp_nonce_field( 'ftc_retry_payment', 'ftc_retry_nonce' ); ?>
        </form>
        <?php
    }

    /**
     * Add order columns.
     *
     * @param array $columns Columns.
     *
     * @return array
     */
    public function add_order_columns( $columns ) {
        $columns['ftc_topten'] = __( 'TopTen', 'ferk-topten-connector' );

        return $columns;
    }

    /**
     * Render custom column.
     *
     * @param string   $column  Column name.
     * @param int      $post_id Post ID.
     */
    public function render_order_columns( $column, $post_id ) {
        if ( 'ftc_topten' !== $column ) {
            return;
        }

        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            echo '&mdash;';
            return;
        }

        $payment_id = $order->get_meta( '_ftc_topten_payment_id' );
        $status     = $order->get_meta( '_ftc_topten_payment_status' );

        if ( ! $payment_id ) {
            echo '&mdash;';
            return;
        }

        echo esc_html( $payment_id );
        if ( $status ) {
            echo '<br /><small>' . esc_html( $status ) . '</small>';
        }
    }

    /**
     * Handle retry payment action.
     */
    public function handle_retry_payment() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'ferk-topten-connector' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $nonce    = isset( $_POST['ftc_retry_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ftc_retry_nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'ftc_retry_payment' ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'ferk-topten-connector' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
            exit;
        }

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        try {
            FTC_Plugin::instance()->recreate_payment_for_order( $order );
            $redirect = add_query_arg( 'ftc_retry', 'success', $redirect );
        } catch ( Exception $e ) {
            FTC_Logger::instance()->error( 'retry_payment', $e->getMessage(), array( 'order_id' => $order_id ) );
            $redirect = add_query_arg( array(
                'ftc_retry' => 'error',
                'ftc_message' => rawurlencode( $e->getMessage() ),
            ), $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
