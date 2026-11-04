<?php
/**
 * Schema Validator for registry.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Registration;

use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Schema Validator. */
class Schema_Validator {
    const API_VERSION          = 1;
    const READ_ADAPTER_VERSION = 1;

    /**
     * Schema.
     *
     * @param array $s S.
     * @param int $depth Depth.
     */
    public static function schema( array $s, int $depth = 0 ): void {
        Validation::check( $depth <= 10 && isset( $s['type'] ) && in_array( $s['type'], [ 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ], true ), __( 'Invalid MCP JSON schema.', 'directorist' ) );
        if ( 'object' === $s['type'] ) {
            Validation::check( false === ( $s['additionalProperties'] ?? null ) && isset( $s['properties'] ) && count( (array) $s['properties'] ) <= 100, __( 'Objects must declare bounded properties.', 'directorist' ) );
            foreach ( (array) $s['properties'] as $key => $child ) {
                Validation::check( ! preg_match( '/password|secret|credential|api_key/i', $key ), __( 'Secret properties are excluded from MCP schemas.', 'directorist' ) );
                self::schema( $child, $depth + 1 );
            }
            Validation::check( ! array_diff( $s['required'] ?? [], array_keys( (array) $s['properties'] ) ), __( 'Invalid required fields.', 'directorist' ) );
        } elseif ( 'array' === $s['type'] ) {
            Validation::check( isset( $s['maxItems'], $s['items'] ) && is_int( $s['maxItems'] ) && $s['maxItems'] >= 0 && $s['maxItems'] <= 100, __( 'Arrays must declare a maximum of 100 items.', 'directorist' ) );
            self::schema( $s['items'], $depth + 1 );
        } elseif ( 'string' === $s['type'] ) {
            Validation::check( isset( $s['enum'] ) || ( isset( $s['maxLength'] ) && $s['maxLength'] > 0 && $s['maxLength'] <= 65536 ), __( 'Strings must declare a length bound or enum.', 'directorist' ) );
        }
    }
}
