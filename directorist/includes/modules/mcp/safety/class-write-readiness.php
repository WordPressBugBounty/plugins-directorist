<?php
/** Establish host support separately from connection and read availability.
 * @package Directorist\MCP
 */
namespace Directorist\MCP\Safety;

use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

class Write_Readiness {
    private static array $checks = [];

    /** All native WordPress transaction participants used by MCP writers. */
    public static function tables(): array {
        global $wpdb;
        return [ $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options ];
    }

    /** Declared participants for discovery and alternate ability entry points. */
    public static function participants( array $definition ): array {
        return array_unique( array_merge( self::tables(), 'orders' === ( $definition['group'] ?? '' ) ? Order_Policy::tables() : [], $definition['tables'] ?? [] ) );
    }

    /** Cache only within this request; execution also performs fresh checks. */
    public static function status( array $tables = [], bool $fresh = false ): array {
        global $wpdb;
        $tables = array_unique( array_merge( self::tables(), $tables ) );
        sort( $tables );
        $key = $wpdb->prefix . ':' . implode( ',', $tables );
        if ( ! $fresh && isset( self::$checks[ $key ] ) ) {
            return self::$checks[ $key ];
        }
        try {
            // A unique name tests capability without waiting for product work.
            $name     = 'directorist_probe_' . wp_generate_uuid4();
            $previous = $wpdb->suppress_errors();
            try {
                $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
                Validation::check( '1' === (string) $got, __( 'MCP writes are unavailable: the database cannot acquire advisory locks. Ask the host to enable connection-scoped GET_LOCK support.', 'directorist' ), 'write_storage_unavailable' );
                try {
                    // Older backends release the first lock when acquiring a
                    // second one. A capability probe must not release product locks.
                    $second     = $name . '_2';
                    $second_got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $second ) );
                    try {
                        Validation::check( '1' === (string) $second_got, __( 'MCP writes require multiple connection-scoped advisory locks.', 'directorist' ), 'write_storage_unavailable' );
                        $first_held = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ) );
                        Validation::check( '1' === (string) $first_held, __( 'The database replaces existing advisory locks. MCP writes are unsupported on this backend.', 'directorist' ), 'write_storage_unavailable' );
                    } finally {
                        $second_released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $second ) );
                    }
                    Validation::check( '1' === (string) $second_released, __( 'The database could not release a preflight advisory lock. Check connection routing.', 'directorist' ), 'write_storage_unavailable' );
                    $owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ) );
                    Validation::check( '1' === (string) $owner, __( 'MCP writes require advisory locks on the active database connection. Check the database proxy configuration.', 'directorist' ), 'write_storage_unavailable' );
                } finally {
                    $released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
                }
                Validation::check( '1' === (string) $released, __( 'MCP writes are unavailable: the database could not release its preflight lock.', 'directorist' ), 'write_storage_unavailable' );
                Transaction::check_tables( $tables );
            } finally {
                $wpdb->suppress_errors( $previous );
            }
            return self::$checks[ $key ] = [ 'ready' => true, 'code' => '', 'message' => '' ];
        } catch ( Failure $error ) {
            return self::$checks[ $key ] = [ 'ready' => false, 'code' => $error->reason, 'message' => $error->getMessage() ];
        }
    }

    /** Fail before consuming a confirmation or reserving an operation. */
    public static function require_supported( array $tables = [], bool $fresh = false ): void {
        $status = self::status( $tables, $fresh );
        Validation::check( $status['ready'], $status['message'], $status['code'] ?: 'write_storage_unavailable' );
    }
}
