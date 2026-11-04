<?php
/**
 * Register the MCP server and filter its permission-aware tool inventory.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Transport;

use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Runtime\Tool_Discovery;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;

defined( 'ABSPATH' ) || exit;

/** Bridge the WordPress MCP adapter lifecycle and Directorist tool discovery. */
class Server {
    /**
     * Register Directorist's server when the adapter initializes.
     *
     * @param McpAdapter $adapter Initialized WordPress MCP adapter.
     */
    public static function server( McpAdapter $adapter ): void {
        if ( ! Request_Context::enabled() || ! Request_Context::dependencies() ) {
            return;
        }
        $adapter->create_server(
            'directorist', 'directorist', 'mcp', __( 'Directorist MCP Server', 'directorist' ), __( 'Manage Directorist using your WordPress account permissions.', 'directorist' ), ATBDP_VERSION,
            [ Http_Transport::class ], '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler', '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
            array_map(
                static function ( string $name ): string {
                    return 'directorist/' . $name;
                }, array_keys( Core_Tool_Provider::definitions() )
            ), [], [], [ Access_Control::class, 'transport' ]
        );
    }

    /**
     * Filter tools.
     *
     * @param \WP\McpSchema\Server\Tools\DTO\Tool[] $tools Adapter tool descriptors.
     * @param McpServer $server Server providing the tool inventory.
     * @return array Permission-filtered adapter tool descriptors.
     */
    public static function filter_tools( array $tools, McpServer $server ): array {
        $definitions = Core_Tool_Provider::definitions();
        return array_values(
            array_filter(
                $tools, static function ( $tool ) use ( $definitions ) {
                    $name = method_exists( $tool, 'get_name' ) ? $tool->get_name() : ( method_exists( $tool, 'getName' ) ? $tool->getName() : ( $tool->name ?? '' ) );
                    if ( 0 !== strpos( $name, 'directorist-' ) ) {
                        return true;
                    }
                    $action = substr( $name, strlen( 'directorist-' ) );
                    return Request_Context::enabled() && isset( $definitions[ $action ] ) && Tool_Discovery::available( $action, $definitions[ $action ] );
                }
            )
        );
    }
}
