<?php
/**
 * TopTen API client.
 *
 * @package Ferk_Topten_Connector\Includes\API
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';

/**
 * HTTP client for TopTen API.
 */
class FTC_Client {
    const PATH_USERS                 = '/users';
    const PATH_CARTS                 = '/carts';
    const PATH_PAYMENTS              = '/payments';
    const PATH_HEALTH                = '/health';
    const PATH_ADD_CART_EXTERNAL     = '/api/Cart/AddCartProductExternal';
    const PATH_PAYMENT_PLACETOPAY    = '/api/CommonWeb/PaymentPlacetopay';
    const PATH_GETPRODUCTS_DETAIL    = '/api/Pro_Productos/GetProductosDetail';
    const PATH_NEWREGISTER           = '/api/UserWeb/NewRegister';

    /**
     * Configuration array.
     *
     * @var array
     */
    protected $config = array();

    /**
     * Constructor.
     *
     * @param array $config Configuration.
     */
    public function __construct( $config = array() ) {
        $this->config = wp_parse_args( $config, FTC_Settings::get_defaults()['credentials'] );
    }

    /**
     * Perform request.
     *
     * @param string $method HTTP method.
     * @param string $path   API path.
     * @param array  $args   Arguments.
     *
     * @return array
     * @throws Exception When request fails.
     */
    public function request( $method, $path, $args = array() ) {
        $method = strtoupper( $method );
        $path   = apply_filters( 'ftc_api_path', $path, $method, $args, $this );

        $base = $this->base_url( $args );
        if ( empty( $base ) ) {
            throw new Exception( __( 'Base URL no configurada.', 'ferk-topten-connector' ) );
        }

        $url = rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );

