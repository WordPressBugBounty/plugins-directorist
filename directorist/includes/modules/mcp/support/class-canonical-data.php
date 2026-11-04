<?php
/**
 * Canonical Data for support.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Support;

defined( 'ABSPATH' ) || exit;

/** Canonical Data. */
class Canonical_Data {
    /**
     * Hash.
     *
     * @param mixed $value Value.
     */
    public static function hash( $value ): string {
        return hash( 'sha256', wp_json_encode( self::canonical( $value ) ) );
    }

    /**
     * Canonical.
     *
     * @param mixed $value Value.
     * @return mixed Canonicalized value with scalar types preserved.
     */
    public static function canonical( $value ) {
        if ( $value instanceof \Closure ) {
            return 'registered-callback';
        }
        if ( is_object( $value ) ) {
            $value = (array) $value;
        }
        if ( is_array( $value ) ) {
            if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
                ksort( $value );
            }
            foreach ( $value as &$item ) {
                $item = self::canonical( $item );
            }
        }
        return $value;
    }
}
