<?php
/**
 * Validate and install extension, tool, field and read-adapter descriptors.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Registration\Registry_State;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Registration\Schema_Validator;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Validate and install extension, tool, field and read-adapter descriptors. */
class Descriptor_Validator {
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
     * Register extension.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_extension( array $definition ) {
        return $this->registry->register(
            function () use ( $definition ) {
                $owner = $definition['owner'] ?? '';
                Validation::check( is_string( $owner ) && preg_match( '/^[a-z][a-z0-9-]{1,39}$/', $owner ) && 'directorist' !== $owner && ! isset( $this->state->extensions[ $owner ] ), __( 'Invalid or duplicate MCP owner.', 'directorist' ) );
                foreach ( [ 'plugin', 'label', 'version', 'contract_version', 'capability' ] as $key ) {
                    Validation::check( ! empty( $definition[ $key ] ) && is_string( $definition[ $key ] ), __( 'The extension descriptor is incomplete.', 'directorist' ) );
                }
                Validation::check( false === strpos( $definition['plugin'], '..' ) && preg_match( '#^[a-zA-Z0-9_./-]+\.php$#', $definition['plugin'] ) && self::API_VERSION === ( $definition['api_version'] ?? null ), __( 'Unsupported MCP extension API or plugin identity.', 'directorist' ) );
                Validation::check( version_compare( ATBDP_VERSION, $definition['requires_core'] ?? ATBDP_VERSION, '>=' ), __( 'The extension requires a newer Directorist version.', 'directorist' ) );
                Validation::check( is_callable( $definition['availability'] ?? null ) && is_callable( $definition['admission'] ?? null ), __( 'Availability and narrow admission callbacks are required.', 'directorist' ) );
                $this->state->extensions[ $owner ] = $definition;
                return true;
            }, $definition['owner'] ?? ''
        );
    }

    /**
     * Owner.
     *
     * @param mixed $owner Owner.
     */
    public function owner( $owner ): void {
        Validation::check( isset( $this->state->extensions[ $owner ] ), __( 'Register the MCP owner first.', 'directorist' ) );
    }

