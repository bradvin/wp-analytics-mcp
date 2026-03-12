<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_GA4 {

    private const SCOPE   = 'https://www.googleapis.com/auth/analytics.readonly';
    private const API_URL = 'https://analyticsdata.googleapis.com/v1beta';

    public static function get_tools(): array {
        return [
            [
                'name'        => 'ga4_run_report',
                'description' => 'Run a Google Analytics 4 report. Returns metrics like sessions, users, pageviews, conversions. Supports dimensions like date, pagePath, deviceCategory, country, sessionSource. Specify your GA4 property ID from the GA4 settings page.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'property_id' => [ 'type' => 'string',  'description' => 'GA4 property ID (numbers only, e.g. 123456789). Found in GA4 Admin > Property Settings.' ],
                        'start_date'  => [ 'type' => 'string',  'description' => 'Start date: YYYY-MM-DD or relative like 7daysAgo, 30daysAgo, yesterday' ],
                        'end_date'    => [ 'type' => 'string',  'description' => 'End date: YYYY-MM-DD or relative like today, yesterday' ],
                        'metrics'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Metrics to return, e.g. sessions, activeUsers, screenPageViews, conversions, bounceRate' ],
                        'dimensions'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Dimensions to group by, e.g. date, pagePath, deviceCategory, country, sessionSource' ],
                        'limit'       => [ 'type' => 'integer', 'description' => 'Max rows to return (default 25, max 250)' ],
                        'offset'      => [ 'type' => 'integer', 'description' => 'Pagination offset (default 0)' ],
                        'order_by'    => [ 'type' => 'string',  'description' => 'Metric name to sort by descending, e.g. sessions' ],
                    ],
                    'required' => [ 'property_id', 'start_date', 'end_date', 'metrics' ],
                ],
            ],
            [
                'name'        => 'ga4_run_realtime_report',
                'description' => 'Run a Google Analytics 4 real-time report showing active users in the last 30 minutes.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'property_id' => [ 'type' => 'string', 'description' => 'GA4 property ID (numbers only)' ],
                        'metrics'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Metrics: activeUsers, screenPageViews, conversions' ],
                        'dimensions'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Dimensions: country, city, pagePath, deviceCategory, unifiedScreenName' ],
                        'limit'       => [ 'type' => 'integer', 'description' => 'Max rows (default 25)' ],
                    ],
                    'required' => [ 'property_id', 'metrics' ],
                ],
            ],
            [
                'name'        => 'ga4_list_metadata',
                'description' => 'List all available dimensions and metrics for a GA4 property. Useful for discovering what data is available before running reports.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'property_id' => [ 'type' => 'string', 'description' => 'GA4 property ID (numbers only)' ],
                    ],
                    'required' => [ 'property_id' ],
                ],
            ],
        ];
    }

    public static function call_tool( string $name, array $args ): array {
        $token = WP_MCP_Google_Auth::get_access_token( self::SCOPE );
        if ( is_wp_error( $token ) ) {
            return self::error( $token->get_error_message() );
        }

        return match( $name ) {
            'ga4_run_report'          => self::run_report( $token, $args ),
            'ga4_run_realtime_report' => self::run_realtime_report( $token, $args ),
            'ga4_list_metadata'       => self::list_metadata( $token, $args ),
            default                   => self::error( "Unknown GA4 tool: $name" ),
        };
    }

    // -------------------------------------------------------------------------

    private static function run_report( string $token, array $args ): array {
        $prop = 'properties/' . ltrim( $args['property_id'], 'properties/' );

        $body = [
            'dateRanges' => [ [ 'startDate' => $args['start_date'], 'endDate' => $args['end_date'] ] ],
            'metrics'    => array_map( fn( $m ) => [ 'name' => $m ], $args['metrics'] ?? [] ),
            'limit'      => min( (int) ( $args['limit'] ?? 25 ), 250 ),
            'offset'     => (int) ( $args['offset'] ?? 0 ),
        ];

        if ( ! empty( $args['dimensions'] ) ) {
            $body['dimensions'] = array_map( fn( $d ) => [ 'name' => $d ], $args['dimensions'] );
        }

        if ( ! empty( $args['order_by'] ) ) {
            $body['orderBys'] = [ [ 'metric' => [ 'metricName' => $args['order_by'] ], 'desc' => true ] ];
        }

        $data = self::api_post( $token, "/{$prop}:runReport", $body );
        if ( is_wp_error( $data ) ) return self::error( $data->get_error_message() );
        return self::ok( $data );
    }

    private static function run_realtime_report( string $token, array $args ): array {
        $prop = 'properties/' . ltrim( $args['property_id'], 'properties/' );

        $body = [
            'metrics' => array_map( fn( $m ) => [ 'name' => $m ], $args['metrics'] ?? [] ),
            'limit'   => min( (int) ( $args['limit'] ?? 25 ), 250 ),
        ];

        if ( ! empty( $args['dimensions'] ) ) {
            $body['dimensions'] = array_map( fn( $d ) => [ 'name' => $d ], $args['dimensions'] );
        }

        $data = self::api_post( $token, "/{$prop}:runRealtimeReport", $body );
        if ( is_wp_error( $data ) ) return self::error( $data->get_error_message() );
        return self::ok( $data );
    }

    private static function list_metadata( string $token, array $args ): array {
        $prop = 'properties/' . ltrim( $args['property_id'], 'properties/' );
        $data = self::api_get( $token, "/{$prop}/metadata" );
        if ( is_wp_error( $data ) ) return self::error( $data->get_error_message() );
        return self::ok( $data );
    }

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
