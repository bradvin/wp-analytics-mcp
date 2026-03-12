<?php
/**
 * Plugin Name: Analytics MCP Server
 * Plugin URI:  https://github.com/bradvin/wp-analytics-mcp
 * Description: Exposes a Model Context Protocol (MCP) endpoint for Google Search Console and Google Analytics 4, allowing Claude Desktop to query your analytics data.
 * Version:     1.0.2
 * Author:      bradvin
 * License:     GPL-3.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WP_MCP_VERSION', '1.0.2' );
define( 'WP_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WP_MCP_PLUGIN_DIR . 'includes/class-mcp-auth.php';
require_once WP_MCP_PLUGIN_DIR . 'includes/class-mcp-google-auth.php';
require_once WP_MCP_PLUGIN_DIR . 'includes/class-mcp-gsc.php';
require_once WP_MCP_PLUGIN_DIR . 'includes/class-mcp-ga4.php';
require_once WP_MCP_PLUGIN_DIR . 'includes/class-mcp-server.php';
require_once WP_MCP_PLUGIN_DIR . 'admin/class-mcp-admin.php';

// Boot the plugin
add_action( 'init', [ 'WP_MCP_Server', 'init' ] );
add_action( 'admin_menu', [ 'WP_MCP_Admin', 'register_menu' ] );
add_action( 'admin_init', [ 'WP_MCP_Admin', 'register_settings' ] );
add_action( 'rest_api_init', [ 'WP_MCP_Server', 'register_routes' ] );
