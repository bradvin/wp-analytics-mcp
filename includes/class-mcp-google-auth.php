<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Google_Auth {

    private static ?string $cached_token = null;
    private static int $token_expiry     = 0;

    /**
     * Get a valid OAuth access token using the stored service account JSON.
     * Tokens are cached in a transient until 5 minutes before expiry.
     */
    public static function get_access_token( string $scope ): string|WP_Error {
        $cache_key = 'wp_mcp_gtoken_' . md5( $scope );
        $cached    = get_transient( $cache_key );

        if ( $cached ) {
            return $cached;
        }

        $credentials_json = get_option( 'wp_mcp_service_account_json', '' );
        if ( empty( $credentials_json ) ) {
            return new WP_Error( 'no_credentials', 'No service account JSON configured.' );
        }

        $creds = json_decode( $credentials_json, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $creds['private_key'] ) ) {
            return new WP_Error( 'invalid_credentials', 'Service account JSON is invalid.' );
        }

        $jwt = self::build_jwt( $creds, $scope );
        if ( is_wp_error( $jwt ) ) return $jwt;

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'token_request_failed', $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $err = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            return new WP_Error( 'token_error', "Google token error: $err" );
        }

        $expires_in = (int) ( $body['expires_in'] ?? 3600 );
        set_transient( $cache_key, $body['access_token'], $expires_in - 300 );

        return $body['access_token'];
    }

    /**
     * Build a signed JWT for the service account.
     */
    private static function build_jwt( array $creds, string $scope ): string|WP_Error {
        $now = time();

        $header  = self::base64url_encode( json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $payload = self::base64url_encode( json_encode( [
            'iss'   => $creds['client_email'],
            'scope' => $scope,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ] ) );

        $signing_input = "$header.$payload";

        $private_key = openssl_pkey_get_private( $creds['private_key'] );
        if ( ! $private_key ) {
            return new WP_Error( 'invalid_key', 'Could not load private key from service account JSON.' );
        }

        $signature = '';
        if ( ! openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'sign_failed', 'Failed to sign JWT.' );
        }

        return "$signing_input." . self::base64url_encode( $signature );
    }

    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
