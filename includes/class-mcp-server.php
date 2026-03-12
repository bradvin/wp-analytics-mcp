<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Implements the MCP Streamable HTTP transport.
 *
 * Endpoint: GET|POST /wp-json/mcp/v1/mcp
 *
 * GET  → returns server discovery info (required by mcp-remote)
 * POST → handles JSON-RPC MCP messages
 *
 * Supports:
 *  - initialize          → returns server info + capabilities
 *  - tools/list          → returns all available tools
 *  - tools/call          → calls a specific tool
 *  - notifications/initialized → acknowledged, no-op
 */
class WP_MCP_Server {

    private const NAMESPACE   = 'mcp/v1';
    private const MCP_VERSION = '2025-11-25'; // Match Claude Desktop client version

    public static function init(): void {
        // Prevent WordPress from consuming the Authorization header before we can read it.
        // Some hosts strip it; this ensures we can always access it.
        if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            return;
        }
        if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
    }

    public static function register_routes(): void {
        // Main MCP endpoint — accepts GET (discovery) and POST (JSON-RPC)
        register_rest_route( self::NAMESPACE, '/mcp', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_discovery' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_request' ],
                'permission_callback' => [ __CLASS__, 'check_auth' ],
            ],
            [
                'methods'             => 'OPTIONS',
                'callback'            => [ __CLASS__, 'handle_options' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Health-check endpoint
        register_rest_route( self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => fn() => new WP_REST_Response( [ 'status' => 'ok', 'version' => WP_MCP_VERSION ] ),
            'permission_callback' => '__return_true',
        ] );
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public static function check_auth( WP_REST_Request $request ): bool|\WP_Error {
        if ( ! WP_MCP_Auth::validate( $request ) ) {
            return new \WP_Error( 'unauthorized', 'Invalid or missing API key.', [ 'status' => 401 ] );
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Request handling
    // -------------------------------------------------------------------------

    /**
     * GET handler — returns MCP server discovery metadata.
     * mcp-remote hits this first to confirm the endpoint exists and is MCP-capable.
     */
    public static function handle_discovery( WP_REST_Request $request ): WP_REST_Response {
        $response = new WP_REST_Response( [
            'name'            => 'wp-analytics-mcp-server',
            'version'         => WP_MCP_VERSION,
            'protocolVersion' => self::MCP_VERSION,
            'description'     => 'WordPress MCP server for Google Search Console and Google Analytics 4',
            'capabilities'    => [
                'tools' => [ 'listChanged' => false ],
            ],
        ], 200 );
        self::add_cors_headers( $response );
        return $response;
    }

    public static function handle_options( WP_REST_Request $request ): WP_REST_Response {
        $response = new WP_REST_Response( null, 200 );
        self::add_cors_headers( $response );
        return $response;
    }

    public static function handle_request( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return self::json_response( self::rpc_error( null, -32700, 'Parse error: empty or invalid JSON body' ) );
        }

        // Support batch requests (array of JSON-RPC objects)
        if ( isset( $body[0] ) && is_array( $body[0] ) ) {
            $results = array_filter( array_map( [ __CLASS__, 'dispatch' ], $body ) );
            return self::json_response( array_values( $results ) );
        }

        $result = self::dispatch( $body );

        // Notifications return empty array — send 202 Accepted with no body
        if ( empty( $result ) ) {
            $response = new WP_REST_Response( null, 202 );
            self::add_cors_headers( $response );
            return $response;
        }

        return self::json_response( $result );
    }

    private static function dispatch( array $rpc ): array {
        $id     = $rpc['id']     ?? null;
        $method = $rpc['method'] ?? '';
        $params = $rpc['params'] ?? [];

        try {
            $result = match( $method ) {
                'initialize'                => self::handle_initialize( $params ),
                'notifications/initialized' => null,
                'ping'                      => new \stdClass(),
                'tools/list'                => self::handle_tools_list( $params ),
                'tools/call'                => self::handle_tools_call( $params ),
                default                     => throw new \Exception( "Method not found: $method", -32601 ),
            };
        } catch ( \Exception $e ) {
            return self::rpc_error( $id, $e->getCode() ?: -32000, $e->getMessage() );
        }

        // Notifications (no id) — no response needed
        if ( $id === null ) return [];

        return self::rpc_result( $id, $result ?? new \stdClass() );
    }

    // -------------------------------------------------------------------------
    // MCP method handlers
    // -------------------------------------------------------------------------

    private static function handle_initialize( array $params ): array {
        // Accept whatever protocol version the client sends; respond with ours
        return [
            'protocolVersion' => self::MCP_VERSION,
            'capabilities'    => [
                'tools' => [ 'listChanged' => false ],
            ],
            'serverInfo' => [
                'name'    => 'wp-analytics-mcp-server',
                'version' => WP_MCP_VERSION,
            ],
        ];
    }

    private static function handle_tools_list( array $params ): array {
        $tools = array_merge(
            WP_MCP_GSC::get_tools(),
            WP_MCP_GA4::get_tools()
        );
        return [ 'tools' => $tools ];
    }

    private static function handle_tools_call( array $params ): array {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        if ( empty( $name ) ) {
            throw new \Exception( 'Missing tool name', -32602 );
        }

        if ( str_starts_with( $name, 'gsc_' ) ) {
            return WP_MCP_GSC::call_tool( $name, $args );
        }

        if ( str_starts_with( $name, 'ga4_' ) ) {
            return WP_MCP_GA4::call_tool( $name, $args );
        }

        throw new \Exception( "Unknown tool: $name", -32601 );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function rpc_result( mixed $id, mixed $result ): array {
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ];
    }

    private static function rpc_error( mixed $id, int $code, string $message ): array {
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ];
    }

    private static function json_response( array $data ): WP_REST_Response {
        $response = new WP_REST_Response( $data, 200 );
        self::add_cors_headers( $response );
        return $response;
    }

    private static function add_cors_headers( WP_REST_Response $response ): void {
        $response->header( 'Content-Type', 'application/json' );
        $response->header( 'Access-Control-Allow-Origin', '*' );
        $response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
        $response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-API-Key' );
    }
}
