<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_GSC {

    private const SCOPE   = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const API_URL = 'https://www.googleapis.com/webmasters/v3';

    /**
     * Return the list of MCP tools this class provides.
     */
    public static function get_tools(): array {
        return [
            [
                'name'        => 'gsc_list_sites',
                'description' => 'List all Google Search Console properties the service account has access to.',
                'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass(), 'required' => [] ],
            ],
            [
                'name'        => 'gsc_query_search_analytics',
                'description' => 'Query Google Search Console search analytics data. Returns clicks, impressions, CTR, and average position. Supports filtering by site URL, date range, and grouping by dimensions (query, page, device, country, date).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'site_url'    => [ 'type' => 'string',  'description' => 'The site URL in GSC format, e.g. https://example.com/ or sc-domain:example.com' ],
                        'start_date'  => [ 'type' => 'string',  'description' => 'Start date in YYYY-MM-DD format' ],
                        'end_date'    => [ 'type' => 'string',  'description' => 'End date in YYYY-MM-DD format' ],
                        'dimensions'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Dimensions to group by: query, page, device, country, date' ],
                        'row_limit'   => [ 'type' => 'integer', 'description' => 'Max rows to return (default 25, max 1000)' ],
                        'start_row'   => [ 'type' => 'integer', 'description' => 'Pagination offset (default 0)' ],
                    ],
                    'required' => [ 'site_url', 'start_date', 'end_date' ],
                ],
            ],
            [
                'name'        => 'gsc_inspect_url',
                'description' => 'Inspect a URL in Google Search Console to check its indexing status, crawl status, and any issues.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'site_url'      => [ 'type' => 'string', 'description' => 'The site URL in GSC format' ],
                        'inspection_url'=> [ 'type' => 'string', 'description' => 'The full URL to inspect' ],
                    ],
                    'required' => [ 'site_url', 'inspection_url' ],
                ],
            ],
            [
                'name'        => 'gsc_list_sitemaps',
                'description' => 'List all sitemaps submitted to Google Search Console for a site.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'site_url' => [ 'type' => 'string', 'description' => 'The site URL in GSC format' ],
                    ],
                    'required' => [ 'site_url' ],
                ],
            ],
        ];
    }

    /**
     * Dispatch a tool call and return [ 'content' => [...] ] or error.
     */
    public static function call_tool( string $name, array $args ): array {
        $token = WP_MCP_Google_Auth::get_access_token( self::SCOPE );
        if ( is_wp_error( $token ) ) {
            return self::error( $token->get_error_message() );
        }

        return match( $name ) {
            'gsc_list_sites'             => self::list_sites( $token ),
            'gsc_query_search_analytics' => self::query_search_analytics( $token, $args ),
            'gsc_inspect_url'            => self::inspect_url( $token, $args ),
            'gsc_list_sitemaps'          => self::list_sitemaps( $token, $args ),
            default                      => self::error( "Unknown GSC tool: $name" ),
        };
    }

    // -------------------------------------------------------------------------

    private static function list_sites( string $token ): array {
        $data = self::api_get( $token, '/sites' );
        if ( is_wp_error( $data ) ) return self::error( $data->get_error_message() );
        return self::ok( $data );
    }

    private static function query_search_analytics( string $token, array $args ): array {
        $site_url = $args['site_url'] ?? '';
        $body     = [
            'startDate'  => $args['start_date'],
            'endDate'    => $args['end_date'],
            'dimensions' => $args['dimensions'] ?? [ 'query' ],
            'rowLimit'   => min( (int) ( $args['row_limit'] ?? 25 ), 1000 ),
            'startRow'   => (int) ( $args['start_row'] ?? 0 ),
        ];

        $encoded  = rawurlencode( $site_url );
        $data     = self::api_post( $token, "/sites/{$encoded}/searchAnalytics/query", $body );
        if ( is_wp_error( $data ) ) return self::error( $data->get_error_message() );
        return self::ok( $data );
    }

    private static function inspect_url( string $token, array $args ): array {
        $body = [
            'inspectionUrl' => $args['inspection_url'],
            'siteUrl'       => $args['site_url'],
        ];
        $data = self::api_post( $token, '/urlInspection/index:inspect', $body );
        if ( is_wp_error( $data ) ) return self::error( $data->get_error_message() );
        return self::ok( $data );
    }

    private static function list_sitemaps( string $token, array $args ): array {
        $encoded = rawurlencode( $args['site_url'] );
        $data    = self::api_get( $token, "/sites/{$encoded}/sitemaps" );
        if ( is_wp_error( $data ) ) return self::error( $data->get_error_message() );
        return self::ok( $data );
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private static function api_get( string $token, string $path ): array|WP_Error {
        $response = wp_remote_get( self::API_URL . $path, [
            'headers' => [ 'Authorization' => "Bearer $token" ],
            'timeout' => 15,
        ] );
        return self::parse_response( $response );
    }

    private static function api_post( string $token, string $path, array $body ): array|WP_Error {
        $response = wp_remote_post( self::API_URL . $path, [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode( $body ),
            'timeout' => 15,
        ] );
        return self::parse_response( $response );
    }

    private static function parse_response( array|WP_Error $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            return new WP_Error( 'api_error', $msg );
        }
        return $body ?? [];
    }

    private static function ok( array $data ): array {
        return [ 'content' => [ [ 'type' => 'text', 'text' => json_encode( $data, JSON_PRETTY_PRINT ) ] ] ];
    }

    private static function error( string $message ): array {
        return [ 'isError' => true, 'content' => [ [ 'type' => 'text', 'text' => $message ] ] ];
    }
}
