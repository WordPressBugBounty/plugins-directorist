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
     * @param bool $allow_write_only Allow bounded secret strings in local mutation inputs only.
     */
    public static function schema( array $s, int $depth = 0, bool $allow_write_only = false ): void {
        Validation::check( $depth <= 10 && isset( $s['type'] ) && in_array( $s['type'], [ 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ], true ), __( 'Invalid MCP JSON schema.', 'directorist' ) );
        if ( 0 === $depth && ! $allow_write_only ) {
            Validation::check( ! self::has_write_only( $s ), __( 'Write-only inputs are supported only for local, non-bulk mutations.', 'directorist' ) );
        }
        if ( true === ( $s['writeOnly'] ?? false ) ) {
            Validation::check( $allow_write_only && 'string' === $s['type'] && ( $s['maxLength'] ?? 0 ) > 0 && $s['maxLength'] <= 4096 && ! array_key_exists( 'default', $s ) && ! array_key_exists( 'enum', $s ), __( 'Secret properties require a bounded write-only mutation input.', 'directorist' ) );
        }
        if ( 'object' === $s['type'] ) {
            Validation::check( false === ( $s['additionalProperties'] ?? null ) && isset( $s['properties'] ) && count( (array) $s['properties'] ) <= 100, __( 'Objects must declare bounded properties.', 'directorist' ) );
            foreach ( (array) $s['properties'] as $key => $child ) {
                $sensitive = (bool) preg_match( '/password|secret|credential|api_key/i', $key );
                Validation::check( ! $sensitive || true === ( $child['writeOnly'] ?? false ), __( 'Secret properties require a bounded write-only mutation input.', 'directorist' ) );
                self::schema( $child, $depth + 1, $allow_write_only );
            }
            Validation::check( ! array_diff( $s['required'] ?? [], array_keys( (array) $s['properties'] ) ), __( 'Invalid required fields.', 'directorist' ) );
        } elseif ( 'array' === $s['type'] ) {
            Validation::check( isset( $s['maxItems'], $s['items'] ) && is_int( $s['maxItems'] ) && $s['maxItems'] >= 0 && $s['maxItems'] <= 100, __( 'Arrays must declare a maximum of 100 items.', 'directorist' ) );
            self::schema( $s['items'], $depth + 1, $allow_write_only );
        } elseif ( 'string' === $s['type'] ) {
            Validation::check( isset( $s['enum'] ) || ( isset( $s['maxLength'] ) && $s['maxLength'] > 0 && $s['maxLength'] <= 65536 ), __( 'Strings must declare a length bound or enum.', 'directorist' ) );
        }
    }

    /**
     * Detect write-only fields in schema nodes used by WordPress REST validation.
     *
     * @param array $schema Input schema.
     * @param int   $depth  Schema nesting depth.
     */
    public static function has_write_only( array $schema, int $depth = 0 ): bool {
        Validation::check( $depth <= 10, __( 'Invalid MCP JSON schema.', 'directorist' ) );
        if ( true === ( $schema['writeOnly'] ?? false ) ) {
            return true;
        }

        foreach ( [ 'properties', 'patternProperties', 'anyOf', 'oneOf' ] as $keyword ) {
            foreach ( (array) ( $schema[ $keyword ] ?? [] ) as $child ) {
                if ( is_array( $child ) && self::has_write_only( $child, $depth + 1 ) ) {
                    return true;
                }
            }
        }

        foreach ( [ 'items', 'additionalProperties' ] as $keyword ) {
            if ( is_array( $schema[ $keyword ] ?? null ) && self::has_write_only( $schema[ $keyword ], $depth + 1 ) ) {
                return true;
            }
        }

        return false;
    }
}
