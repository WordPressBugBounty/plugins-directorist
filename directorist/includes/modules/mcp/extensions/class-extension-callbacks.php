<?php
/**
 * Invoke registered extension tools and validate their projected results.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Registration\Registry_State;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Tool_Providers\Dashboard\Read_Callbacks;
use Directorist\MCP\Safety\Bulk_Executor;
use Directorist\MCP\Safety\Transaction;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Invoke registered extension tools and validate their projected results. */
class Extension_Callbacks {
    const API_VERSION          = 1;
    const READ_ADAPTER_VERSION = 1;

    /** @var \Directorist\MCP\Registration\Registry_State Shared registration state. */
    private Registry_State $state;

    /** @var \Directorist\MCP\Registration\Tool_Registry Registry coordinator. */
    private Tool_Registry $registry;

    /**
     * Bind request-local registration collaborators.
     *
     * @param Registry_State $state Shared request-local registration state.
     * @param Tool_Registry $registry Common tool registry.
     */
    public function __construct( Registry_State $state, Tool_Registry $registry ) {
        $this->state    = $state;
        $this->registry = $registry;
    }

    /**
     * Read.
     *
     * @param string $name Registered action or object name.
     * @param array $args Validated tool input.
     */
    public function read( string $name, array $args ): array {
        $definition = $this->state->tools[ $name ];
        if ( 'dashboard' === ( $definition['domain'] ?? '' ) ) {
            $args['scope'] = Read_Callbacks::permission( $name, $args );
        }
        $value = Validation::unwrap( Callback_Invoker::extension_callback( $definition['read'], $args ) );
        $value = Validation::unwrap( Callback_Invoker::extension_callback( $definition['project'], $value, $args, get_current_user_id() ) );
        if ( 'site_health_info' === ( $definition['domain'] ?? '' ) ) {
            $value = Read_Support::safe_value( $value );
        }
        Validation::unwrap( rest_validate_value_from_schema( $value, $definition['output_schema'] ) );
        return $value;
    }

    /**
     * Prepare.
     *
     * @param string $name Registered action or object name.
     * @param array $args Validated tool input.
     */
    public function prepare( string $name, array $args ): array {
        $definition = $this->state->tools[ $name ];
        if ( isset( $definition['bulk_of'] ) ) {
            return Bulk_Executor::bulk( $name, $args, $definition['bulk_of'] );
        }
        $plan = Validation::unwrap( Callback_Invoker::extension_callback( $definition['prepare'], $args ) );
        Validation::check( is_array( $plan ) && isset( $plan['state'], $plan['preview'], $plan['execute'] ) && is_callable( $plan['execute'] ) && empty( $plan['bulk'] ), __( 'Invalid extension mutation plan.', 'directorist' ), 'unsupported_operation' );
        Validation::unwrap( rest_validate_value_from_schema( $plan['preview'] ?: (object) [], $definition['preview_schema'] ) );
        if ( empty( $args['dry_run'] ) ) {
            Validation::version( $args['expected_version'] ?? null, Canonical_Data::hash( $plan['state'] ) );
        }
        $plan['preview']['version'] = Canonical_Data::hash( $plan['state'] );
        $execute                    = $plan['execute'];
        $plan['confirm']            = true;
        Transaction::check_tables( $definition['tables'] );
        $plan['tables']     = $definition['tables'];
        $plan['invalidate'] = $definition['invalidate'];
        $plan['state']      = [ $plan['state'], $this->registry->fingerprint() ];
        $plan['execute']    = function () use ( $definition, $execute, $args, $name ) {
            $this->registry->permission( $name, $args );
            $value = Validation::unwrap( $execute() );
            $value = Validation::unwrap( Callback_Invoker::extension_callback( $definition['project'], $value, $args, get_current_user_id() ) );
            Validation::unwrap( rest_validate_value_from_schema( $value, $definition['output_schema'] ) );
            return Result::result( $value );
        };
        if ( ! empty( $definition['external_effects'] ) ) {
            $writer               = $plan['execute'];
            $plan['orchestrated'] = true;
            $plan['execute']      = static function () use ( $name, $args, $definition, $writer ) { return External_Operations::execute( $name, $args, $definition, $writer );
            };
        }
        return $plan;
    }
}
