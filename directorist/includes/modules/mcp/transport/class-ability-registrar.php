<?php
/**
 * Ability Registrar for module.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Transport;

use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Runtime\Tool_Runner;
use Directorist\MCP\Support\Failure;

defined( 'ABSPATH' ) || exit;

/** Ability Registrar. */
class Ability_Registrar {
    /** @var array<string, bool> Includes failed attempts to prevent duplicate registration. */
    private static array $attempted = [];

    /** @var array<string, \WP_Ability> Only abilities successfully registered by this module. */
    private static array $registered = [];

    /** Category. */
    public static function category(): void {
        if ( Request_Context::enabled() && Request_Context::dependencies() ) {
            wp_register_ability_category( 'directorist', [ 'label' => __( 'Directorist', 'directorist' ), 'description' => __( 'Permission-aware directory management.', 'directorist' ) ] );
        }
    }

    /** Abilities. */
    public static function abilities(): void {
        if ( ! Request_Context::enabled() || ! Request_Context::dependencies() ) {
            return;
        }
        foreach ( Core_Tool_Provider::definitions() as $name => $definition ) {
            $ability = 'directorist/' . $name;
            if ( isset( self::$attempted[ $ability ] ) ) {
                continue;
            }
            self::$attempted[ $ability ] = true;
            try {
                $registered = self::register_ability( $name, $definition );
                if ( null === $registered ) {
                    self::registration_failed( $ability, 'ability_registration_failed' );
                    continue;
                }
                self::$registered[ $ability ] = $registered;
            } catch ( \Throwable $error ) {
                self::registration_failed( $ability, 'ability_registration_exception' );
            }
        }
    }

    /** Resolve the lazy WordPress registry before selecting this module's successful abilities. */
    public static function registered_names(): array {
        $abilities = wp_get_abilities();
        $names     = [];
        foreach ( array_keys( Core_Tool_Provider::definitions() ) as $name ) {
            $ability = 'directorist/' . $name;
            if ( isset( self::$registered[ $ability ], $abilities[ $ability ] ) && self::$registered[ $ability ] === $abilities[ $ability ] ) {
                $names[] = $ability;
            }
        }
        return $names;
    }

    /**
     * Register one definition without changing its public schema or callbacks.
     *
     * @param string $name Unprefixed tool name.
     * @param array $definition Registered tool descriptor.
     */
    private static function register_ability( string $name, array $definition ): ?\WP_Ability {
        return wp_register_ability(
            'directorist/' . $name, [
                'label'               => ucwords( str_replace( '-', ' ', $name ) ),
                'description'         => $definition['description'],
                'category'            => 'directorist',
                'input_schema'        => $definition['schema'],
                'permission_callback' => static function ( $args = [] ) use ( $name ) {
                    try {
                        return Access_Control::permission( $name, (array) $args );
                    } catch ( Failure $error ) {
                        return Tool_Errors::capture( $error->as_error() );
                    } catch ( \Throwable $error ) {
                        return Tool_Errors::capture( new \WP_Error( 'directorist_mcp_extension_failed', __( 'An integration could not authorize this operation.', 'directorist' ) ) );
                    }
                },
                'execute_callback'    => static function ( $args = [] ) use ( $name ) {
                    return Tool_Runner::execute( $name, (array) $args );
                },
                'meta'                => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readOnlyHint' => ! $definition['write'], 'destructiveHint' => $definition['write'], 'idempotentHint' => ! $definition['write'], 'openWorldHint' => ! empty( $definition['external_effects'] ) || in_array( $name, [ 'create-order-refund', 'get-order-operation', 'import-media' ], true ) ] ],
            ] + ( isset( $definition['output_schema'] ) ? [ 'output_schema' => Tool_Registry::ability_output_schema( $definition ) ] : [] )
        );
    }

    /**
     * Report registration failure without leaking exception or input details.
     *
     * @param string $ability Fully qualified ability name.
     * @param string $code Fixed registration failure code.
     */
    private static function registration_failed( string $ability, string $code ): void {
        $context = [ 'ability' => $ability, 'code' => $code ];
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Sanitized server-side registration diagnostic.
        error_log( '[Directorist MCP] ' . wp_json_encode( $context ) );
        // Catch inside each listener so WordPress can unwind its hook stack normally.
        global $wp_filter;
        $hook    = $wp_filter['directorist_mcp_ability_registration_failed'] ?? null;
        $wrapped = [];
        if ( $hook ) {
            foreach ( $hook->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $id => $callback ) {
                    $original                                        = $callback['function'];
                    $wrapper                                         = static function ( ...$args ) use ( $original ) {
                        try {
                            return call_user_func_array( $original, $args );
                        } catch ( \Throwable $error ) {
                            return null;
                        }
                    };
                    $wrapped[]                                       = [ $priority, $id, $original, $wrapper ];
                    $hook->callbacks[ $priority ][ $id ]['function'] = $wrapper;
                }
            }
        }
        try {
            /**
             * Fires when an individual ability cannot be registered.
             *
             * @param array $context Ability name and failure code; no request or exception data.
             */
            do_action( 'directorist_mcp_ability_registration_failed', $context );
        } finally {
            foreach ( $wrapped as $entry ) {
                list( $priority, $id, $original, $wrapper ) = $entry;
                if ( isset( $hook->callbacks[ $priority ][ $id ] ) && $hook->callbacks[ $priority ][ $id ]['function'] === $wrapper ) {
                    $hook->callbacks[ $priority ][ $id ]['function'] = $original;
                }
            }
        }
    }
}
