<?php
/**
 * Single registration inventory for core tools and extension contributions.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Registration;

use Directorist\MCP\Extensions\Descriptor_Validator;
use Directorist\MCP\Extensions\Extension_Callbacks;
use Directorist\MCP\Extensions\Extension_Lifecycle;
use Directorist\MCP\Extensions\External_Operations;
use Directorist\MCP\Extensions\Integration_Callbacks;
use Directorist\MCP\Extensions\Registration_Api;
use Directorist\MCP\Tool_Providers\Context\Tool_Provider;
use Directorist\MCP\Registry;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Read_Contracts;

defined( 'ABSPATH' ) || exit;

/** Coordinates registration without executing feature callbacks. */
class Tool_Registry {
    const API_VERSION          = 1;
    const READ_ADAPTER_VERSION = 1;

    private static ?self $instance = null;

    private Registry_State $state;

    private Registration_Api $registration_api;

    private Descriptor_Validator $descriptor_validator;

    private Extension_Lifecycle $lifecycle;

    private Integration_Callbacks $integrations;

    private Extension_Callbacks $callbacks;

    /** @var array<string, callable(array): array> Core execution strategies by tool name. */
    private array $core_callbacks = [];

    /** @var array<string, class-string<\Directorist\MCP\Contracts\Tool_Provider>> Authorization strategies by tool name. */
    private array $core_providers = [];

    /** Build core tools, isolate extension registration, then seal the inventory. */
    public static function instance(): self {
        if ( ! self::$instance ) {
            self::$instance                       = new self();
            self::$instance->state                = new Registry_State();
            self::$instance->registration_api     = new Registration_Api( self::$instance->state, self::$instance );
            self::$instance->descriptor_validator = new Descriptor_Validator( self::$instance->state, self::$instance );
            self::$instance->lifecycle            = new Extension_Lifecycle( self::$instance->state, self::$instance );
            self::$instance->integrations         = new Integration_Callbacks( self::$instance->state, self::$instance );
            self::$instance->callbacks            = new Extension_Callbacks( self::$instance->state, self::$instance );

            self::$instance->state->tools   = Core_Tool_Provider::core_definitions();
            self::$instance->core_callbacks = Core_Tool_Provider::callbacks();
            foreach ( Core_Tool_Provider::providers() as $provider ) {
                foreach ( array_keys( $provider::definitions() ) as $tool ) {
                    self::$instance->core_providers[ $tool ] = $provider;
                }
            }

            global $wp_filter;
            $hook    = $wp_filter['directorist_mcp_register'] ?? null;
            $wrapped = [];
            if ( $hook ) {
                foreach ( $hook->callbacks as $priority => $callbacks ) {
                    foreach ( $callbacks as $id => $callback ) {
                        $original                                        = $callback['function'];
                        $wrapper                                         = static function ( ...$args ) use ( $original ) {
                            try {
                                return call_user_func_array( $original, $args );
                            } catch ( \Throwable $error ) {
                                self::$instance->state->errors[] = [ 'code' => 'registration_failed' ];
                            }
                        };
                        $wrapped[]                                       = [ $priority, $id, $original, $wrapper ];
                        $hook->callbacks[ $priority ][ $id ]['function'] = $wrapper;
                    }
                }
            }
            try {
                do_action( 'directorist_mcp_register', Registry::instance() );
            } catch ( \Throwable $error ) {
                self::$instance->state->errors[] = [ 'code' => 'registration_failed' ];
            } finally {
                foreach ( $wrapped as $entry ) {
                    list( $priority, $id, $original, $wrapper ) = $entry;
                    if ( isset( $hook->callbacks[ $priority ][ $id ] ) && $hook->callbacks[ $priority ][ $id ]['function'] === $wrapper ) {
                        $hook->callbacks[ $priority ][ $id ]['function'] = $original;
                    }
                }
            }
            self::$instance->state->sealed = true;
            foreach ( self::$instance->state->tools as $name => &$definition ) {
                if ( empty( $definition['owner'] ) && in_array( $definition['group'], [ 'dashboard', 'site-health-info' ], true ) ) {
                    $definition['output_schema'] = Read_Contracts::schema( $name, self::$instance->state->read_adapters );
                }
            }
        }
        return self::$instance;
    }

    /**
     * Register.
     *
     * @param callable $callback Operation to invoke within the guarded context.
     * @param mixed $owner Owner.
     */
    public function register( callable $callback, $owner ) {
        return $this->registration_api->register( $callback, $owner );
    }

    /**
     * Register extension.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_extension( array $definition ) {
        return $this->descriptor_validator->register_extension( $definition );
    }

    /**
     * Owner.
     *
     * @param mixed $owner Owner.
     */
    public function owner( $owner ): void {
        $this->descriptor_validator->owner( $owner );
    }

    /**
     * Register tool.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_tool( array $definition ) {
        return $this->descriptor_validator->register_tool( $definition );
    }

    /** Mutation controls. */
    public static function mutation_controls(): array {
        $s = [ 'type' => 'string', 'maxLength' => 256 ];
        return [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $s, 'idempotency_key' => $s, 'expected_version' => $s ];
    }

    /**
     * Schema.
     *
     * @param array $s S.
     * @param int $depth Depth.
     */
    public static function schema( array $s, int $depth = 0 ): void {
        Schema_Validator::schema( $s, $depth );
    }

