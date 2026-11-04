<?php
/**
 * Build credential-free connection status and client setup templates.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Admin;

use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Runtime\Tool_Discovery;

defined( 'ABSPATH' ) || exit;

/** Build credential-free connection status and client setup templates. */
class Connection_Configuration {
    /** Status. */
    public static function status(): array {
        $groups = [];
        $count  = 0;
        foreach ( Core_Tool_Provider::definitions() as $name => $definition ) {
            if ( Tool_Discovery::available( $name, $definition ) ) {
                ++$count; $groups[] = $definition['group'];
            }
        }
        return [
            'enabled'                         => Request_Context::enabled(),
            'ready'                           => Request_Context::dependencies(),
            'connection_ready'                => Request_Context::dependencies(),
            'write_readiness'                 => self::write_readiness(),
            'abilities_available'             => function_exists( 'wp_register_ability' ),
            'adapter_available'               => class_exists( '\WP\MCP\Core\McpAdapter' ),
            'adapter_setup'                   => Adapter_Setup::status(),
            'https'                           => is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
            'application_passwords_available' => function_exists( 'wp_is_application_passwords_available_for_user' ) && wp_is_application_passwords_available_for_user( wp_get_current_user() ),
            'endpoint_url'                    => rest_url( 'directorist/mcp' ),
            'tools_count'                     => (int) $count,
            'groups'                          => array_values(
                array_unique(
                    array_map(
                        static function ( $group ) { return 'directories' === $group ? 'Directory Builder' : ucwords( str_replace( '-', ' ', $group ) );
                        }, $groups
                    )
                )
            ),
            'current_user_login'              => (string) wp_get_current_user()->user_login,
            'profile_url'                     => admin_url( 'profile.php#application-passwords-section' ),
            'adapter_url'                     => 'https://wordpress.org/plugins/mcp-adapter/',
            'guide_url'                       => rest_url( 'directorist/v1/mcp/guide' )
        ];
    }

    /** Publish operation-specific support without disabling permitted reads. */
    private static function write_readiness(): array {
        $status = [];
        foreach ( Core_Tool_Provider::definitions() as $name => $definition ) {
            if ( ! empty( $definition['write'] ) ) {
                $status[ $name ] = Request_Context::dependencies()
                    ? \Directorist\MCP\Safety\Write_Readiness::status( \Directorist\MCP\Safety\Write_Readiness::participants( $definition ) )
                    : [ 'ready' => false, 'code' => 'dependency_unavailable', 'message' => __( 'Resolve the WordPress Abilities API and MCP Adapter dependencies before using MCP writes.', 'directorist' ) ];
            }
        }
        return $status;
    }

    /**
     * Return credential-free client configuration templates.
     *
     * @return array|\WP_Error Client templates or a connection prerequisite error.
     */
    public static function templates() {
        if ( ! Request_Context::enabled() || ! Request_Context::dependencies() ) {
            return new \WP_Error( 'directorist_mcp_unavailable', __( 'Enable MCP and resolve its dependencies before configuring a client.', 'directorist' ), [ 'status' => 409 ] );
        }
        $url    = rest_url( 'directorist/mcp' );
        $header = 'Basic <base64(username:application-password)>';
        $http   = wp_json_encode( [ 'mcpServers' => [ 'directorist' => [ 'url' => $url, 'headers' => [ 'Authorization' => $header ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $bridge = wp_json_encode( [ 'mcpServers' => [ 'directorist' => [ 'command' => 'npx', 'args' => [ '-y', '@automattic/mcp-wordpress-remote@0.5.1' ], 'env' => [ 'WP_API_URL' => $url, 'WP_API_USERNAME' => '<username>', 'WP_API_PASSWORD' => '<application-password>', 'OAUTH_ENABLED' => 'false' ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        return [
            'clients' => [
                [ 'id' => 'claude-code', 'label' => 'Claude Code', 'instructions' => __( 'Add this HTTP MCP server in your client terminal. Treat the copied configuration as a secret.', 'directorist' ), 'snippet' => 'claude mcp add --transport http directorist ' . escapeshellarg( $url ) . ' --header "Authorization: ' . $header . '"' ],
                [ 'id' => 'claude-desktop', 'label' => 'Claude Desktop', 'instructions' => __( 'Use a local WordPress MCP bridge with Node.js installed. Add this entry to your client MCP configuration, then restart the client.', 'directorist' ), 'snippet' => $bridge ],
                [ 'id' => 'cursor', 'label' => 'Cursor', 'instructions' => __( 'Add this entry to your MCP server configuration.', 'directorist' ), 'snippet' => $http ],
                [ 'id' => 'codex', 'label' => 'Codex', 'instructions' => __( 'Use this server URL and Authorization header with an HTTP-capable MCP client.', 'directorist' ), 'snippet' => "URL: {$url}\nTransport: Streamable HTTP\nAuthorization: {$header}" ],
                [ 'id' => 'generic', 'label' => __( 'Other', 'directorist' ), 'instructions' => __( 'Your client must support HTTP with a custom Authorization header or a compatible local bridge. OAuth-only connections are not supported.', 'directorist' ), 'snippet' => $http ],
            ]
        ];
    }
}