        if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
            $url = add_query_arg( $args['query'], $url );
        }

        $extra_headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
        if ( ! empty( $args['idempotency_key'] ) ) {
            $extra_headers['Idempotency-Key'] = $args['idempotency_key'];
        }

        $headers = $this->build_headers( $extra_headers, $args );

        $body    = isset( $args['body'] ) ? wp_json_encode( $args['body'], JSON_UNESCAPED_UNICODE ) : null;
        $timeout = $this->timeout( $args );
        $retries = $this->retries( $args );

        $logger = FTC_Logger::instance();
        $backoff_schedule = array( 60, 300, 900 );

        $request_args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => $timeout,
        );

        if ( null !== $body ) {
            $request_args['body'] = $body;
        }

        for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
            $response = wp_remote_request( $url, $request_args );

            if ( is_wp_error( $response ) ) {
                if ( $attempt >= $retries ) {
                    throw new Exception( $response->get_error_message() );
                }

                $logger->warn( 'api_request', __( 'Error de transporte, reintentando...', 'ferk-topten-connector' ), array( 'attempt' => $attempt + 1 ) );
                sleep( min( $backoff_schedule[ min( $attempt, count( $backoff_schedule ) - 1 ) ] / 60, 15 ) );
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $attempt < $retries ) {
                $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                $delay       = $retry_after ? max( 1, $retry_after ) : $backoff_schedule[ min( $attempt, count( $backoff_schedule ) - 1 ) ];
                $logger->warn( 'api_request', __( 'Respuesta temporal, reintentando.', 'ferk-topten-connector' ), array( 'code' => $code, 'delay' => $delay ) );
                sleep( $delay );
                continue;
            }

            $body_response = wp_remote_retrieve_body( $response );
            $data          = json_decode( $body_response, true );

            if ( $code >= 200 && $code < 300 ) {
                return is_array( $data ) ? $data : array();
            }

            $message = ! empty( $data['message'] ) ? $data['message'] : sprintf( 'HTTP %1$s', $code );

            throw new Exception( $message );
        }

        throw new Exception( __( 'No fue posible contactar al servicio TopTen.', 'ferk-topten-connector' ) );
    }

    /**
     * Fetch TopTen products detail list.
     *
     * @param array $payload Request payload.
     * @param array $args    Extra args for transport.
     *
     * @return array
     * @throws Exception When the request fails or the response is invalid.
     */
    public function get_products_detail( array $payload, array $args = array() ) : array {
        $body_payload = array();

        foreach ( $payload as $key => $value ) {
            if ( null === $value ) {
                continue;
            }

            if ( is_bool( $value ) ) {
                $body_payload[ $key ] = $value;
                continue;
            }

            if ( is_scalar( $value ) ) {
                if ( is_string( $value ) ) {
                    $trimmed = trim( $value );
                    if ( '' === $trimmed ) {
                        continue;
                    }
                    $body_payload[ $key ] = $trimmed;
                    continue;
                }

                $body_payload[ $key ] = $value;
                continue;
            }

            if ( is_array( $value ) || is_object( $value ) ) {
                $body_payload[ $key ] = $value;
            }
        }

        $body_payload = apply_filters( 'ftc_client_products_detail_payload', $body_payload, $payload, $args, $this );

        $body = wp_json_encode( $body_payload, JSON_UNESCAPED_UNICODE );
        if ( false === $body ) {
            throw new Exception( 'GetProductosDetail payload encoding failed.' );
        }

        $endpoint = rtrim( $this->base_url( $args ), '/' ) . self::PATH_GETPRODUCTS_DETAIL;

        $request_args = array(
            'headers' => $this->build_headers( array(), $args ),
            'timeout' => $this->timeout( $args ),
            'body'    => $body,
        );

        $request_args = apply_filters( 'ftc_client_products_detail_request_args', $request_args, $endpoint, $payload, $args, $this );

        if ( isset( $request_args['headers'] ) && is_array( $request_args['headers'] ) ) {
            foreach ( array_keys( $request_args['headers'] ) as $header_key ) {
                if ( 0 === strcasecmp( 'Authorization', $header_key ) || 0 === strcasecmp( 'Api-Key', $header_key ) || 0 === strcasecmp( 'X-Api-Key', $header_key ) ) {
                    unset( $request_args['headers'][ $header_key ] );
                }
            }
        }

        $response = wp_remote_post( $endpoint, $request_args );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'GetProductosDetail transport error: ' . $response->get_error_message() );
        }

        $raw  = wp_remote_retrieve_body( $response );
        $code = (int) wp_remote_retrieve_response_code( $response );
        $json = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $message = $this->extract_products_detail_error_message( $json, $raw, $code );

            throw new Exception( $message );
        }

        if ( ! is_array( $json ) ) {
            throw new Exception( 'GetProductosDetail unexpected response: ' . $raw );
        }

        return $json;
    }

    /**
     * Extract human readable error from GetProductosDetail response.
     *
     * @param array|null $decoded Decoded body.
     * @param string     $raw     Raw body.
     * @param int        $code    HTTP status code.
     *
     * @return string
     */
    protected function extract_products_detail_error_message( $decoded, string $raw, int $code ) : string {
        if ( is_array( $decoded ) ) {
            foreach ( array( 'Mensaje', 'mensaje', 'Message', 'message', 'error' ) as $key ) {
                if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) ) {
                    $message = trim( (string) $decoded[ $key ] );
                    if ( '' !== $message ) {
                        return $message;
                    }
                }
            }

            foreach ( array( 'Codigo', 'codigo', 'StatusCode', 'status_code' ) as $key ) {
                if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) ) {
                    $code_label = trim( (string) $decoded[ $key ] );
                    if ( '' !== $code_label ) {
                        return sprintf( '%s (%d)', $code_label, $code );
                    }
                }
            }
        }

        if ( '' !== trim( $raw ) ) {
            return sprintf( 'HTTP %1$d: %2$s', $code, trim( $raw ) );
        }

        return sprintf( 'HTTP %d', $code );
    }

    /**
     * Perform health check.
     *
     * @return array
     */
    public function health() {
        return $this->request( 'GET', self::PATH_HEALTH );
    }

    /**
     * Retrieve products (stub implementation).
     *
     * @param array $query Query parameters.
     * @param array $args  Request arguments.
     *
     * @return array
     */
    public function get_products( array $query = array(), array $args = array() ) {
        $request_args = wp_parse_args(
            array(
                'query' => $query,
            ),
            $args
        );

        return $this->request( 'GET', '/api/Products', $request_args );
    }

    /**
     * Create or retrieve TopTen user via NewRegister.
     *
     * @param array $payload Payload.
     * @param array $args    Arguments.
     *
     * @return int
     * @throws Exception On unexpected response or transport error.
     */
    public function create_user_newregister( array $payload, array $args = array() ) : int {
        $path = self::PATH_NEWREGISTER;
        $base = $this->base_url( $args );

        if ( empty( $base ) ) {
            throw new Exception( __( 'Base URL no configurada.', 'ferk-topten-connector' ) );
        }

        $headers = $this->build_headers(
            array(
                'Content-Type' => 'application/json',
            ),
            $args
        );

        $body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        $timeout  = $this->timeout( $args );
        $max_tries = 2;
        $attempt  = 0;

        do {
            $response = wp_remote_post(
                rtrim( $base, '/' ) . $path,
                array(
                    'headers' => $headers,
                    'timeout' => $timeout,
                    'body'    => $body,
                )
            );

            if ( is_wp_error( $response ) ) {
                if ( $attempt >= $max_tries - 1 ) {
                    throw new Exception( 'TopTen create_user transport error: ' . $response->get_error_message() );
                }

                $attempt++;
                sleep( 1 );
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $raw  = wp_remote_retrieve_body( $response );

            if ( $code >= 500 && $attempt < $max_tries - 1 ) {
                $attempt++;
                sleep( 1 );
                continue;
            }

            if ( $code < 200 || $code >= 300 ) {
                throw new Exception( sprintf( 'TopTen create_user unexpected HTTP %1$s: %2$s', $code, $raw ) );
            }

            $user_id = null;
            $trimmed = is_string( $raw ) ? trim( $raw ) : '';
            if ( '' !== $trimmed && is_numeric( $trimmed ) ) {
                $user_id = (int) $trimmed;
            } else {
                $decoded = json_decode( $raw, true );
                if ( is_int( $decoded ) ) {
                    $user_id = $decoded;
                } elseif ( is_numeric( $decoded ) ) {
                    $user_id = (int) $decoded;
                } elseif ( is_array( $decoded ) && isset( $decoded['value'] ) && is_numeric( $decoded['value'] ) ) {
                    $user_id = (int) $decoded['value'];
                }
            }

            if ( ! is_int( $user_id ) ) {
                throw new Exception( 'TopTen create_user unexpected response: ' . $raw );
            }

            return $user_id;
        } while ( $attempt < $max_tries );

        throw new Exception( __( 'No fue posible crear el usuario.', 'ferk-topten-connector' ) );
    }

    /**
     * Create user.
     *
     * @param array $payload Payload.
     *
     * @return array
     */
    public function create_user( $payload ) {
        $path = apply_filters( 'ftc_api_users_path', self::PATH_USERS, $payload, $this );
        return $this->request( 'POST', $path, array( 'body' => $payload ) );
    }

    /**
     * Create cart.
     *
     * @param array $payload Payload.
     *
     * @return array
     */
    public function create_cart( $payload ) {
        $path = apply_filters( 'ftc_api_carts_path', self::PATH_CARTS, $payload, $this );
        return $this->request( 'POST', $path, array( 'body' => $payload ) );
    }

    /**
     * Create cart using AddCartProductExternal endpoint.
     *
     * @param array $payload Payload to send.
     * @param array $args    Optional arguments.
     *
     * @return int
     * @throws \Exception When response is invalid or transport fails.
     */
    public function create_cart_external( array $payload, array $args = array() ) {
        $path    = self::PATH_ADD_CART_EXTERNAL;
        $headers = $this->build_headers(
            array(
                'Content-Type' => 'application/json',
            ),
            $args
        );

        $body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        $endpoint = rtrim( $this->base_url( $args ), '/' ) . '/' . ltrim( $path, '/' );

        $response = wp_remote_post(
            $endpoint,
            array(
                'headers' => $headers,
                'timeout' => $this->timeout( $args ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( 'TopTen create_cart transport error: ' . $response->get_error_message() );
        }

        $raw = wp_remote_retrieve_body( $response );

        $cart_id = null;
        $trimmed = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' !== $trimmed && is_numeric( $trimmed ) ) {
            $cart_id = (int) $trimmed;
        } else {
            $decoded = json_decode( $raw, true );
            if ( is_int( $decoded ) ) {
                $cart_id = $decoded;
            } elseif ( is_numeric( $decoded ) ) {
                $cart_id = (int) $decoded;
            } elseif ( is_array( $decoded ) && isset( $decoded['value'] ) && is_numeric( $decoded['value'] ) ) {
                $cart_id = (int) $decoded['value'];
            }
        }

        if ( ! is_int( $cart_id ) ) {
            throw new \Exception( 'TopTen create_cart unexpected response: ' . $raw );
        }

        return $cart_id;
    }

    /**
     * Create payment session using PaymentPlacetopay endpoint.
     *
     * @param array $payload Payload to send.
     * @param array $args    Optional arguments.
     *
     * @return array
     * @throws \Exception When response is invalid or transport fails.
     */
    public function create_payment_placetopay( array $payload, array $args = array() ) : array {
        $path    = self::PATH_PAYMENT_PLACETOPAY;
        $headers = $this->build_headers(
            array(
                'Content-Type' => 'application/json',
            ),
            $args
        );

        $body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        $response = wp_remote_post(
            $this->base_url( $args ) . $path,
            array(
                'headers' => $headers,
                'timeout' => $this->timeout( $args ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( 'TopTen create_payment transport error: ' . $response->get_error_message() );
        }

        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( ! is_array( $decoded ) ) {
            throw new \Exception( 'TopTen create_payment unexpected response: ' . $raw );
        }

        $decoded_normalized = array();
        foreach ( $decoded as $key => $value ) {
            $decoded_normalized[ strtolower( (string) $key ) ] = $value;
        }

        $success_info = array();
        if ( isset( $decoded['SuccessInfo'] ) && is_array( $decoded['SuccessInfo'] ) ) {
            $success_info = $decoded['SuccessInfo'];
        } elseif ( isset( $decoded_normalized['successinfo'] ) && is_array( $decoded_normalized['successinfo'] ) ) {
            $success_info = $decoded_normalized['successinfo'];
        }

        $success_info_normalized = array();
        foreach ( $success_info as $key => $value ) {
            $success_info_normalized[ strtolower( (string) $key ) ] = $value;
        }

        $success = isset( $success_info_normalized['success'] ) ? (bool) $success_info_normalized['success'] : false;
        if ( ! $success ) {
            $msg = isset( $success_info_normalized['message'] ) ? (string) $success_info_normalized['message'] : 'Unknown error';
            throw new \Exception( 'TopTen create_payment failed: ' . $msg );
        }

        $token        = isset( $decoded['Token'] ) ? $decoded['Token'] : ( $decoded_normalized['token'] ?? '' );
        $url_external = isset( $decoded['UrlExternal'] ) ? $decoded['UrlExternal'] : ( $decoded_normalized['urlexternal'] ?? '' );
        $expiration   = isset( $decoded['ExpirationUTC'] ) ? $decoded['ExpirationUTC'] : ( $decoded_normalized['expirationutc'] ?? 0 );
        $id_adquiria  = isset( $decoded['IdAdquiria'] ) ? $decoded['IdAdquiria'] : ( $decoded_normalized['idadquiria'] ?? 0 );

        return array(
            'token'          => (string) $token,
            'url_external'   => (string) $url_external,
            'expiration_utc' => (int) $expiration,
            'id_adquiria'    => (int) $id_adquiria,
            'raw'            => $decoded,
        );
    }

    /**
     * Create payment.
     *
     * @param array $payload Payload.
     * @param array $args    Extra args.
     *
     * @return array
     */
    public function create_payment( $payload, $args = array() ) {
        $path = apply_filters( 'ftc_api_payments_path', self::PATH_PAYMENTS, $payload, $this );
        $args = wp_parse_args( $args, array() );

        return $this->request( 'POST', $path, array(
            'body'            => $payload,
            'idempotency_key' => isset( $args['idempotency_key'] ) ? $args['idempotency_key'] : null,
        ) );
    }

    /**
     * Retrieve payment data.
     *
     * @param string $payment_id Payment ID.
     *
     * @return array
     */
    public function get_payment( $payment_id ) {
        $path = trailingslashit( self::PATH_PAYMENTS ) . rawurlencode( $payment_id );
        $path = apply_filters( 'ftc_api_get_payment_path', $path, $payment_id, $this );

        return $this->request( 'GET', $path );
    }


    /**
     * Build HTTP headers for a request.
     *
     * @param array $extra Extra headers.
     * @param array $args  Optional args.
     *
     * @return array
     */
    protected function build_headers( array $extra = array(), array $args = array() ) {
        $headers = array_merge(
            array(
                'Accept' => 'application/json',
            ),
            is_array( $extra ) ? $extra : array()
        );

        if ( ! isset( $headers['Content-Type'] ) ) {
            $headers['Content-Type'] = 'application/json';
        }

        return apply_filters( 'ftc_client_headers', $headers, $args, $this );
    }

    /**
     * Resolve base URL for a request.
     *
     * @param array $args Optional args.
     *
     * @return string
     * @throws \Exception When base URL is missing.
     */
    protected function base_url( $args = array() ) {
        if ( isset( $args['base_url'] ) && $args['base_url'] ) {
            return untrailingslashit( $args['base_url'] );
        }

        $credentials = $this->credentials( $args );
        $sandbox     = $this->is_sandbox( $credentials, $args );

        $base = $sandbox ? FTC_Utils::array_get( $credentials, 'base_url_sandbox', '' ) : FTC_Utils::array_get( $credentials, 'base_url_production', '' );

        if ( empty( $base ) ) {
            $fallback_key = $sandbox ? 'base_url_production' : 'base_url_sandbox';
            $fallback     = FTC_Utils::array_get( $credentials, $fallback_key, '' );

            if ( ! empty( $fallback ) ) {
                $missing_label  = $sandbox ? __( 'sandbox', 'ferk-topten-connector' ) : __( 'production', 'ferk-topten-connector' );
                $fallback_label = $sandbox ? __( 'production', 'ferk-topten-connector' ) : __( 'sandbox', 'ferk-topten-connector' );

                FTC_Logger::instance()->warn(
                    'config',
                    sprintf(
                        /* translators: 1: missing environment name, 2: fallback environment name. */
                        __( 'Missing %1$s base URL, falling back to %2$s.', 'ferk-topten-connector' ),
                        $missing_label,
                        $fallback_label
                    )
                );

                $base = $fallback;
            }
        }

        $base = apply_filters( 'ftc_client_base_url', $base, $sandbox, $args, $this );

        if ( empty( $base ) ) {
            throw new \Exception( __( 'Base URL no configurada. Configura las URLs de sandbox o producción en la sección de credenciales.', 'ferk-topten-connector' ) );
        }

        return untrailingslashit( $base );
    }

    /**
     * Resolve credentials merged with overrides.
     *
     * @param array $args Optional args.
     *
     * @return array
     */
    protected function credentials( $args = array() ) {
        $credentials = $this->config;

        if ( isset( $args['credentials'] ) && is_array( $args['credentials'] ) ) {
            $credentials = wp_parse_args( $args['credentials'], $credentials );
        }

        return $credentials;
    }

    /**
     * Determine if sandbox should be used.
     *
     * @param array $credentials Credentials.
     * @param array $args        Optional args.
     *
     * @return bool
     */
    protected function is_sandbox( $credentials, $args = array() ) {
        if ( isset( $args['sandbox'] ) ) {
            return (bool) $args['sandbox'];
        }

        $sandbox = FTC_Utils::array_get( $credentials, 'sandbox', 'yes' );

        return 'yes' === $sandbox || true === $sandbox;
    }

    /**
     * Resolve timeout value.
     *
     * @param array $args Optional args.
     *
     * @return int
     */
    protected function timeout( $args = array() ) {
        if ( isset( $args['timeout'] ) && is_numeric( $args['timeout'] ) ) {
            return max( 5, (int) $args['timeout'] );
        }

        return ! empty( $this->config['timeout'] ) ? (int) $this->config['timeout'] : 30;
    }

    /**
     * Resolve retries value.
     *
     * @param array $args Optional args.
     *
     * @return int
     */
    protected function retries( $args = array() ) {
        if ( isset( $args['retries'] ) ) {
            return min( 5, max( 0, (int) $args['retries'] ) );
        }

        if ( isset( $this->config['retries'] ) ) {
            return min( 5, max( 0, (int) $this->config['retries'] ) );
        }

        return 3;
    }
}
