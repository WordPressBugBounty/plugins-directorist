<?php
/**
 * Serialize cooperating native and MCP writes using one database advisory lock.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Serialize cooperating native and MCP writes using one database advisory lock. */
class Write_Lock {
    private static $locked = false;

    /** Is locked. */
    public static function is_locked(): bool {
        return (bool) self::$locked;
    }

    /** Lock. */
    public static function lock(): void {
        if ( self::$locked ) {
            self::assert_owned();
            return;
        }
        global $wpdb;
        $name = 'directorist_mcp_' . md5( DB_NAME . ':' . $wpdb->prefix );
        $got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
        Validation::check( null !== $got && '' === $wpdb->last_error, __( 'The database write lock is unavailable. Check the connection and advisory-lock support.', 'directorist' ), 'write_storage_unavailable' );
        if ( '1' !== (string) $got ) {
            Validation::fail( 'operation_in_progress', __( 'Another Directorist operation is in progress. Retry shortly.', 'directorist' ), [ 'retryable' => true ] );
        }
        self::$locked = $name;
        try {
            self::assert_owned();
        } catch ( Failure $error ) {
            self::unlock();
            throw $error;
        }
    }

    /** A reconnected or rerouted connection must never inherit a PHP lock flag. */
    private static function assert_owned(): void {
        global $wpdb;
        $owned = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::$locked ) );
        Validation::check( '1' === (string) $owned, __( 'The database connection no longer owns the Directorist write lock. Reconcile any active operation before retrying.', 'directorist' ), 'write_storage_unavailable' );
    }

    /** Unlock. */
    public static function unlock(): void {
        if ( self::$locked ) {
            global $wpdb;
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$locked ) );
            self::$locked = false;
        }
    }

    /** Native lock. */
    public static function native_lock(): void {
        if ( self::$locked || ( Request_Context::enabled() && Request_Context::dependencies() && Write_Readiness::status()['ready'] ) ) {
            try {
                self::lock();
            } catch ( Failure $error ) {
                if ( Write_Guard::is_mutating() ) {
                    throw $error;
                }
                wp_die( esc_html( $error->getMessage() ), esc_html__( 'Directorist is busy', 'directorist' ), [ 'response' => 409 ] );
            }
            static $shutdown_registered = false;
            if ( ! $shutdown_registered ) {
                register_shutdown_function( [ self::class, 'unlock' ] );
                $shutdown_registered = true;
            }
        }
    }
}
