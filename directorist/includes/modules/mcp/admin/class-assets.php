<?php
/**
 * Register the directly shipped MCP scripts and styles in dependency order.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Admin;

defined( 'ABSPATH' ) || exit;

/** Register the directly shipped MCP scripts and styles in dependency order. */
class Assets {
    /** Assets. */
    public static function assets(): void {
        if ( ! \ATBDP_Settings_Panel::in_settings_page() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $scripts = [
            'api-client'             => [ 'services/api-client.js', [] ],
            'adapter-installer'      => [ 'services/adapter-installer.js', [] ],
            'snippets'               => [ 'utilities/snippets.js', [] ],
            'clipboard'              => [ 'utilities/clipboard.js', [] ],
            'modal-accessibility'    => [ 'utilities/modal-accessibility.js', [] ],
            'client-configuration'   => [ 'components/client-configuration.js', [] ],
            'connection-drawer'      => [ 'components/connection-drawer.js', [ 'api-client', 'snippets', 'clipboard', 'client-configuration' ] ],
            'configuration-field'    => [ 'components/configuration-field.js', [ 'api-client', 'adapter-installer', 'connection-drawer' ] ],
            'settings-panel-adapter' => [ 'settings-panel-adapter.js', [] ],
        ];
        foreach ( $scripts as $name => $script ) {
            $dependencies = array_map( static function ( $dependency ) { return 'directorist-mcp-' . $dependency; }, $script[1] );
            if ( in_array( $name, [ 'client-configuration', 'connection-drawer', 'configuration-field' ], true ) ) {
                $dependencies[] = 'wp-i18n';
            }
            wp_register_script( 'directorist-mcp-' . $name, ATBDP_URL . 'assets/src/js/admin/mcp/' . $script[0], $dependencies, ATBDP_VERSION, true );
            if ( in_array( 'wp-i18n', $dependencies, true ) && function_exists( 'wp_set_script_translations' ) ) {
                wp_set_script_translations( 'directorist-mcp-' . $name, 'directorist', ATBDP_DIR . 'languages' );
            }
        }
        wp_enqueue_script( 'directorist-mcp-settings', ATBDP_URL . 'assets/src/js/admin/mcp/init.js', [ 'directorist-settings-manager', 'directorist-mcp-configuration-field', 'directorist-mcp-settings-panel-adapter' ], ATBDP_VERSION, true );
        wp_enqueue_style( 'directorist-mcp-base', ATBDP_URL . 'assets/src/css/admin/mcp/base.css', [], ATBDP_VERSION );
        wp_enqueue_style( 'directorist-mcp-field', ATBDP_URL . 'assets/src/css/admin/mcp/configuration-field.css', [ 'directorist-mcp-base' ], ATBDP_VERSION );
        wp_enqueue_style( 'directorist-mcp-settings', ATBDP_URL . 'assets/src/css/admin/mcp/connection-drawer.css', [ 'directorist-mcp-field' ], ATBDP_VERSION );
        wp_localize_script(
            'directorist-mcp-settings', 'directoristMcpSettings', [
                'statusUrl'    => rest_url( 'directorist/v1/mcp/status' ),
                'templatesUrl' => rest_url( 'directorist/v1/mcp/config-snippets' ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'ajaxNonce'    => wp_create_nonce( directorist_get_nonce_key() ),
                'nonceKey'     => directorist_get_nonce_key(),
                'adapterSetup' => [
                    'nonce'  => wp_create_nonce( 'updates' ),
                    'slug'   => 'mcp-adapter',
                    'plugin' => Adapter_Setup::PLUGIN_FILE,
                    'name'   => __( 'MCP Adapter', 'directorist' ),
                    'labels' => [
                        'installing' => __( 'Installing MCP Adapter…', 'directorist' ),
                        'activating' => __( 'Activating MCP Adapter…', 'directorist' ),
                        'checking'   => __( 'Checking setup…', 'directorist' ),
                        'success'    => __( 'MCP Adapter is active. You can now enable Directorist MCP.', 'directorist' ),
                        'configured' => __( 'MCP Adapter is active. Directorist MCP is ready to configure.', 'directorist' ),
                        'failed'     => __( 'MCP Adapter setup could not be completed. Please retry.', 'directorist' ),
                    ],
                ],
            ]
        );
    }
}
