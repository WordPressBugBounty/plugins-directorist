<?php
/**
 * Settings Schema for settings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Settings;

use Directorist\MCP\Extensions\Domain_Fields;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Settings Schema. */
class Settings_Schema {
    /** Registry. */
    public static function registry(): array {
        // REST requests do not load the admin helper used by the panel's role choices.
        if ( ! function_exists( 'get_editable_roles' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $panel   = new \ATBDP_Settings_Panel();
        $core    = [];
        $capture = static function ( $fields ) use ( &$core, $panel ) {
            $core = $panel->register_setting_fields( $fields );
            return $fields;
        };
        add_filter( 'atbdp_listing_type_settings_field_list', $capture, PHP_INT_MIN );
        try {
            $panel->prepare_settings();
        } finally {
            remove_filter( 'atbdp_listing_type_settings_field_list', $capture, PHP_INT_MIN );
        }
        $groups = [];
        foreach ( $panel->layouts as $menu_key => $menu ) {
            self::index_groups( $menu, $menu_key, $groups );
        }
        $registry = [];
        foreach ( $core as $key => $field ) {
            $field   = $panel->fields[ $key ] ?? $field;
            $type    = $field['type'] ?? 'text';
            $secret  = self::sensitive( $key ) || in_array( $type, [ 'button', 'button-example', 'ajax-action', 'import', 'export', 'export-data', 'restore' ], true );
            $schema  = self::field_schema( $field );
            $default = $field['value'] ?? null;
            if ( isset( $schema['type'] ) && 'number' === $schema['type'] && is_numeric( $default ) ) {
                $default = 0 + $default;
            }
            $registry[ $key ] = [
                'key'             => $key,
                'label'           => wp_strip_all_tags( $field['label'] ?? $field['title'] ?? $key ),
                'group'           => $groups[ $key ] ?? 'general',
                'owner'           => 'directorist',
                'default'         => $default,
                'schema'          => $schema,
                'readable'        => ! $secret,
                'writable'        => ! $secret && null !== $schema,
                'resettable'      => ! $secret && null !== $schema,
                'classification'  => $secret ? 'excluded' : ( $schema ? 'supported' : 'read-only' ),
                'dependencies'    => $field['show-if'] ?? [],
                'storage_adapter' => 'directorist',
                'field'           => $field
            ];
        }
        // Extensions opt in explicitly. Merely adding an admin field never exposes it.
        foreach ( (array) array_merge( (array) apply_filters( 'directorist_mcp_settings', [] ), Domain_Fields::settings() ) as $key => $registration ) {
            if ( ! is_string( $key ) || self::sensitive( $key ) || isset( $registry[ $key ] ) || ! is_array( $registration ) ) {
                continue;
            }
            $required = [ 'owner', 'schema', 'default', 'capability', 'read', 'validate', 'write', 'storage_adapter', 'sensitive' ];
            if ( array_diff( $required, array_keys( $registration ) ) || ! empty( $registration['sensitive'] ) || ! is_callable( $registration['read'] ) || ! is_callable( $registration['validate'] ) || ! is_callable( $registration['write'] ) ) {
                continue;
            }
            if ( ! is_string( $registration['owner'] ) || ! is_string( $registration['capability'] ) || ! current_user_can( $registration['capability'] ) ) {
                continue;
            }
            $registry[ $key ] = array_merge(
                $registration, [
                    'key'            => $key,
                    'label'          => $registration['label'] ?? $key,
                    'group'          => $registration['group'] ?? 'extensions',
                    'readable'       => true,
                    'writable'       => $registration['writable'] ?? true,
                    'resettable'     => ! empty( $registration['resettable'] ),
                    'classification' => isset( $registration['writable'] ) && ! $registration['writable'] ? 'read-only' : 'supported',
                    'dependencies'   => $registration['dependencies'] ?? []
                ]
            );
        }
        return $registry;
    }

    /**
     * Index groups.
     *
     * @param array $node Node.
     * @param string $group Group.
     * @param array $groups Groups.
     */
    public static function index_groups( array $node, string $group, array &$groups ): void {
        foreach ( $node['fields'] ?? [] as $key ) {
            if ( is_string( $key ) ) {
                $groups[ $key ] = $group;
            }
        }
        foreach ( [ 'sections', 'submenu' ] as $kind ) {
            foreach ( $node[ $kind ] ?? [] as $key => $child ) {
                if ( is_array( $child ) ) {
                    self::index_groups( $child, $group . '/' . $key, $groups );
                }
            }
        }
    }

    /**
     * Sensitive.
     *
     * @param string $key Key.
     */
    public static function sensitive( string $key ): bool {
        return (bool) preg_match( '/password|secret|token|api_key|private_key|public_key|client_id|license|webhook|credential|access_key|auth_key|(?:^|_)(?:pk|sk)(?:_|$)|^mcp_|^directorist_mcp_/', $key );
    }

    /**
     * Field schema.
     *
     * @param array $field Field.
     */
    public static function field_schema( array $field ): ?array {
        $type = $field['type'] ?? 'text';
        if ( 'toggle' === $type || 'switch' === $type ) {
            return [ 'type' => 'boolean' ];
        }
        if ( in_array( $type, [ 'number', 'range' ], true ) ) {
            $schema = [ 'type' => 'number' ];
            foreach ( [ 'min' => 'minimum', 'max' => 'maximum' ] as $key => $target ) {
                if ( isset( $field[ $key ] ) && is_numeric( $field[ $key ] ) ) {
                    $schema[ $target ] = 0 + $field[ $key ];
                }
            }
            return $schema;
        }
        $choices = [];
        foreach ( (array) ( $field['options'] ?? [] ) as $key => $option ) {
            if ( is_array( $option ) && isset( $option['value'] ) ) {
                $choices[] = $option['value'];
            }
        }
        if ( in_array( $type, [ 'select', 'radio', 'radio-images', 'select2', 'color', 'color-picker', 'color_picker', 'text', 'textarea', 'email', 'url' ], true ) ) {
            $schema = [ 'type' => 'string' ];
            if ( $choices ) {
                $types          = array_values(
                    array_unique(
                        array_map(
                            static function ( $choice ) {
                                return is_int( $choice ) ? 'integer' : ( is_bool( $choice ) ? 'boolean' : 'string' );
                            }, $choices
                        )
                    )
                );
                $schema['type'] = 1 === count( $types ) ? $types[0] : $types;
                $schema['enum'] = $choices;
            }
            if ( 'email' === $type ) {
                $schema['format'] = 'email';
            }
            if ( 'url' === $type ) {
                $schema['format'] = 'uri';
            }
            return $schema;
        }
        if ( in_array( $type, [ 'checkbox', 'checkbox-array', 'multi-select', 'select_multiple' ], true ) && $choices ) {
            return [ 'type' => 'array', 'items' => [ 'enum' => $choices ], 'maxItems' => count( $choices ) ];
        }
        return null;
    }

    /**
     * Value.
     *
     * @param array $definition Registered descriptor.
     * @return mixed Effective registered setting value; depends on its field schema.
     */
    public static function value( array $definition ) {
        if ( 'directorist' !== $definition['owner'] ) {
            return call_user_func( $definition['read'] );
        }
        $saved = (array) get_option( 'atbdp_option', [] );
        return array_key_exists( $definition['key'], $saved ) ? $saved[ $definition['key'] ] : $definition['default'];
    }

    /**
     * Select.
     *
     * @param array $registry Common tool registry.
     * @param array $args Validated tool input.
     */
    public static function select( array $registry, array $args ): array {
        if ( isset( $args['keys'] ) ) {
            Validation::check( is_array( $args['keys'] ) && count( $args['keys'] ) <= 100, __( 'Specify at most 100 settings keys.', 'directorist' ) );
            foreach ( $args['keys'] as $key ) {
                Validation::check( is_string( $key ) && isset( $registry[ $key ] ), __( 'Unknown or unsupported settings key.', 'directorist' ) );
            }
            $registry = array_intersect_key( $registry, array_flip( $args['keys'] ) );
        }
        return array_filter(
            $registry, static function ( $definition ) use ( $args ) {
                return ( ! isset( $args['group'] ) || 0 === strpos( $definition['group'], $args['group'] ) ) && ( empty( $args['search'] ) || false !== stripos( $definition['label'] . ' ' . $definition['key'], $args['search'] ) );
            }
        );
    }
}
