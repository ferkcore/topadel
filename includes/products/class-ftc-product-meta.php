<?php
/**
 * Product meta helpers.
 *
 * @package Ferk_Topten_Connector\Includes\Products
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds product meta boxes for TopTen integration.
 */
class FTC_Product_Meta {
    /**
     * Register hooks.
     */
    public function hooks() {
        add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
        add_action( 'save_post_product', array( $this, 'save_meta_box' ), 10, 3 );
    }

    /**
     * Add product meta box.
     */
    public function add_meta_box() {
        add_meta_box(
            'ftc-product-topten',
            __( 'TopTen Connector', 'ferk-topten-connector' ),
            array( $this, 'render_meta_box' ),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render product meta box.
     *
     * @param WP_Post $post Current post.
     */
    public function render_meta_box( $post ) {
        $product = wc_get_product( $post->ID );

        if ( ! $product ) {
            esc_html_e( 'Producto no disponible.', 'ferk-topten-connector' );
            return;
        }

        $value         = get_post_meta( $post->ID, 'id_topten', true );
        $value_display = is_numeric( $value ) && (int) $value > 0 ? (int) $value : '';
        $can_edit      = current_user_can( 'manage_woocommerce' );

        wp_nonce_field( 'ftc_product_meta', 'ftc_product_meta_nonce' );
        ?>
        <p>
            <label for="ftc-id-topten"><strong><?php esc_html_e( 'ID TopTen (Prod_Id)', 'ferk-topten-connector' ); ?></strong></label>
            <?php if ( $can_edit ) : ?>
                <input type="number" name="ftc_id_topten" id="ftc-id-topten" value="<?php echo esc_attr( $value_display ); ?>" class="small-text" min="0" step="1" />
            <?php else : ?>
                <input type="text" id="ftc-id-topten" value="<?php echo esc_attr( $value_display ); ?>" class="regular-text" readonly />
            <?php endif; ?>
        </p>
        <p class="description"><?php esc_html_e( 'Este ID corresponde al TopTen Prod_Id; se usa para carrito y pago.', 'ferk-topten-connector' ); ?></p>
        <?php if ( $product->is_type( 'variable' ) ) : ?>
            <p class="description"><?php esc_html_e( 'Las variaciones pueden definir su propio ID TopTen a través de la herramienta de mapeo.', 'ferk-topten-connector' ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Persist product meta value.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param bool    $update  Whether this is an update.
     */
    public function save_meta_box( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['ftc_product_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ftc_product_meta_nonce'] ) ), 'ftc_product_meta' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $raw_value = isset( $_POST['ftc_id_topten'] ) ? wp_unslash( $_POST['ftc_id_topten'] ) : '';
        $clean     = is_numeric( $raw_value ) ? absint( $raw_value ) : 0;

        if ( $clean > 0 ) {
            update_post_meta( $post_id, 'id_topten', $clean );
        } else {
            delete_post_meta( $post_id, 'id_topten' );
        }
    }
}