    /**
     * Register field adapter.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_field_adapter( array $definition ) {
        return $this->descriptor_validator->register_field_adapter( $definition );
    }

    /**
     * Register policy.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_policy( array $definition ) {
        return $this->descriptor_validator->register_policy( $definition );
    }

    /**
     * Register read adapter.
     *
     * @param array $definition Registered descriptor.
     */
    public function register_read_adapter( array $definition ) {
        return $this->descriptor_validator->register_read_adapter( $definition );
    }

    /**
     * Read adapters.
     *
     * @param string $domain Domain.
     * @param array $args Validated tool input.
     */
    public function read_adapters( string $domain, array $args ): array {
        return $this->lifecycle->read_adapters( $domain, $args );
    }

    /**
     * Active.
     *
     * @param string $owner Owner.
     */
    public function active( string $owner ): bool {
        return $this->lifecycle->active( $owner );
    }

    /**
     * Admitted.
     *
     * @param string $owner Owner.
     */
    public function admitted( string $owner ): bool {
        return $this->lifecycle->admitted( $owner );
    }

    /** Admission. */
    public function admission(): bool {
        return $this->lifecycle->admission();
    }

    /** Definitions. */
    public function definitions(): array {
        return $this->state->tools;
    }

    /**
     * Fields.
     *
     * @param string $domain Domain.
     * @param mixed $context Context.
     */
    public function fields( string $domain, $context = null ): array {
        return $this->lifecycle->fields( $domain, $context );
    }

    /**
     * Permission.
     *
     * @param string $name Registered action or object name.
     * @param array $args Validated tool input.
     * @param bool $discover Discover.
     */
    public function permission( string $name, array $args, bool $discover = false ): bool {
        return $this->lifecycle->permission( $name, $args, $discover );
    }

    /**
     * Available.
     *
     * @param string $name Registered action or object name.
     */
    public function available( string $name ): bool {
        return $this->lifecycle->available( $name );
    }

    /** Fingerprint. */
    public function fingerprint(): string {
        $owners = [];
        foreach ( $this->state->extensions as $owner => $definition ) {
            $owners[ $owner ] = [ $definition['version'], $definition['contract_version'], $this->active( $owner ) ];
        }
        return Canonical_Data::hash( [ self::API_VERSION, $owners, $this->state->fields, $this->state->read_adapters, $this->state->tools, $this->state->policies, get_option( 'directorist_mcp_extensions_epoch', '' ), get_site_option( 'directorist_mcp_extensions_epoch', '' ) ] );
    }

    /** Lifecycle. */
    public static function lifecycle(): void {
        Extension_Lifecycle::lifecycle();
    }

    /**
     * Integrations.
     *
     * @param array $args Validated tool input.
     */
    public function integrations( array $args ): array {
        return $this->integrations->integrations( $args );
    }

    /**
     * Read.
     *
     * @param string $name Registered action or object name.
     * @param array $args Validated tool input.
     */
    public function read( string $name, array $args ): array {
        return $this->callbacks->read( $name, $args );
    }

    /**
     * Ability output schema.
     *
     * @param array $definition Registered descriptor.
     */
    public static function ability_output_schema( array $definition ): array {
        if ( empty( $definition['write'] ) ) {
            return $definition['output_schema'];
        }
        $preview                          = $definition['preview_schema'];
        $preview['properties']['version'] = [ 'type' => 'string', 'maxLength' => 64 ];
        return [ 'type' => 'object',
            'oneOf'     => [ ! empty( $definition['external_effects'] ) ? External_Operations::output_schema( $definition ) : $definition['output_schema'], [
                'type'                 => 'object',
                'required'             => [ 'dry_run', 'changes', 'confirm_token', 'expires_in_seconds' ],
                'additionalProperties' => false,
                'properties'           => [
                    'dry_run'            => [ 'type' => 'boolean', 'enum' => [ true ] ],
                    'changes'            => $preview,
                    'confirm_token'      => [ 'type' => 'string', 'maxLength' => 64 ],
                    'expires_in_seconds' => [ 'type' => 'integer', 'enum' => [ 300 ] ],
                    'warnings'           => [ 'type' => 'array', 'maxItems' => 100, 'items' => [ 'type' => 'string', 'maxLength' => 1000 ] ],
                ],
            ]
            ]
        ];
    }

    /**
     * Prepare.
     *
     * @param string $name Registered action or object name.
     * @param array $args Validated tool input.
     */
    public function prepare( string $name, array $args ): array {
        return $this->callbacks->prepare( $name, $args );
    }

    /**
     * Execute the registered strategy after the runner has applied guards.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public function invoke( string $tool, array $args ): array {
        $definition = $this->state->tools[ $tool ];
        if ( isset( $definition['owner'] ) ) {
            return $definition['write'] ? $this->prepare( $tool, $args ) : $this->read( $tool, $args );
        }
        return call_user_func( $this->core_callbacks[ $tool ], $args );
    }

    /**
     * Apply core feature authorization around restrictive extension policies.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param string $phase Authorization phase around restrictive extension policies.
     */
    public function authorize_core( string $tool, array $args, string $phase ): void {
        $provider = $this->core_providers[ $tool ];
        $provider::authorize( $tool, $args, $phase );
    }

    /**
     * Evaluate the registered core discovery strategy.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public function discover_core( string $tool ): bool {
        $provider = $this->core_providers[ $tool ];
        return $provider::discover( $tool );
    }
}
