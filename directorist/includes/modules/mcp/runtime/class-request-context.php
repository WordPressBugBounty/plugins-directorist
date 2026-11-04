<?php
/**
 * Read MCP readiness and share one correlation identifier per request.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Runtime;

defined( 'ABSPATH' ) || exit;

/** Read MCP readiness and share one correlation identifier per request. */
class Request_Context {
    private static ?string $request_id = null;

    /** Request id. */
    public static function request_id(): string {
        if ( ! self::$request_id ) {
            self::$request_id = (string) wp_generate_uuid4();
        }
        return self::$request_id;
    }

    /** Enabled. */
    public static function enabled(): bool {
        return true === get_directorist_option( 'mcp_enabled', false ) || '1' === get_directorist_option( 'mcp_enabled', false );
    }

    /** Dependencies. */
    public static function dependencies(): bool {
        global $wp_version;
        return version_compare( $wp_version, '6.9', '>=' ) && function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' ) && class_exists( '\WP\MCP\Core\McpAdapter' ) && defined( '\WP\MCP\Core\McpAdapter::VERSION' ) && version_compare( \WP\MCP\Core\McpAdapter::VERSION, '0.6.1', '>=' ) && method_exists( '\WP\MCP\Core\McpAdapter', 'create_server' );
    }
}
