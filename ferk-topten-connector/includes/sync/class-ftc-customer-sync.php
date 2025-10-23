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
        $wc_user_id = (int) $order->get_user_id();
        $email      = sanitize_email( $order->get_billing_email() );

        $map = $this->db->find_map( 'customer', $wc_user_id > 0 ? $wc_user_id : 0, $email );
        if ( $map && ! empty( $map['external_id'] ) ) {
            return (string) $map['external_id'];
        }

        $first   = wc_clean( $order->get_billing_first_name() );
        $last    = wc_clean( $order->get_billing_last_name() );
        $phone   = wc_clean( $order->get_billing_phone() );
        $country = wc_strtoupper( wc_clean( $order->get_billing_country() ) );

        list($ddi, $local_phone) = FTC_Utils::split_phone( $phone, $country );

        $doc_type = apply_filters( 'ftc_topten_document_type', (string) $order->get_meta( '_billing_document_type' ) );
        $doc_num  = apply_filters( 'ftc_topten_document_number', (string) $order->get_meta( '_billing_document' ) );

        $birth     = (string) $order->get_meta( '_billing_birthdate' );
        $birth_iso = FTC_Utils::normalize_datetime_nullable( $birth );

        $external_id = $wc_user_id > 0 ? (string) $wc_user_id : (string) $email;
        $password    = FTC_Utils::random_password( 24 );

        if ( ! is_email( $email ) ) {
            throw new Exception( 'Correo inválido para NewRegister' );
        }

        $payload = array_filter(
            array(
                'Nombre'             => $first ? $first : null,
                'Apellido'           => $last ? $last : null,
                'Correo'             => $email,
                'Clave'              => $password,
                'Telefono'           => $local_phone ? $local_phone : null,
                'TipoDocumento'      => $doc_type ? $doc_type : null,
                'Documento'          => $doc_num ? $doc_num : null,
                'IndicativoTelefono' => $ddi ? $ddi : null,
                'Enti_Id'            => apply_filters( 'ftc_topten_entity_id', self::FTCTOPTEN_ENTITY_ID ),
                'FechaCumple'        => $birth_iso ? $birth_iso : null,
                'ExternalId'         => $external_id,
            ),
            static function( $value ) {
                return null !== $value && '' !== $value;
            }
        );

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
