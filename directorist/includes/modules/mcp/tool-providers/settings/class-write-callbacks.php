<?php
/**
 * Write Callbacks for settings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Settings;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Write Callbacks. */
class Write_Callbacks {
    /**
     * Prepare.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function prepare( string $tool, array $args ): array {
        Access_Control::admin();
        $registry = Settings_Schema::registry();
        $changes  = 'reset-settings' === $tool ? array_fill_keys( $args['keys'], null ) : Validation::object( $args['changes'], 'changes' );
        Validation::check( count( $changes ) > 0 && count( $changes ) <= 100, __( 'Specify between 1 and 100 explicit settings.', 'directorist' ) );
        $before     = [];
        $normalized = [];
        $adapters   = [];
        foreach ( $changes as $key => $value ) {
            Validation::check( isset( $registry[ $key ] ) && $registry[ $key ]['writable'], __( 'Unsupported or sensitive settings key.', 'directorist' ), 'sensitive_field' );
            $definition = $registry[ $key ];
            if ( 'reset-settings' === $tool ) {
                Validation::check( $definition['resettable'], __( 'This setting cannot be reset.', 'directorist' ) );
                $value = $definition['default'];
            }
            $normalized[ $key ] = Settings_Validator::validate( $value, $definition );
            $before[ $key ]     = Settings_Schema::value( $definition );
            $adapters[]         = $definition['storage_adapter'];
        }
        Validation::check( 1 === count( array_unique( $adapters ) ), __( 'Use separate calls for independent settings stores.', 'directorist' ), 'unsupported_operation' );
        if ( 'directorist' !== reset( $adapters ) ) {
            $writer = $registry[ array_key_first( $normalized ) ]['write'];
            foreach ( array_keys( $normalized ) as $key ) {
                Validation::check( $registry[ $key ]['write'] === $writer, __( 'The store must provide one atomic change-set writer.', 'directorist' ), 'unsupported_operation' );
            }
        }
        // Independent stores can describe effective policy changes for the complete change set.
        $effects = [];
        foreach ( array_unique( $adapters ) as $adapter ) {
            $definition = $registry[ array_key_first( $normalized ) ];
            foreach ( array_keys( $normalized ) as $key ) {
                Validation::check( ( $registry[ $key ]['preview_changes'] ?? null ) === ( $definition['preview_changes'] ?? null ) && ( $registry[ $key ]['effects_schema'] ?? null ) === ( $definition['effects_schema'] ?? null ), __( 'The store must provide one effective change-set preview.', 'directorist' ), 'unsupported_operation' );
            }
            if ( isset( $definition['preview_changes'] ) ) {
                $effect = Validation::unwrap( call_user_func( $definition['preview_changes'], $normalized ) );
                Validation::unwrap( rest_validate_value_from_schema( $effect, $definition['effects_schema'] ) );
                $effects[ $adapter ] = $effect;
            }
        }
        $dependency_state = [];
        foreach ( $changes as $key => $unused ) {
            $dependency = $registry[ $key ]['dependencies']['where'] ?? '';
            if ( $dependency && isset( $registry[ $dependency ] ) && $registry[ $dependency ]['readable'] ) {
                $dependency_state[ $dependency ] = Settings_Schema::value( $registry[ $dependency ] );
            }
        }
        $version = Canonical_Data::hash( $before );
        if ( isset( $args['expected_version'] ) ) {
            Validation::version( $args['expected_version'], $version );
        }
        // Callables are executable registration details, not serializable schema state.
        $contract = array_intersect_key( $registry, $changes );
        foreach ( $contract as &$definition ) {
            unset( $definition['read'], $definition['write'], $definition['validate'], $definition['preview_changes'] );
        }
        return [
            'tables'  => array_values(
                array_unique(
                    array_merge(
                        ...array_map(
                            static function ( $key ) use ( $registry ) { return $registry[ $key ]['tables'] ?? [];
                            }, array_keys( $normalized )
                        )
                    )
                )
            ),
            'state'   => [ $before, $dependency_state, $contract, $effects ],
            'confirm' => true,
            'preview' => [ 'before' => $before, 'after' => $normalized, 'version' => $version ] + ( $effects ? [ 'effects' => $effects ] : [] ),
            'execute' => static function () use ( $registry, $normalized, $adapters ) {
                if ( 'directorist' === reset( $adapters ) ) {
                    $panel = new \ATBDP_Settings_Panel();
                    $panel->prepare_settings();
                    foreach ( $normalized as $key => $value ) {
                        if ( ! isset( $panel->fields[ $key ] ) ) {
                            $panel->fields[ $key ] = [ 'value' => $value ];
                        }
                    }
                    // Match the native UI's single-item JSON envelope, preserving numeric-looking strings.
                    $payload = array_map(
                        static function ( $value ) {
                            return wp_json_encode( [ $value ] );
                        }, $normalized
                    );
                    $result  = $panel->update_settings_options( $payload );
                    Validation::check( ! empty( $result['status']['success'] ), __( 'The settings update failed.', 'directorist' ), 'operation_failed' );
                    $saved = (array) get_option( 'atbdp_option', [] );
                    foreach ( $normalized as $key => $value ) {
                                Validation::check( array_key_exists( $key, $saved ) && $saved[ $key ] === $value, __( 'The settings save could not be verified.', 'directorist' ), 'operation_failed' );
                    }
                } else {
                    // A storage adapter owns the entire unit, never one write callback per key.
                    $callbacks = [];
                    foreach ( array_keys( $normalized ) as $key ) {
                        $callbacks[] = $registry[ $key ]['write'];
                    }
                    foreach ( $callbacks as $callback ) {
                        Validation::check( $callback === $callbacks[0], __( 'The store must provide one atomic change-set writer.', 'directorist' ), 'unsupported_operation' );
                    }
                    Validation::unwrap( call_user_func( $callbacks[0], $normalized ) );
                }
                return Result::result( [ 'values' => $normalized, 'version' => Canonical_Data::hash( $normalized ) ] );
            }
        ];
    }

    /**
     * Handle update-settings through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function update_settings( array $args = [] ): array {
        return self::prepare( 'update-settings', $args );
    }

    /**
     * Handle reset-settings through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function reset_settings( array $args = [] ): array {
        return self::prepare( 'reset-settings', $args );
    }
}
