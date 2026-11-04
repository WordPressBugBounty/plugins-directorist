<?php
/**
 * Clean expired preview and replay records without removing durable financial intents.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Clean expired preview and replay records without removing durable financial intents. */
class Operation_Store {
    /** Administrator inventory for local reconciliation; bounded by option ID. */
    public static function unresolved( int $cursor = 0 ): array {
        Access_Control::admin();
        global $wpdb;
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT 100", $wpdb->esc_like( '_directorist_mcp_op_' ) . '%', $cursor ) );
        $items = [];
        foreach ( $rows as $row ) {
            $record = get_option( $row->option_name );
            if ( is_array( $record ) && ! in_array( $record['state'] ?? '', [ 'done', 'resolved' ], true ) ) {
                $items[] = array_intersect_key( $record, array_flip( [ 'actor_id', 'tool', 'created', 'request_id', 'state' ] ) );
            }
        }
        return [ 'items' => $items, 'next_cursor' => 100 === count( $rows ) ? (int) end( $rows )->option_id : null ];
    }

    /**
     * Record an administrator's investigated outcome, never redispatch a writer.
     * This local PHP API requires the original payload, actor, key and correlation.
     * A compact permanent marker continues rejecting automatic repeats.
     */
    public static function resolve( string $tool, array $args, int $actor_id, string $idempotency_key, string $request_id, string $evidence ): array {
        Access_Control::admin();
        Access_Control::permission( $tool, $args );
        Validation::check( '' !== trim( $evidence ) && strlen( $evidence ) <= 1000, __( 'Supply a concise reference to the verified native outcome.', 'directorist' ) );
        $key     = '_directorist_mcp_op_' . Canonical_Data::hash( [ get_current_blog_id(), $actor_id, $tool, $idempotency_key ] );
        $payload = $args;
        unset( $payload['dry_run'], $payload['confirm_token'], $payload['idempotency_key'] );
        $digest    = Canonical_Data::hash( [ get_current_blog_id(), $actor_id, $tool, $payload ] );
        $owns_lock = ! Write_Lock::is_locked();
        Write_Lock::lock();
        try {
            Write_Guard::fresh();
            Access_Control::admin();
            Access_Control::permission( $tool, $args );
            $record = get_option( $key );
            Validation::check( is_array( $record ) && 'running' === ( $record['state'] ?? '' ) && hash_equals( $record['digest'], $digest ) && hash_equals( $record['request_id'], $request_id ), __( 'An unresolved operation matching that payload and correlation was not found.', 'directorist' ), 'not_found' );
            $record['state']          = 'resolved';
            $record['reconciliation'] = [ 'user_id' => get_current_user_id(), 'request_id' => Request_Context::request_id(), 'resolved_at' => time(), 'evidence' => sanitize_text_field( $evidence ) ];
            unset( $record['progress'], $record['result'] );
            Validation::check( update_option( $key, $record, false ), __( 'Could not save reconciliation. The reservation remains blocked.', 'directorist' ), 'operation_failed' );
            do_action( 'directorist_mcp_operation_reconciled', [ 'tool' => $tool, 'actor_id' => $actor_id, 'request_id' => $request_id, 'reconciliation' => $record['reconciliation'] ] );
            return [ 'state' => 'resolved', 'request_id' => $request_id, 'reconciliation' => $record['reconciliation'] ];
        } finally {
            if ( $owns_lock ) {
                Write_Lock::unlock();
            }
        }
    }

    /**
     * Cleanup.
     *
     * @param int $cursor Cursor.
     */
    public static function cleanup( int $cursor = 0 ): void {
        $owns_lock = ! Write_Lock::is_locked();
        try {
            Write_Lock::lock();
        } catch ( \Directorist\MCP\Support\Failure $error ) {
            // Retaining completed caches is safe while coordination is unavailable.
            return;
        }
        try {
            self::cleanup_locked( $cursor );
        } finally {
            if ( $owns_lock ) {
                Write_Lock::unlock();
            }
        }
    }

    /** Serialize expiry with replacement reservations using the same key. */
    private static function cleanup_locked( int $cursor ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT 500", $wpdb->esc_like( '_directorist_mcp_op_' ) . '%', (int) $cursor ) );
        foreach ( $rows as $row ) {
            $name   = $row->option_name;
            $record = get_option( $name );
            if ( is_array( $record ) && 'done' === ( $record['state'] ?? '' ) && ( $record['expires'] ?? 0 ) < time() ) {
                delete_option( $name );
            }
        }
        if ( 500 === count( $rows ) ) {
            wp_schedule_single_event( time() + 60, 'directorist_mcp_cleanup', [ (int) end( $rows )->option_id ] );
        }
    }
}
