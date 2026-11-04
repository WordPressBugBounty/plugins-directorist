<?php
/**
 * Validation for support.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Support;

defined( 'ABSPATH' ) || exit;

/** Validation. */
class Validation {
    /**
     * Fail.
     *
     * @param mixed $code Code.
     * @param mixed $message Message.
     * @param array $details Details.
     */
    public static function fail( $code, $message, array $details = [] ): void {
        throw new Failure( $code, $message, $details );
    }

    /**
     * Check.
     *
     * @param mixed $condition Condition.
     * @param mixed $message Message.
     * @param mixed $code Code.
     */
    public static function check( $condition, $message, $code = 'validation_failed' ): void {
        if ( ! $condition ) {
            self::fail( $code, $message );
        }
    }

    /**
     * Unwrap.
     *
     * @param mixed $result Native result or WP_Error to translate into an MCP failure.
     * @return mixed Original result after error handling.
     */
    public static function unwrap( $result ) {
        if ( is_wp_error( $result ) ) {
            self::fail( 'validation_failed', $result->get_error_message() );
        }
        return $result;
    }

    /**
     * Object.
     *
     * @param mixed $value Untrusted JSON object to validate before normalization.
     * @param mixed $label Label.
     */
    public static function object( $value, $label = 'object' ): array {
        if ( is_object( $value ) ) {
            $value = (array) $value;
        }
        self::check( is_array( $value ) && ( ! $value || ! isset( $value[0] ) ), sprintf( __( 'Expected %s to be an object.', 'directorist' ), $label ) );
        return $value;
    }

    /**
     * Allowed.
     *
     * @param array $value Value.
     * @param array $keys Keys.
     */
    public static function allowed( array $value, array $keys ): void {
        $unknown = array_diff( array_keys( $value ), $keys );
        self::check( ! $unknown, __( 'Unknown or unsupported parameter.', 'directorist' ) . ' ' . implode( ', ', $unknown ) );
    }

    /**
     * Id.
     *
     * @param mixed $value Untrusted input; numeric strings, fractions and booleans must be rejected.
     */
    public static function id( $value ): int {
        self::check( is_int( $value ) && $value > 0, __( 'A positive integer ID is required.', 'directorist' ) );
        return (int) $value;
    }

    /**
     * Ids.
     *
     * @param mixed $value Value.
     */
    public static function ids( $value ): array {
        self::check( is_array( $value ) && count( $value ) <= 100, __( 'Expected at most 100 explicit IDs.', 'directorist' ) );
        return array_values( array_unique( array_map( [ self::class, 'id' ], $value ) ) );
    }

    /**
     * Version.
     *
     * @param mixed $expected Version reviewed by the caller.
     * @param mixed $current Current native object version.
     */
    public static function version( $expected, $current ): void {
        self::check( is_string( $expected ) && hash_equals( $current, $expected ), __( 'The record changed. Read it again and obtain a new preview.', 'directorist' ), 'state_changed' );
    }

    /**
     * Taxonomy.
     *
     * @param mixed $name Registered action or object name.
     */
    public static function taxonomy( $name ): string {
        self::check( in_array( $name, [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], true ), __( 'Unsupported Directorist taxonomy.', 'directorist' ) );
        return (string) $name;
    }

    /**
     * Directory.
     *
     * @param mixed $id Id.
     */
    public static function directory( $id ): \WP_Term {
        $term = get_term( self::id( $id ), ATBDP_DIRECTORY_TYPE );
        self::check( $term && ! is_wp_error( $term ), __( 'Directory not found.', 'directorist' ), 'not_found' );
        return $term;
    }

    /**
     * Clean text.
     *
     * @param mixed $value Value.
     * @param mixed $html Html.
     */
    public static function clean_text( $value, $html = false ): string {
        self::check( is_string( $value ), __( 'A text value is required.', 'directorist' ) );
        return $html ? wp_kses_post( $value ) : sanitize_text_field( $value );
    }
}
