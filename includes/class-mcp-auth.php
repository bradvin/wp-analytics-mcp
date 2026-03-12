<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Auth {

    /**
     * Validate the API key from the incoming request.
     * Accepts key via Authorization header (Bearer token) or ?api_key= query param.
     */
    public static function validate( WP_REST_Request $request ): bool {
        $stored_key = get_option( 'wp_mcp_api_key', '' );

        if ( empty( $stored_key ) ) {
            return false; // No key configured — deny all
        }

        // Check Authorization: Bearer <token>
        $auth_header = $request->get_header( 'authorization' );
        if ( $auth_header && str_starts_with( $auth_header, 'Bearer ' ) ) {
            $token = trim( substr( $auth_header, 7 ) );
            if ( hash_equals( $stored_key, $token ) ) {
                return true;
            }
        }

        // Check ?api_key= query param
        $query_key = $request->get_param( 'api_key' );
        if ( $query_key && hash_equals( $stored_key, $query_key ) ) {
            return true;
        }

        return false;
    }

    /**
     * Generate a secure random API key.
     */
    public static function generate_key(): string {
        return bin2hex( random_bytes( 32 ) );
    }
}
