<?php
/**
 * Customer synchronization.
 *
 * @package Ferk_Topten_Connector\Includes\Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/db/class-ftc-db.php';

/**
 * Sync WooCommerce customers with TopTen.
 */
class FTC_Customer_Sync {
    const FTCTOPTEN_ENTITY_ID = 51;

    /**
     * Database helper.
     *
     * @var FTC_DB
     */
    protected $db;

    /**
     * Constructor.
     *
     * @param FTC_DB|null $db DB helper.
     */
    public function __construct( $db = null ) {
        if ( $db instanceof FTC_DB ) {
            $this->db = $db;
        } elseif ( class_exists( 'FTC_Plugin' ) ) {
            $this->db = FTC_Plugin::instance()->db();
        } else {
            $this->db = new FTC_DB();
        }
    }

    /**
     * Obtain or create TopTen user based on order.
     *
     * @param WC_Order $order Order.
     *
     * @return string
     * @throws Exception When creation fails.
     */
    public function get_or_create_topten_user_from_order( $order ) {
        $user_id      = $order->get_user_id();
        $email        = $order->get_billing_email();
        $hash         = $email ? md5( strtolower( $email ) ) : '';
        $wc_identifier = $user_id ? (int) $user_id : $this->get_guest_identifier( $email );

        $db  = FTC_Plugin::instance()->db();
        $map = $db ? $db->find_map( 'customer', $wc_identifier, $email ) : null;

        if ( $map && ! empty( $map['external_id'] ) ) {
            $order->update_meta_data( '_ftc_topten_user_id', $map['external_id'] );
            $order->save();

            return $map['external_id'];
        }

        $doc_type = apply_filters( 'ftc_topten_document_type', (string) $order->get_meta( '_billing_document_type' ) );
        $doc_num  = apply_filters( 'ftc_topten_document_number', (string) $order->get_meta( '_billing_document' ) );

        $birth     = (string) $order->get_meta( '_billing_birthdate' );
        $birth_iso = FTC_Utils::normalize_datetime_nullable( $birth );

        $external_id = $wc_user_id > 0 ? (string) $wc_user_id : (string) $email;
        $password    = FTC_Utils::random_password( 24 );

        if ( ! is_email( $email ) ) {
            throw new Exception( 'Correo inválido para NewRegister' );
        }

        if ( $db ) {
            $db->upsert_map(
                'customer',
                $wc_identifier ? $wc_identifier : 0,
                $external_id,
                array(
                    'hash'      => $hash,
                    'data_json' => $data_json,
                )
            );
        }

        $client = FTC_Plugin::instance()->client();
        $id     = (int) $client->create_user_newregister( $payload );

        if ( $id <= 0 ) {
            throw new Exception( 'TopTen NewRegister retornó 0 (error creando usuario)' );
        }

        $hash = FTC_Utils::hash_identity( $email );
        $this->db->upsert_map(
            'customer',
            $wc_user_id > 0 ? $wc_user_id : 0,
            (string) $id,
            $hash,
            array(
                'email'       => $email,
                'first'       => $first,
                'last'        => $last,
                'phone'       => $phone,
                'ddi'         => $ddi,
                'doc'         => $doc_num,
                'doc_type'    => $doc_type,
                'birth'       => $birth_iso,
                'externalId'  => $external_id,
                'created_from'=> 'NewRegister',
            )
        );

        return (string) $id;
    }
}
