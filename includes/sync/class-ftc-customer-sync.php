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
        $wc_user_id    = (int) $order->get_user_id();
        $email         = sanitize_email( (string) $order->get_billing_email() );
        $identity_hash = FTC_Utils::hash_identity( $email );
        $wc_identifier = $wc_user_id > 0 ? $wc_user_id : $this->get_guest_identifier( $email );

        $db  = FTC_Plugin::instance()->db();
        $map = $db ? $db->find_map( 'customer', $wc_identifier, $email ) : null;

        if ( $map && ! empty( $map['external_id'] ) ) {
            $order->update_meta_data( '_ftc_topten_user_id', $map['external_id'] );
            $order->save();

            return $map['external_id'];
        }

        $first    = (string) $order->get_billing_first_name();
        $last     = (string) $order->get_billing_last_name();
        $phone    = (string) $order->get_billing_phone();
        $doc_type = apply_filters( 'ftc_topten_document_type', (string) $order->get_meta( '_billing_document_type' ) );
        $doc_num  = apply_filters( 'ftc_topten_document_number', (string) $order->get_meta( '_billing_document' ) );

        $birth     = (string) $order->get_meta( '_billing_birthdate' );
        $birth_iso = FTC_Utils::normalize_datetime_nullable( $birth );

        $ddi_meta = (string) $order->get_meta( '_billing_phone_ddi' );
        $ddi      = apply_filters( 'ftc_topten_phone_ddi', $ddi_meta, $order );
        $ddi      = is_scalar( $ddi ) ? preg_replace( '/\D+/', '', (string) $ddi ) : '';

        $clean_phone = preg_replace( '/\D+/', '', $phone );

        $external_id = $wc_user_id > 0 ? (string) $wc_user_id : (string) $email;
        $password    = FTC_Utils::random_password( 24 );

        if ( ! is_email( $email ) ) {
            throw new Exception( 'Correo inválido para NewRegister' );
        }

        $customer_data = array(
            'email'      => $email,
            'first'      => $first,
            'last'       => $last,
            'phone'      => $clean_phone,
            'ddi'        => $ddi,
            'doc'        => $doc_num,
            'doc_type'   => $doc_type,
            'birth'      => $birth_iso,
            'externalId' => $external_id,
        );

        if ( $db ) {
            $db->upsert_map(
                'customer',
                $wc_identifier ? $wc_identifier : 0,
                $external_id,
                array(
                    'hash' => $identity_hash,
                    'data' => $customer_data,
                )
            );
        }

        $payload = array(
            'Nombre'         => $first,
            'Apellido'       => $last,
            'Correo'         => $email,
            'Clave'          => $password,
            'Enti_Id'        => (int) apply_filters( 'ftc_topten_entity_id', FTC_Utils::FTCTOPTEN_ENTITY_ID ),
            'ExternalId'     => $external_id,
            'Documento'      => $doc_num ? $doc_num : null,
            'DocumentoTipo'  => $doc_type ? $doc_type : null,
            'Telefono'       => $clean_phone ? $clean_phone : null,
            'TelefonoDDI'    => $ddi ? $ddi : null,
            'FechaNacimiento'=> $birth_iso ? $birth_iso : null,
        );

        $payload = apply_filters( 'ftc_topten_newregister_payload', $payload, $order, $customer_data );
        $payload = array_filter(
            $payload,
            static function ( $value ) {
                return null !== $value && '' !== $value;
            }
        );

        $client = FTC_Plugin::instance()->client();
        $id     = (int) $client->create_user_newregister( $payload );

        if ( $id <= 0 ) {
            throw new Exception( 'TopTen NewRegister retornó 0 (error creando usuario)' );
        }

        if ( $this->db ) {
            $data = array_merge(
                $customer_data,
                array(
                    'topten_id'   => (string) $id,
                    'created_from'=> 'NewRegister',
                )
            );

            if ( ! empty( $password ) ) {
                $data['password_hint'] = substr( $password, 0, 3 ) . '***';
            }

            $this->db->upsert_map(
                'customer',
                $wc_user_id > 0 ? $wc_user_id : 0,
                (string) $id,
                array(
                    'hash' => $identity_hash,
                    'data' => $data,
                )
            );
        }

        return (string) $id;
    }

    /**
     * Build a deterministic identifier for guest customers.
     *
     * @param string $email Customer email.
     *
     * @return int
     */
    protected function get_guest_identifier( $email ) {
        if ( ! is_scalar( $email ) ) {
            return 0;
        }

        $email = trim( strtolower( (string) $email ) );
        if ( '' === $email ) {
            return 0;
        }

        return abs( crc32( $email ) );
    }
}
