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

defined( 'ABSPATH' ) || exit;

/** Bridge the WordPress MCP adapter lifecycle and Directorist tool discovery. */
class Server {
    /** @var \SplObjectStorage|null Successful and in-progress registrations, scoped to each adapter object. */
    private static ?\SplObjectStorage $registrations = null;

    /**
     * Register Directorist's server when the adapter initializes.
     *
     * @param mixed $adapter Adapter supplied by a shared WordPress hook.
     */
    public static function server( $adapter ): void {
        if ( ! $adapter || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
            return;
        }
        if ( ! Request_Context::enabled() || ! Request_Context::dependencies() ) {
            return;
        }
        if ( null === self::$registrations ) {
            self::$registrations = new \SplObjectStorage();
        }
        if ( self::$registrations->contains( $adapter ) ) {
            return;
        }

        self::$registrations[ $adapter ] = false;
        try {
            $abilities = Ability_Registrar::registered_names();
            $result    = $adapter->create_server(
                'directorist', 'directorist', 'mcp', __( 'Directorist MCP Server', 'directorist' ), __( 'Manage Directorist using your WordPress account permissions.', 'directorist' ), ATBDP_VERSION,
                [ Http_Transport::class ], '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler', '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
                $abilities, [], [], [ Access_Control::class, 'transport' ]
            );
            if ( is_wp_error( $result ) || ! is_object( $result ) ) {
                self::registration_failed();
                return;
            }
            self::$registrations[ $adapter ] = true;
        } catch ( \Throwable $error ) {
            self::registration_failed();
        } finally {
            if ( ! self::$registrations[ $adapter ] ) {
                self::$registrations->detach( $adapter );
            }
        }
    }

    /** Log a fixed diagnostic without exposing adapter errors or request data. */
    private static function registration_failed(): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Sanitized server-side registration diagnostic.
        error_log( '[Directorist MCP] server_creation_failed' );
    }

    /**
     * Filter tools.
     *
     * @param \WP\McpSchema\Server\Tools\DTO\Tool[] $tools Adapter tool descriptors.
     * @param mixed $server Server supplied by a shared WordPress hook.
     * @return array Permission-filtered adapter tool descriptors.
     */
    public static function filter_tools( array $tools, $server ): array {
        $definitions = null;
        $filtered    = [];
        foreach ( $tools as $tool ) {
            $name = self::tool_name( $tool );
            if ( 0 !== strpos( $name, 'directorist-' ) ) {
                $filtered[] = $tool;
                continue;
            }
            if ( null === $definitions ) {
                $definitions = Core_Tool_Provider::definitions();
            }
            $action = substr( $name, strlen( 'directorist-' ) );
            if ( Request_Context::enabled() && isset( $definitions[ $action ] ) && Tool_Discovery::available( $action, $definitions[ $action ] ) ) {
                $filtered[] = $tool;
            }
        }
        return null === $definitions ? $tools : $filtered;
    }

    /**
     * Read either supported DTO naming convention without a concrete class requirement.
     *
     * @param mixed $tool Tool descriptor supplied by an adapter.
     */
    private static function tool_name( $tool ): string {
        if ( ! is_object( $tool ) ) {
            return '';
        }
        $name = is_callable( [ $tool, 'get_name' ] ) ? $tool->get_name() : ( is_callable( [ $tool, 'getName' ] ) ? $tool->getName() : ( $tool->name ?? '' ) );
        return is_string( $name ) ? $name : '';
    }
}
