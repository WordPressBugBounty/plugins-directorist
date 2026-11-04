<?php
/**
 * Describe the native WordPress action needed to install the MCP dependency.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Admin;

use Directorist\MCP\Runtime\Request_Context;

defined( 'ABSPATH' ) || exit;

/** Keep dependency setup separate from MCP enablement and client credentials. */
class Adapter_Setup {
    const PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

    /**
     * Return plugin state and the current user's permitted setup action.
     *
     * WordPress's native AJAX handlers recheck these capabilities and the
     * updates nonce. This status is presentation data, not authorization.
     *
     * @return array{installed: bool, active: bool, action: string, label: string, can_setup: bool, message: string}
     */
    public static function status(): array {
        global $wp_version;

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $installed = isset( get_plugins()[ self::PLUGIN_FILE ] );
        $active    = $installed && is_plugin_active( self::PLUGIN_FILE );
        $action    = $installed ? 'activate' : 'install';
        $message   = '';

        if ( $active || class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
            $action = '';
            if ( ! Request_Context::dependencies() ) {
                $message = __( 'The active MCP Adapter is incompatible. Update it in WordPress Plugins and check setup again.', 'directorist' );
            }
        }

        if ( version_compare( (string) $wp_version, '6.9', '<' ) || ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
            $message = __( 'WordPress 6.9 or newer with the Abilities API is required before setting up the MCP Adapter.', 'directorist' );
        } elseif ( $action && ! current_user_can( 'activate_plugin', self::PLUGIN_FILE ) ) {
            $message = __( 'Ask an administrator with plugin activation permission to activate the MCP Adapter.', 'directorist' );
        } elseif ( 'install' === $action && ! current_user_can( 'install_plugins' ) ) {
            $message = __( 'Ask an administrator with plugin installation permission to install the MCP Adapter.', 'directorist' );
        }

        return [
            'installed' => (bool) $installed,
            'active'    => (bool) $active,
            'action'    => (string) $action,
            'label'     => 'install' === $action ? __( 'Install & Activate MCP Adapter', 'directorist' ) : __( 'Activate MCP Adapter', 'directorist' ),
            'can_setup' => '' !== $action && '' === $message,
            'message'   => (string) $message,
        ];
    }
}
