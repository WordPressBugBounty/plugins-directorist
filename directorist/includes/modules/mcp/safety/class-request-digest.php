<?php
/**
 * Bind credential-bearing MCP mutations with a key kept outside the database.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Registration\Schema_Validator;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Compute digests for mutation previews, reservations and state. */
class Request_Digest {
    /**
     * Check the registered input schema for write-only values, including nested fields.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function uses_key( string $tool ): bool {
        $schema = Tool_Registry::instance()->definitions()[ $tool ]['schema'] ?? [];
        return Schema_Validator::has_write_only( $schema );
    }

    /**
     * Hash the canonical value and preserve legacy digests for other tools.
     *
     * @param string $purpose Binding purpose.
     * @param mixed  $value   Value to bind.
     * @param bool   $keyed   Whether to protect the digest with a key.
     */
    public static function hash( string $purpose, $value, bool $keyed ): string {
        if ( ! $keyed ) {
            return Canonical_Data::hash( $value );
        }

        // wp_salt() can fall back to a secret stored in the same database.
        foreach ( [ 'AUTH_KEY', 'AUTH_SALT' ] as $constant ) {
            $key = defined( $constant ) ? constant( $constant ) : '';
            if ( is_string( $key ) && '' !== trim( $key ) && 'put your unique phrase here' !== $key && __( 'put your unique phrase here' ) !== $key ) {
                $data = serialize( [ 'directorist-mcp', $purpose, Canonical_Data::canonical( $value ) ] );
                return hash_hmac( 'sha256', $data, $key );
            }
        }

        Validation::fail( 'unsupported_operation', __( 'Set a unique WordPress authentication key or salt in wp-config.php to use this tool.', 'directorist' ) );
    }
}
