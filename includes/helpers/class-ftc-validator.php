<?php
/**
 * Validation helpers.
 *
 * @package Ferk_Topten_Connector\Includes\Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides validation helpers for payloads.
 */
class FTC_Validator {
    /**
     * Validate webhook signature.
     *
     * @param string $payload   Raw payload.
     * @param string $signature Signature header.
     * @param string $secret    Secret key.
     *
     * @return bool
     */
    public static function validate_signature( $payload, $signature, $secret ) {
        if ( empty( $secret ) ) {
            return true;
        }

        if ( empty( $signature ) || empty( $payload ) ) {
            return false;
        }

        $computed = base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );

        return hash_equals( $computed, $signature );
    }

    /**
     * Validate that a status string is allowed.
     *
     * @param string $status Status.
     *
     * @return bool
     */
    public static function is_valid_status( $status ) {
        $allowed = array( 'approved', 'paid', 'pending', 'rejected', 'canceled', 'cancelled' );

        return in_array( strtolower( (string) $status ), $allowed, true );
    }
}
