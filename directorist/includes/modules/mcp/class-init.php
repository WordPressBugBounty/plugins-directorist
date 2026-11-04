<?php
/**
 * Directorist MCP module entry point. Start here when exploring the module.
 *
 * @package Directorist\MCP
 */
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-autoloader.php';
Autoloader::register();

/** Composes module hooks without implementing feature behavior. */
class Init {
    private static bool $booted = false;

    /** Register the module once, retaining WordPress lifecycle priorities. */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        Admin\Settings_Integration::register_hooks();

        // Tool descriptions need translations; build after textdomain setup (10), before the MCP adapter (20).
        add_action( 'init', [ Registration\Tool_Registry::class, 'instance' ], 15 );

        foreach ( [ 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete' ] as $hook ) {
            add_action( $hook, [ Extensions\Extension_Lifecycle::class, 'lifecycle' ] );
        }

        Safety\Native_Write_Hooks::register_hooks();

        add_action( 'admin_enqueue_scripts', [ Admin\Assets::class, 'assets' ], 30 );
        add_action( 'rest_api_init', [ Admin\Configuration_Controller::class, 'admin_routes' ] );
        add_action( 'wp_abilities_api_categories_init', [ Transport\Ability_Registrar::class, 'category' ] );
        add_action( 'wp_abilities_api_init', [ Transport\Ability_Registrar::class, 'abilities' ] );
        add_action( 'mcp_adapter_init', [ Transport\Server::class, 'server' ] );
        add_filter( 'mcp_adapter_tools_list', [ Transport\Server::class, 'filter_tools' ], 10, 2 );
        add_filter( 'mcp_adapter_tool_call_result', [ Transport\Tool_Errors::class, 'result' ], 10, 5 );
        add_action( 'directorist_mcp_cleanup', [ Safety\Operation_Store::class, 'cleanup' ] );
    }
}

Init::boot();
