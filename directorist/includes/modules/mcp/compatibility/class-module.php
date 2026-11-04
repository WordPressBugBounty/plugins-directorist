<?php
/**
 * Compatibility facade for the original Module API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Admin\Assets;
use Directorist\MCP\Admin\Configuration_Controller;
use Directorist\MCP\Admin\Connection_Configuration;
use Directorist\MCP\Admin\Settings_Integration;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Transport\Ability_Registrar;
use Directorist\MCP\Transport\Server;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Module {
    /** Forward boot to its implementation. */
    public static function boot() {
        return Init::boot();
    }

    /** Forward request id to its implementation. */
    public static function request_id() {
        return Request_Context::request_id();
    }

    /** Forward enabled to its implementation. */
    public static function enabled() {
        return Request_Context::enabled();
    }

    /** Forward dependencies to its implementation. */
    public static function dependencies() {
        return Request_Context::dependencies();
    }

    /**
     * Forward fields to its implementation.
     *
     * @param mixed $fields Fields.
     */
    public static function fields( $fields ) {
        return Settings_Integration::fields( $fields );
    }

    /**
     * Forward layout to its implementation.
     *
     * @param mixed $layouts Layouts.
     */
    public static function layout( $layouts ) {
        return Settings_Integration::layout( $layouts );
    }

    /** Forward assets to its implementation. */
    public static function assets() {
        return Assets::assets();
    }

    /** Forward before settings save to its implementation. */
    public static function before_settings_save() {
        return Settings_Integration::before_settings_save();
    }

    /**
     * Forward option change to its implementation.
     *
     * @param mixed $value Value.
     * @param mixed $old Old.
     */
    public static function option_change( $value, $old ) {
        return Settings_Integration::option_change( $value, $old );
    }

    /** Forward admin routes to its implementation. */
    public static function admin_routes() {
        return Configuration_Controller::admin_routes();
    }

    /** Forward status to its implementation. */
    public static function status() {
        return Connection_Configuration::status();
    }

    /** Forward templates to its implementation. */
    public static function templates() {
        return Connection_Configuration::templates();
    }

    /** Forward category to its implementation. */
    public static function category() {
        return Ability_Registrar::category();
    }

    /** Forward abilities to its implementation. */
    public static function abilities() {
        return Ability_Registrar::abilities();
    }

    /**
     * Forward server to its implementation.
     *
     * @param \WP\MCP\Core\McpAdapter $adapter Initialized WordPress MCP adapter.
     */
    public static function server( $adapter ) {
        // Keep the legacy no-op for unsupported adapters at the compatibility boundary.
        if ( ! $adapter instanceof \WP\MCP\Core\McpAdapter ) {
            return;
        }
        return Server::server( $adapter );
    }

    /**
     * Forward filter tools to its implementation.
     *
     * @param mixed $tools Tools.
     * @param mixed $server Server.
     */
    public static function filter_tools( $tools, $server ) {
        return Server::filter_tools( $tools, $server );
    }
}
