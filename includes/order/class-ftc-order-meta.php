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
        add_action( 'admin_post_ftc_mark_paid', array( $this, 'handle_mark_paid' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_missing_products_notice' ) );
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

        $user_id       = $order->get_meta( '_ftc_topten_user_id' );
        $cart_id       = $order->get_meta( '_ftc_topten_cart_id' );
        $payment_id    = $order->get_meta( '_ftc_topten_payment_id' );
        $payment_url   = $order->get_meta( '_ftc_topten_payment_url' );
        $status        = $order->get_meta( '_ftc_topten_payment_status' );
        $payment_token = $order->get_meta( '_ftc_topten_payment_token' );
        $expiration    = $order->get_meta( '_ftc_topten_payment_expiration_utc' );
        $id_adquiria   = $order->get_meta( '_ftc_topten_payment_idadquiria' );
        $last_status   = $order->get_meta( '_ftc_topten_last_status' );
        $last_status_at = $order->get_meta( '_ftc_topten_last_status_at' );

        $expiration_display = '';
        if ( ! empty( $expiration ) && is_numeric( $expiration ) ) {
            $expiration_display = gmdate( 'Y-m-d H:i:s', (int) $expiration ) . ' UTC';
        } elseif ( ! empty( $expiration ) ) {
            $expiration_display = (string) $expiration;
        }

        $last_status_at_display = '';
        if ( ! empty( $last_status_at ) && is_numeric( $last_status_at ) ) {
            $last_status_at_display = wp_date( 'Y-m-d H:i:s', (int) $last_status_at );
        }

        ?>
        <p><strong><?php esc_html_e( 'Usuario TopTen:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $user_id ? $user_id : '—' ); ?></p>
        <p><strong><?php esc_html_e( 'TopTen Cart ID:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $cart_id ? $cart_id : '—' ); ?></p>
        <?php
        if ( $cart_id ) {
            $cart_url = apply_filters( 'ftc_topten_backoffice_cart_url', '', $cart_id, $order );
            if ( $cart_url ) {
                ?>
                <p><a class="button button-secondary" href="<?php echo esc_url( $cart_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir carrito en TopTen', 'ferk-topten-connector' ); ?></a></p>
                <?php
            }
        }
        ?>
        <p><strong><?php esc_html_e( 'Pago TopTen:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $payment_id ? $payment_id : '—' ); ?></p>
        <p><strong><?php esc_html_e( 'Estado pago TopTen:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $status ? $status : '—' ); ?></p>
        <?php if ( $last_status || $last_status_at_display ) : ?>
            <p><strong><?php esc_html_e( 'Último estado recibido por webhook:', 'ferk-topten-connector' ); ?></strong><br />
                <?php echo esc_html( $last_status ? $last_status : '—' ); ?>
                <?php if ( $last_status_at_display ) : ?>
                    <br /><small><?php echo esc_html( sprintf( /* translators: %s: datetime string */ __( 'Actualizado: %s', 'ferk-topten-connector' ), $last_status_at_display ) ); ?></small>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ( $payment_token ) : ?>
            <p><strong><?php esc_html_e( 'TopTen Payment Token:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $payment_token ); ?></p>
        <?php endif; ?>
        <?php if ( $payment_url ) : ?>
            <p><strong><?php esc_html_e( 'TopTen Payment URL:', 'ferk-topten-connector' ); ?></strong> <a href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $payment_url ); ?></a></p>
        <?php endif; ?>
        <?php if ( $expiration_display ) : ?>
            <p><strong><?php esc_html_e( 'Expiration (UTC):', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $expiration_display ); ?></p>
        <?php endif; ?>
        <?php if ( $id_adquiria ) : ?>
            <p><strong><?php esc_html_e( 'IdAdquiria:', 'ferk-topten-connector' ); ?></strong> <?php echo esc_html( $id_adquiria ); ?></p>
        <?php endif; ?>
        <?php if ( $payment_url ) : ?>
            <p><a class="button button-secondary" href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir URL de pago', 'ferk-topten-connector' ); ?></a></p>
        <?php endif; ?>
        <p>
            <button class="button" name="ftc-retry-payment" value="1" form="ftc-retry-payment-form"><?php esc_html_e( 'Reintentar crear pago', 'ferk-topten-connector' ); ?></button>
        </p>
        <?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
            <p>
                <button class="button" name="ftc-mark-paid" value="1" form="ftc-mark-paid-form"><?php esc_html_e( 'Marcar como pagado (manual)', 'ferk-topten-connector' ); ?></button>
            </p>
        <?php endif; ?>
        <form id="ftc-retry-payment-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ftc_retry_payment" />
            <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
            <?php wp_nonce_field( 'ftc_retry_payment', 'ftc_retry_nonce' ); ?>
        </form>
        <?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
            <form id="ftc-mark-paid-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ftc_mark_paid" />
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
                <?php wp_nonce_field( 'ftc_mark_paid', 'ftc_mark_paid_nonce' ); ?>
            </form>
        <?php endif; ?>
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

    /**
     * Handle manual mark as paid action.
     */
    public function handle_mark_paid() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'ferk-topten-connector' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $nonce    = isset( $_POST['ftc_mark_paid_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ftc_mark_paid_nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'ftc_mark_paid' ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'ferk-topten-connector' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
            exit;
        }

        $transaction_id = (string) ( $order->get_meta( '_ftc_topten_payment_token' ) ?: '' );
        if ( '' === $transaction_id ) {
            $transaction_id = (string) ( $order->get_meta( '_ftc_topten_payment_idadquiria' ) ?: '' );
        }

        if ( '' === $transaction_id ) {
            $transaction_id = 'manual-' . time();
        }

        $order->payment_complete( $transaction_id );
        $order->add_order_note( __( 'Pago marcado manualmente desde la administración de TopTen.', 'ferk-topten-connector' ) );
        $order->update_meta_data( '_ftc_topten_payment_status', 'manual-paid' );
        $order->update_meta_data( '_ftc_topten_last_status', 'manual-paid' );
        $order->update_meta_data( '_ftc_topten_last_status_at', time() );
        $order->save();

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        $redirect = add_query_arg( 'ftc_manual_paid', 'success', $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Maybe show notice when products require mapping.
     */
    public function maybe_show_missing_products_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $order_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $order_id ) {
            return;
        }

        if ( 'shop_order' !== get_post_type( $order_id ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $missing = $order->get_meta( '_ftc_topten_missing_products', true );
        if ( empty( $missing ) || ! is_array( $missing ) ) {
            return;
        }

        $items_html = '<ul>';
        foreach ( $missing as $product_id => $name ) {
            $items_html .= '<li>' . esc_html( sprintf( '%s (ID %d)', $name, $product_id ) ) . '</li>';
        }
        $items_html .= '</ul>';

        $first_product = null;
        $first_id      = array_key_first( $missing );
        if ( $first_id ) {
            $first_product = wc_get_product( $first_id );
        }

        $meta_key = apply_filters( 'ftc_topten_product_meta_key', '_ftc_topten_prod_id', $first_product );

        printf(
            '<div class="notice notice-warning"><p>%1$s</p>%2$s<p>%3$s</p></div>',
            esc_html__( 'Algunos productos no tienen asignado un identificador de TopTen (SKU).', 'ferk-topten-connector' ),
            wp_kses_post( $items_html ),
            esc_html( sprintf( 'Asegúrate de definir un SKU en WooCommerce, agrega el meta "%1$s" en el producto o implementa los filtros "ftc_topten_resolve_prod_id_by_sku" / "ftc_topten_map_chosen_terms" para resolverlos dinámicamente.', $meta_key ) )
        );
    }
}
