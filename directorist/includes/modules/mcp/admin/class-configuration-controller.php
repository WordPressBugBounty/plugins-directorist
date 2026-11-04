<?php
/**
 * Expose the existing configuration status, snippet and public guide routes.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Admin;

defined( 'ABSPATH' ) || exit;

/** Expose the existing configuration status, snippet and public guide routes. */
class Configuration_Controller {
    /** Admin routes. */
    public static function admin_routes(): void {
        $permission = static function () {
            return current_user_can( 'manage_options' ) ? true : new \WP_Error( 'directorist_mcp_forbidden', __( 'Configuration access is required.', 'directorist' ), [ 'status' => rest_authorization_required_code() ] );
        };
        register_rest_route( 'directorist/v1', '/mcp/status', [ 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => [ Connection_Configuration::class, 'status' ] ] );
        register_rest_route( 'directorist/v1', '/mcp/config-snippets', [ 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => [ Connection_Configuration::class, 'templates' ] ] );
        register_rest_route(
            'directorist/v1', '/mcp/guide', [
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => static function () {
                    return [
                        'title'       => __( 'Connect to Directorist MCP', 'directorist' ),
                        'endpoint'    => rest_url( 'directorist/mcp' ),
                        'steps'       => [
                            __( 'Ask a site administrator to enable Directorist Settings → Other Features → MCP. Administrators can use Configure to open the MCP Connection panel.', 'directorist' ),
                            __( 'Create a named application password for your own WordPress account from your profile. Never use or share an administrator credential.', 'directorist' ),
                            __( 'Use trusted HTTPS and a compatible HTTP MCP client with an Authorization: Basic header encoding username:application-password, or a compatible local bridge.', 'directorist' ),
                            __( 'Initialize the client, discover the tools, then call directorist-get-context to verify your identity and permissions. Preview consequential changes before executing them.', 'directorist' ),
                            __( 'Revoke the named application password in your WordPress profile to disconnect that client. Disabling Directorist MCP stops Directorist tools but does not revoke credentials for other WordPress APIs.', 'directorist' ),
                        ],
                        'limitations' => __( 'OAuth-only clients are not supported. If application passwords are unavailable, ask the administrator to review the site policy.', 'directorist' )
                    ];
                }
            ]
        );
    }
}
