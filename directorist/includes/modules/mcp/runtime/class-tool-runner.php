<?php
/**
 * Validate and execute tools through shared authorization and safety guards.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Runtime;

use Directorist\MCP\Extensions\Domain_Fields;
use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Safety\Read_Guard;
use Directorist\MCP\Safety\Write_Guard;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Operation_Events;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Validate and execute tools through shared authorization and safety guards. */
class Tool_Runner {
    /**
     * Execute.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @return array|\WP_Error Structured tool result or safe execution error.
     */
    public static function execute( string $tool, array $args = [] ) {
        try {
            $definitions = Core_Tool_Provider::definitions();
            Validation::check( isset( $definitions[ $tool ] ), __( 'Unsupported Directorist tool.', 'directorist' ), 'unsupported_operation' );
            Validation::check( strlen( wp_json_encode( $args ) ) <= 1048576, __( 'The request is too large.', 'directorist' ) );
            $definition = $definitions[ $tool ];
            Validation::allowed( $args, array_keys( (array) $definition['schema']['properties'] ) );
            Validation::unwrap( rest_validate_value_from_schema( $args ?: (object) [], $definition['schema'] ) );
            Access_Control::permission( $tool, $args );
            if ( ! $definition['write'] ) {
                return Read_Guard::run(
                    static function () use ( $tool, $args, $definition ) {
                        $result = self::read( $tool, $args, $definition['group'] );
                        if ( isset( $definition['output_schema'] ) ) {
                            Validation::check( ! is_wp_error( rest_validate_value_from_schema( $result, $definition['output_schema'] ) ), __( 'The read source returned an invalid result.', 'directorist' ), 'source_invalid' );
                        }
                        return $result;
                    }, false, in_array( $definition['group'], [ 'dashboard', 'site-health-info' ], true ) || in_array( $definition['domain'] ?? '', [ 'dashboard', 'site_health_info' ], true )
                );
            }
            $result = Write_Guard::run(
                $tool, $args, static function () use ( $tool, $args ) {
                    return self::prepare( $tool, $args );
                }
            );
            if ( isset( $result['operation_id'] ) && empty( $result['success'] ) ) {
                Validation::fail( 'failed' === $result['state'] ? 'provider_failed' : 'outcome_unknown', __( 'Read the original operation to reconcile this outcome. Do not submit a replacement financial action.', 'directorist' ), $result );
            }
            return $result;
        } catch ( Failure $error ) {
            $result             = $error->as_error();
            $data               = $result->get_error_data();
            $data['request_id'] = $data['request_id'] ?? Request_Context::request_id();
            $data['attempt_id'] = Request_Context::request_id();
            $result->add_data( $data );
            return $result;
        } catch ( \Throwable $error ) {
            Operation_Events::event( $tool, 'failed', $args, 'operation_failed' );
            return new \WP_Error( 'directorist_mcp_operation_failed', __( 'Directorist could not complete this operation.', 'directorist' ), [ 'code' => 'operation_failed', 'committed' => false, 'retryable' => false, 'request_id' => Request_Context::request_id() ] );
        }
    }

    /**
     * Prepare.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function prepare( string $tool, array $args ): array {
        $definition = Tool_Registry::instance()->definitions()[ $tool ];
        if ( isset( $definition['owner'] ) || 0 === strpos( $tool, 'bulk-' ) ) {
            return Tool_Registry::instance()->invoke( $tool, $args );
        }
        return Domain_Fields::prepare(
            $tool, $args, $definition['group'], static function ( $base ) use ( $tool ) {
                return Tool_Registry::instance()->invoke( $tool, $base );
            }
        );
    }

    /**
     * Read.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param string $group Group.
     */
    public static function read( string $tool, array $args, string $group ): array {
        $definition = Tool_Registry::instance()->definitions()[ $tool ];
        $result     = Tool_Registry::instance()->invoke( $tool, $args );
        if ( isset( $definition['owner'] ) || ! in_array( $group, [ 'settings', 'directories', 'listings', 'taxonomies', 'orders' ], true ) ) {
            return $result;
        }
        return Domain_Fields::read( $tool, $args, $group, $result );
    }
}
