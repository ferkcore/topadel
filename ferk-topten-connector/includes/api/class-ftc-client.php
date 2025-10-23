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
    const PATH_USERS    = '/users';
    const PATH_CARTS    = '/carts';
    const PATH_PAYMENTS = '/payments';
    const PATH_HEALTH   = '/health';

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

        $base = $this->get_base_url();
        if ( empty( $base ) ) {
            throw new Exception( __( 'Base URL no configurada.', 'ferk-topten-connector' ) );
        }

        $url = trailingslashit( untrailingslashit( $base ) ) . ltrim( $path, '/' );

        $headers = array(
            'Content-Type' => 'application/json',
        );

        if ( ! empty( $this->config['api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->config['api_key'];
        }

        if ( ! empty( $args['idempotency_key'] ) ) {
            $headers['Idempotency-Key'] = $args['idempotency_key'];
        }

        $body    = isset( $args['body'] ) ? wp_json_encode( $args['body'] ) : null;
        $timeout = ! empty( $this->config['timeout'] ) ? (int) $this->config['timeout'] : 30;
        $retries = ! empty( $this->config['retries'] ) ? (int) $this->config['retries'] : 3;

        $logger = FTC_Logger::instance();

        $backoff_schedule = array( 60, 300, 900 );

        for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
            $response = wp_remote_request(
                $url,
                array(
                    'method'  => $method,
                    'headers' => $headers,
                    'timeout' => $timeout,
                    'body'    => $body,
                )
            );

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
     * Perform health check.
     *
     * @return array
     */
    public function health() {
        return $this->request( 'GET', self::PATH_HEALTH );
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
     * Get base URL using sandbox flag.
     *
     * @return string
     */
    protected function get_base_url() {
        $sandbox = ( 'yes' === FTC_Utils::array_get( $this->config, 'sandbox', 'yes' ) );

        if ( $sandbox ) {
            return FTC_Utils::array_get( $this->config, 'base_url_sandbox', '' );
        }

        return FTC_Utils::array_get( $this->config, 'base_url_production', '' );
    }
}
