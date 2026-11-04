<?php
/**
 * Commit native writes atomically and invalidate caches after rollback.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Commit native writes atomically and invalidate caches after rollback. */
class Transaction {
    /**
     * Atomic.
     *
     * @param callable $callback Operation to invoke within the guarded context.
     * @param array $tables Tables.
     * @param callable|null $invalidate Invalidate.
     * @return mixed Native operation result, preserved without coercion.
     */
    public static function atomic( callable $callback, array $tables = [], ?callable $invalidate = null ) {
        Read_Guard::assert_writable();
        self::check_tables( array_merge( Write_Readiness::tables(), $tables ) );
        global $wpdb;
        $post_ids     = [];
        $term_ids     = [];
        $option_names = [];
        $post_hook    = static function ( $id ) use ( &$post_ids ) {
            $post_ids[ (int) $id ] = true;
        };
        $term_hook    = static function ( $id, $term_taxonomy_id, $taxonomy ) use ( &$term_ids ) {
            $term_ids[ $taxonomy ][ (int) $id ] = true;
        };
        $option_hook  = static function ( $name ) use ( &$option_names ) {
            $option_names[ $name ] = true;
        };
        add_action( 'clean_post_cache', $post_hook );
        add_action( 'created_term', $term_hook, 1, 3 );
        add_action( 'edited_term', $term_hook, 1, 3 );
        add_action( 'delete_term', $term_hook, 1, 3 );
        add_action( 'updated_option', $option_hook );
        add_action( 'added_option', $option_hook );
        if ( Write_Lock::is_locked() ) {
            Write_Lock::lock();
        }
        Validation::check( false !== $wpdb->query( 'START TRANSACTION' ), __( 'Could not begin a safe write transaction.', 'directorist' ), 'operation_failed' );
        try {
            $result = $callback();
            if ( Write_Lock::is_locked() ) {
                Write_Lock::lock();
            }
            Validation::check( false !== $wpdb->query( 'COMMIT' ), __( 'The database commit could not be confirmed.', 'directorist' ), 'outcome_unknown' );
            return $result;
        } catch ( \Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            foreach ( array_keys( $post_ids ) as $id ) {
                clean_post_cache( $id );
                wp_cache_delete( $id, 'post_meta' );
            }
            foreach ( $term_ids as $taxonomy => $ids ) {
                clean_term_cache( array_keys( $ids ), $taxonomy );
                foreach ( array_keys( $ids ) as $id ) {
                    wp_cache_delete( $id, 'term_meta' );
                }
            }
            foreach ( array_keys( $option_names ) as $name ) {
                wp_cache_delete( $name, 'options' );
            }
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) {
                wp_cache_set_posts_last_changed();
            }
            if ( function_exists( 'wp_cache_set_terms_last_changed' ) ) {
                wp_cache_set_terms_last_changed();
            }
            throw $error;
        } finally {
            if ( is_callable( $invalidate ) ) {
                call_user_func( $invalidate );
            }
            remove_action( 'clean_post_cache', $post_hook );
            remove_action( 'created_term', $term_hook, 1 );
            remove_action( 'edited_term', $term_hook, 1 );
            remove_action( 'delete_term', $term_hook, 1 );
            remove_action( 'updated_option', $option_hook );
            remove_action( 'added_option', $option_hook );
        }
    }

    /**
     * Check tables.
     *
     * @param array $tables Tables.
     */
    public static function check_tables( array $tables ): void {
        global $wpdb;
        foreach ( $tables as $table ) {
            Validation::check( is_string( $table ) && preg_match( '/^[A-Za-z0-9_]+$/', $table ), __( 'Invalid registered transaction table.', 'directorist' ), 'unsupported_operation' );
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
            Validation::check( '' === $wpdb->last_error && null !== $engine, __( 'MCP cannot inspect a required storage table. Check table availability and information_schema permissions.', 'directorist' ), 'write_storage_unavailable' );
            Validation::check( 'INNODB' === strtoupper( (string) $engine ), __( 'MCP writes require InnoDB for every participating table. Ask the administrator to review storage configuration.', 'directorist' ), 'unsupported_operation' );
        }
    }
}