    /**
     * Register tool.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_tool( array $definition ) {
        return $this->registry->register(
            function () use ( $definition ) {
                $owner = $definition['owner'] ?? '';
                $this->owner( $owner );
                $action = $definition['action'] ?? '';
                Validation::check( is_string( $action ) && preg_match( '/^[a-z][a-z0-9-]{1,49}$/', $action ), __( 'Invalid MCP action name.', 'directorist' ) );
                $name = $owner . '-' . $action;
                Validation::check( strlen( 'directorist/' . $name ) <= 64, __( 'The ability name exceeds the supported length.', 'directorist' ) );
                Validation::check( ! isset( $this->state->tools[ $name ] ), __( 'An MCP name is already owned.', 'directorist' ) );
                foreach ( [ 'permission', 'discover', 'project' ] as $callback ) {
                    Validation::check( is_callable( $definition[ $callback ] ?? null ), __( 'Explicit permissions and output projection are required.', 'directorist' ) );
                }
                Validation::check( isset( $definition['write'] ) && is_bool( $definition['write'] ) && ! empty( $definition['description'] ) && ! empty( $definition['contract_version'] ), __( 'The tool descriptor is incomplete.', 'directorist' ) );
                Schema_Validator::schema( $definition['schema'] ?? [] );
                Schema_Validator::schema( $definition['output_schema'] ?? [] );
                Validation::check( 'object' === $definition['output_schema']['type'] && 'object' === $definition['schema']['type'] && false === ( $definition['schema']['additionalProperties'] ?? null ), __( 'Tool input must be a closed object.', 'directorist' ) );
                Validation::check( in_array( $definition['domain'] ?? '', [ 'extension', 'settings', 'directories', 'listings', 'taxonomies', 'orders', 'dashboard', 'site_health_info' ], true ), __( 'An explicit MCP domain is required.', 'directorist' ) );
                Validation::check( ! in_array( $definition['domain'], [ 'dashboard', 'site_health_info' ], true ) || ! $definition['write'], __( 'Diagnostic domains are read-only.', 'directorist' ) );
                if ( in_array( $definition['domain'], [ 'dashboard', 'site_health_info' ], true ) ) {
                    Read_Contributions::validate_inputs( $definition['schema'], $definition['domain'] );
                }
                if ( 'site_health_info' === $definition['domain'] ) {
                    Read_Contributions::validate_schema( $definition['output_schema'] );
                }
                if ( $definition['write'] ) {
                    Schema_Validator::schema( $definition['preview_schema'] ?? [] );
                    Validation::check( is_callable( $definition['prepare'] ?? null ) && is_array( $definition['tables'] ?? null ) && is_callable( $definition['invalidate'] ?? null ), __( 'Mutation planning, declared stores and cache invalidation are required.', 'directorist' ) );
                    if ( ! empty( $definition['external_effects'] ) ) {
                        Validation::check( true === ( $definition['idempotent'] ?? false ) && is_callable( $definition['dispatch'] ?? null ) && is_callable( $definition['reconcile'] ?? null ) && is_callable( $definition['verify_record'] ?? null ) && empty( $definition['bulk'] ), __( 'External tools require durable provider idempotency/reconciliation and a single explicit action.', 'directorist' ), 'unsupported_operation' );
                        Validation::check( ! isset( $this->state->tools[ $name . '-operation' ] ) && strlen( 'directorist/' . $name . '-operation' ) <= 64, __( 'The operation reader name is unavailable.', 'directorist' ) );
                    }
                    Validation::check( ! array_intersect_key( (array) $definition['schema']['properties'], Tool_Registry::mutation_controls() ), __( 'Core mutation control names are reserved.', 'directorist' ) );
                    $definition['schema']['properties']        = (array) $definition['schema']['properties'] + Tool_Registry::mutation_controls();
                    $definition['output_schema']['properties'] = (array) $definition['output_schema']['properties'] + [ 'success' => [ 'type' => 'boolean' ], 'committed' => [ 'type' => [ 'boolean', 'string' ] ], 'idempotent_replay' => [ 'type' => 'boolean' ] ];
                } else {
                    Validation::check( is_callable( $definition['read'] ?? null ), __( 'A reader is required.', 'directorist' ) );
                }
                $definition['group'] = $definition['group'] ?? $owner;
                Validation::check( is_string( $definition['group'] ) && strlen( $definition['group'] ) >= 1 && strlen( $definition['group'] ) <= 80 && is_string( $definition['description'] ) && strlen( $definition['description'] ) <= 2000 && is_string( $definition['contract_version'] ), __( 'Invalid group, description or contract version.', 'directorist' ) );
                if ( ! empty( $definition['bulk'] ) ) {
                    Validation::check( $definition['write'] && true === $definition['bulk'] && ! isset( $this->state->tools[ $owner . '-bulk-' . $action ] ), __( 'Invalid bulk registration.', 'directorist' ) );
                    $bulk                = $definition;
                    $bulk['bulk_of']     = $name;
                    $bulk['description'] = __( 'Confirmed bounded batch: ', 'directorist' ) . $definition['description'];
                    $item                = $definition['schema'];
                    foreach ( array_keys( Tool_Registry::mutation_controls() ) as $control ) {
                        if ( 'expected_version' !== $control ) {
                            unset( $item['properties'][ $control ] );
                        }
                    }
                    $item['properties']['client_ref'] = [ 'type' => 'string', 'maxLength' => 64, 'minLength' => 1 ];
                    $item['required'][]               = 'client_ref';
                    $bulk['schema']                   = [ 'type' => 'object', 'properties' => [ 'items' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $item ] ] + Tool_Registry::mutation_controls(), 'required' => [ 'items' ], 'additionalProperties' => false ];
                    unset( $bulk['output_schema'] );
                    $this->state->tools[ $owner . '-bulk-' . $action ] = $bulk;
                }
                $this->state->tools[ $name ] = $definition;
                if ( ! empty( $definition['external_effects'] ) ) {
                    $this->state->tools[ $name . '-operation' ] = [
                        'owner'            => $owner,
                        'domain'           => 'extension',
                        'group'            => $definition['group'],
                        'write'            => false,
                        'contract_version' => $definition['contract_version'],
                        'description'      => __( 'Read the original external operation without dispatching or repeating its writer.', 'directorist' ),
                        'schema'           => [ 'type' => 'object', 'properties' => [ 'operation_id' => [ 'type' => 'string', 'maxLength' => 36 ] ], 'required' => [ 'operation_id' ], 'additionalProperties' => false ],
                        'output_schema'    => External_Operations::output_schema( $definition ),
                        'discover'         => $definition['discover'],
                        'permission'       => static function () { return true;
                        },
                        'read'             => static function ( $args ) use ( $name ) { return External_Operations::read( $name, $args );
                        },
                        'project'          => static function ( $value ) { return $value;
                        },
                    ];
                }
                return true;
            }, $definition['owner'] ?? ''
        );
    }

    /**
     * Register field adapter.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_field_adapter( array $definition ) {
        return $this->registry->register(
            function () use ( $definition ) {
                $this->owner( $definition['owner'] ?? '' );
                $domain = $definition['domain'] ?? '';
                $key    = $definition['key'] ?? '';
                Validation::check( in_array( $domain, [ 'settings', 'builder', 'listings', 'taxonomies', 'orders', 'order_items', 'refund_providers' ], true ), __( 'Unsupported adapter domain.', 'directorist' ) );
                Validation::check( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_-]{1,79}$/', $key ) && ! isset( $this->state->fields[ $domain ][ $key ] ), __( 'Invalid or duplicate adapter identity.', 'directorist' ) );
                Validation::check( false === ( $definition['sensitive'] ?? null ) && ! preg_match( '/password|secret|token|credential|api_key|mcp_enabled/i', $key ), __( 'Sensitive MCP fields are excluded.', 'directorist' ) );
                foreach ( [ 'permission', 'read', 'validate' ] as $callback ) {
                    Validation::check( is_callable( $definition[ $callback ] ?? null ), __( 'Field adapters require explicit permissions, reads and validation.', 'directorist' ) );
                }
                Validation::check( ! empty( $definition['contract_version'] ) && array_key_exists( 'default', $definition ), __( 'Field defaults and versions are required.', 'directorist' ) );
                Schema_Validator::schema( $definition['schema'] ?? [] );
                if ( ! empty( $definition['writable'] ) ) {
                    Validation::check( is_callable( $definition['write'] ?? null ) && is_array( $definition['tables'] ?? null ) && is_callable( $definition['invalidate'] ?? null ) && ! empty( $definition['storage_adapter'] ), __( 'Writable adapters need complete persistence contracts.', 'directorist' ) );
                }
                if ( isset( $definition['preview_changes'] ) || isset( $definition['effects_schema'] ) ) {
                    Validation::check( 'settings' === $domain && ! empty( $definition['writable'] ) && is_callable( $definition['preview_changes'] ?? null ), __( 'Effective previews require a writable settings adapter.', 'directorist' ) );
                    Schema_Validator::schema( $definition['effects_schema'] ?? [] );
                }
                if ( 'refund_providers' === $domain ) {
                    foreach ( [ 'dispatch', 'reconcile' ] as $callback ) {
                        Validation::check( is_callable( $definition[ $callback ] ?? null ), __( 'Providers require dispatch and reconciliation.', 'directorist' ) );
                    }
                    Validation::check( true === ( $definition['idempotent'] ?? false ), __( 'Provider duplicate protection is required.', 'directorist' ) );
                }
                $this->state->fields[ $domain ][ $key ] = $definition;
                return true;
            }, $definition['owner'] ?? ''
        );
    }

    /**
     * Register policy.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_policy( array $definition ) {
        return $this->registry->register(
            function () use ( $definition ) {
                $this->owner( $definition['owner'] ?? '' );
                Validation::check( is_callable( $definition['check'] ?? null ) && ! empty( $definition['key'] ) && ! isset( $this->state->policies[ $definition['owner'] . ':' . $definition['key'] ] ), __( 'Invalid or duplicate restriction policy.', 'directorist' ) );
                $this->state->policies[ $definition['owner'] . ':' . $definition['key'] ] = $definition;
                return true;
            }, $definition['owner'] ?? ''
        );
    }

    /**
     * Register read adapter.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_read_adapter( array $definition ) {
        return $this->registry->register(
            function () use ( $definition ) {
                $this->owner( $definition['owner'] ?? '' );
                $domain = $definition['domain'] ?? '';
                Validation::check( in_array( $domain, [ 'dashboard', 'site_health_info' ], true ) && self::READ_ADAPTER_VERSION === ( $definition['api_version'] ?? null ), __( 'Unsupported read-adapter contract.', 'directorist' ) );
                Validation::check( isset( $definition['key'] ) && is_string( $definition['key'] ) && preg_match( '/^[a-z][a-z0-9-]{1,39}$/', $definition['key'] ), __( 'Invalid read-adapter key.', 'directorist' ) );
                $key = $definition['owner'] . '-' . $definition['key'];
                Validation::check( ! isset( $this->state->read_adapters[ $domain ][ $key ] ), __( 'A read-adapter key is already owned.', 'directorist' ) );
                foreach ( [ 'label', 'contract_version', 'source_version', 'units', 'range_semantics' ] as $field ) {
                    Validation::check( ! empty( $definition[ $field ] ) && is_string( $definition[ $field ] ) && strlen( $definition[ $field ] ) <= 256 && null !== Read_Support::safe_value( $definition[ $field ] ), __( 'Incomplete or unsafe read-adapter metadata.', 'directorist' ) );
                }
                Validation::check( 'public' === ( $definition['sensitivity'] ?? null ) && empty( $definition['private'] ) && ! isset( $definition['write'] ) && 'none' === ( $definition['cache'] ?? null ), __( 'Read adapters must be explicitly public and uncached without a writer.', 'directorist' ) );
                Validation::check( in_array( $definition['kind'] ?? '', 'dashboard' === $domain ? [ 'overview', 'metric', 'guidance' ] : [ 'section' ], true ), __( 'Unsupported contribution kind.', 'directorist' ) );
                Validation::check( is_array( $definition['link_hosts'] ?? [] ) && count( $definition['link_hosts'] ?? [] ) <= 10, __( 'Declare at most ten public guidance hosts.', 'directorist' ) );
                foreach ( $definition['link_hosts'] ?? [] as $host ) {
                    Validation::check( 'dashboard' === $domain && 'guidance' === $definition['kind'] && is_string( $host ) && preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $host ) && ! preg_match( '/(?:\.local|\.test|\.invalid|\.internal|\.localhost|\.[0-9]+)$/', $host ), __( 'Only registered public guidance hosts are permitted.', 'directorist' ) );
                }
                Validation::check( ! empty( $definition['scopes'] ) && is_array( $definition['scopes'] ) && ! array_diff( $definition['scopes'], 'dashboard' === $domain ? [ 'site', 'own' ] : [ 'site', 'network' ] ), __( 'Explicit contribution scopes are required.', 'directorist' ) );
                Validation::check( isset( $definition['filters'] ) && is_array( $definition['filters'] ) && ! array_diff( $definition['filters'], [ 'directory_id', 'period', 'start_date', 'end_date', 'currency', 'compare_previous', 'series_kind', 'page', 'per_page' ] ), __( 'Declare supported read filters.', 'directorist' ) );
                foreach ( [ 'permission', 'availability', 'read', 'project' ] as $callback ) {
                    Validation::check( is_callable( $definition[ $callback ] ?? null ), __( 'Read-adapter callbacks are required.', 'directorist' ) );
                }
                Schema_Validator::schema( $definition['output_schema'] ?? [] );
                Validation::check( 'object' === $definition['output_schema']['type'], __( 'Contribution output must be a closed typed object.', 'directorist' ) );
                Read_Contributions::validate_schema( $definition['output_schema'] );
                $registered = $this->state->read_adapters[ $domain ] ?? [];
                Validation::check( count( $registered ) < 100 && strlen( wp_json_encode( array_column( $registered, 'output_schema' ) ) ) + strlen( wp_json_encode( $definition['output_schema'] ) ) <= 262144, __( 'Read contributions exceed the bounded discovery inventory.', 'directorist' ) );
                $definition['id']                              = $key;
                $this->state->read_adapters[ $domain ][ $key ] = $definition;
                return true;
            }, $definition['owner'] ?? ''
        );
    }
}
