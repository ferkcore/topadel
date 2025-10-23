<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FTC_JsonPedido {
    /**
     * Construye el objeto PHP que luego será json_encode() y pasado como string en "JsonPedido".
     * Ver doc: infoPago, facturacionPedido, entregaUsuario, ipUsuario, infoExtra, mone_Id, codigoCupon, usua_Cod, origen, productosPedido[], cartItems{}
     */
    public static function build_from_order( WC_Order $order, array $opts ) : array {
        $billing = array(
            'nombres'         => \wc_clean( $order->get_billing_first_name() ),
            'apellidos'       => \wc_clean( $order->get_billing_last_name() ),
            'tipoDocumento'   => (string) ( $order->get_meta( '_billing_document_type' ) ?: 'Cédula de identidad' ),
            'numeroDocumento' => (string) $order->get_meta( '_billing_document' ),
            'telefono'        => \wc_clean( $order->get_billing_phone() ),
            'indicativo'      => isset( $opts['indicativo'] ) ? $opts['indicativo'] : '+598',
            'rut'             => false,
            'idPais'          => (int) ( isset( $opts['id_pais'] ) ? $opts['id_pais'] : 186 ),
            'razonSocial'     => '',
            'numRut'          => '',
        );

        $productos = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $stored_prod_id = (int) $item->get_meta( '_ftc_topten_prod_id', true );
            $prod_id        = $stored_prod_id > 0 ? $stored_prod_id : \FTC_Utils::resolve_topten_product_id( $product );
            if ( ! $prod_id ) {
                continue;
            }

            $chosen_ids_csv = (string) $item->get_meta( '_ftc_topten_chosen_terms', true );
            $chosen_text    = (string) $item->get_meta( '_ftc_topten_chosen_terms_text', true );

            if ( '' === trim( $chosen_ids_csv ) && '' === trim( $chosen_text ) ) {
                list( $chosen_ids_csv, $chosen_text ) = \FTC_Utils::resolve_chosen_terms_for_item( $product, $item );
            }

            $productos[] = array_filter(
                array(
                    'idProducto'            => (int) $prod_id,
                    'esRegalo'              => false,
                    'cantidadEsRegalo'      => 0,
                    'terminosSeleccionados' => $chosen_ids_csv ? $chosen_ids_csv : '',
                    'cantidad'              => (int) $item->get_quantity(),
                ),
                static function ( $value ) {
                    return null !== $value;
                }
            );
        }

        $full_name = trim( ( isset( $billing['nombres'] ) ? $billing['nombres'] : '' ) . ' ' . ( isset( $billing['apellidos'] ) ? $billing['apellidos'] : '' ) );

        $info_pago = array(
            'mone_Id'            => (int) ( isset( $opts['mone_id'] ) ? $opts['mone_id'] : 2 ),
            'installments'       => 0,
            'captureDataIframe'  => false,
            'paymentMethodId'    => '',
            'tokenPayment'       => '',
            'nombreCompletoPago' => '',
            'documento'          => '',
            'tipoDocumento'      => '',
            'email'              => '',
            'coge_Id_Pago'       => (int) ( isset( $opts['coge_id_pago'] ) ? $opts['coge_id_pago'] : 27 ),
            'mepa_Id'            => (int) ( isset( $opts['mepa_id'] ) ? $opts['mepa_id'] : 1 ),
            'valid'              => true,
        );

        $shipping_address_parts = array_filter(
            array_map(
                'wc_clean',
                array(
                    $order->get_shipping_address_1(),
                    $order->get_shipping_address_2(),
                    $order->get_shipping_city(),
                    $order->get_shipping_state(),
                    $order->get_shipping_postcode(),
                    $order->get_shipping_country(),
                )
            )
        );

        if ( empty( $shipping_address_parts ) ) {
            $shipping_address_parts = array_filter(
                array_map(
                    'wc_clean',
                    array(
                        $order->get_billing_address_1(),
                        $order->get_billing_address_2(),
                        $order->get_billing_city(),
                        $order->get_billing_state(),
                        $order->get_billing_postcode(),
                        $order->get_billing_country(),
                    )
                )
            );
        }

        $info_extra = implode( ', ', $shipping_address_parts );

        $entrega_usuario = array(
            'sucu_Id'           => (int) ( isset( $opts['sucursal_id'] ) ? $opts['sucursal_id'] : 78 ),
            'personaRetiro'     => strtoupper( $full_name ),
            'direccionId'       => null,
            'coes_Id_Logistica' => null,
            'ventanaHoraria'    => '',
            'coma_Id'           => null,
            'DiasEnvio'         => array(),
        );

        $json_pedido = array(
            'request'   => array(
                'infoPago'          => $info_pago,
                'facturacionPedido' => $billing,
                'entregaUsuario'    => $entrega_usuario,
                'ipUsuario'         => \WC_Geolocation::get_ip_address(),
                'infoExtra'         => $info_extra,
                'mone_Id'           => (int) ( isset( $opts['mone_id'] ) ? $opts['mone_id'] : 2 ),
                'codigoCupon'       => '',
                'usua_Cod'          => (int) ( isset( $opts['usua_cod'] ) ? $opts['usua_cod'] : 0 ),
                'origen'            => (string) ( isset( $opts['origen'] ) && '' !== trim( $opts['origen'] ) ? $opts['origen'] : 'Top padel' ),
                'productosPedido'   => $productos,
            ),
            'cartItems' => new \stdClass(),
        );

        return $json_pedido;
    }
}
