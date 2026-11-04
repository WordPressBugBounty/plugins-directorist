<?php
/**
 * Settings Validator for settings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Settings;

use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Settings Validator. */
class Settings_Validator {
    /**
     * Validate.
     *
     * @param mixed $value Value.
     * @param array $definition Registered descriptor.
     * @return mixed Validated value matching the registered setting schema.
     */
    public static function validate( $value, array $definition ) {
        $schema = $definition['schema'];
        if ( isset( $schema['enum'] ) && is_scalar( $value ) ) {
            foreach ( $schema['enum'] as $choice ) {
                if ( (string) $choice === (string) $value ) {
                    $value = $choice;
                    break;
                }
            }
        }
        if ( '' === $value && isset( $schema['format'] ) ) {
            unset( $schema['format'] );
        }
        if ( null !== $schema ) {
            Validation::unwrap( rest_validate_value_from_schema( $value, $schema, $definition['key'] ) );
        }
        if ( 'directorist' !== $definition['owner'] ) {
            return Validation::unwrap( call_user_func( $definition['validate'], $value ) );
        }
        $type = $definition['field']['type'] ?? 'text';
        if ( in_array( $type, [ 'number', 'range' ], true ) ) {
            return 0 + $value;
        }
        if ( in_array( $type, [ 'toggle', 'switch' ], true ) ) {
            return in_array( $value, [ true, 1, '1', 'true' ], true );
        }
        if ( is_string( $value ) ) {
            if ( in_array( $type, [ 'color', 'color-picker', 'color_picker' ], true ) ) {
                Validation::check( (bool) sanitize_hex_color( $value ), __( 'Use a valid hex color.', 'directorist' ) );
            }
            $value = 'textarea' === $type ? wp_kses_post( $value ) : sanitize_text_field( $value );
        }
        if ( preg_match( '/(^|_)(page|page_id)$/', $definition['key'] ) && $value && is_numeric( $value ) ) {
            Validation::check( 'page' === get_post_type( (int) $value ) && current_user_can( 'read_post', (int) $value ), __( 'Select an accessible WordPress page.', 'directorist' ) );
        }
        return $value;
    }
}
