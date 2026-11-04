<?php
/** Validate browser origins independently of authentication and CORS.
 * @package Directorist\MCP
 */
namespace Directorist\MCP\Transport;

use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

class Origin_Policy {
    /** Reject supplied origins other than explicitly configured browser origins. */
    public static function validate( \WP_REST_Request $request ): void {
        $values = $request->get_header_as_array( 'origin' );
        if ( null === $values ) {
            return;
        }
        $origin = 1 === count( $values ) ? self::normalize( $values[0] ) : null;
        // These URLs come from installation configuration, never the inbound Host.
        // URL helpers can rewrite the configured scheme using inbound TLS state.
        // Options retain the installation's public scheme behind a TLS proxy.
        $urls    = apply_filters( 'directorist_mcp_trusted_origins', [ get_option( 'home' ), get_option( 'siteurl' ) ] );
        $trusted = [];
        foreach ( (array) $urls as $url ) {
            $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
            if ( ! is_array( $parts ) ) {
                continue;
            }
            unset( $parts['path'], $parts['query'], $parts['fragment'] );
            $candidate = isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';
            $trusted[] = self::normalize( $candidate );
        }
        Validation::check( null !== $origin && in_array( $origin, $trusted, true ), __( 'This browser Origin is not trusted for Directorist MCP.', 'directorist' ), 'origin_forbidden' );
    }

    /** Normalize only a serialized HTTP(S) origin; never repair invalid input. */
    private static function normalize( $origin ): ?string {
        if ( ! is_string( $origin ) || ! preg_match( '#\Ahttps?://(?:\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::[0-9]{1,5})?\z#i', $origin ) ) {
            return null;
        }
        $parts = wp_parse_url( $origin );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return null;
        }
        $scheme = strtolower( $parts['scheme'] );
        $port   = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
        return $port > 0 && $port <= 65535 ? $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port : null;
    }
}
