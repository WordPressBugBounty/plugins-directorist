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
    /** Category. */
    public static function category(): void {
        if ( Request_Context::enabled() && function_exists( 'wp_register_ability_category' ) ) {
            wp_register_ability_category( 'directorist', [ 'label' => __( 'Directorist', 'directorist' ), 'description' => __( 'Permission-aware directory management.', 'directorist' ) ] );
        }
    }

    /** Abilities. */
    public static function abilities(): void {
        if ( ! Request_Context::enabled() || ! Request_Context::dependencies() ) {
            return;
        }
        foreach ( Core_Tool_Provider::definitions() as $name => $definition ) {
            wp_register_ability(
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
    }
}
