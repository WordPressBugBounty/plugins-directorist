<?php
/**
 * Coordinate preview binding, execution reservation and authorized result replay.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Extensions\Callback_Invoker;
use Directorist\MCP\Extensions\External_Operations;
use Directorist\MCP\Tool_Providers\Listings\Listing_Schema;
use Directorist\MCP\Tool_Providers\Orders\Financial_Operations;
use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Operation_Events;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Uncommitted_Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Coordinate preview binding, execution reservation and authorized result replay. */
class Write_Guard {
    private static $mutating = false;

    private static $active_key = '';

    /** Is mutating. */
    public static function is_mutating(): bool {
        return self::$mutating;
    }

    /** Fresh. */
    public static function fresh(): void {
        wp_cache_delete( 'atbdp_option', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( 'directorist_mcp_epoch', 'options' );
    }

    /**
     * Clear listing state cached by authorization before the write lock was held.
     *
     * @param array $args Single or bulk listing selectors.
     */
    private static function refresh_listings( array $args ): void {
        $items = is_array( $args['items'] ?? null ) ? array_slice( $args['items'], 0, 50 ) : [];
        $ids   = [];
        foreach ( array_merge( [ $args ], $items ) as $item ) {
            $id = ( (array) $item )['listing_id'] ?? null;
            if ( is_int( $id ) && $id > 0 ) {
                $ids[ $id ] = $id;
            }
        }
        if ( ! $ids ) {
            return;
        }

        wp_cache_delete_multiple( $ids, 'posts' );
        wp_cache_delete_multiple( $ids, 'post_meta' );
        foreach ( get_object_taxonomies( ATBDP_POST_TYPE ) as $taxonomy ) {
            wp_cache_delete_multiple( $ids, $taxonomy . '_relationships' );
        }
        // Term queries have a separate cache generation from relationship caches.
        if ( wp_using_ext_object_cache() ) {
            wp_cache_get( 'last_changed', 'terms', true );
        } else {
            wp_cache_set_terms_last_changed();
        }
        foreach ( $ids as $id ) {
            if ( ATBDP_POST_TYPE === get_post_type( $id ) ) {
                $directory = Listing_Schema::directory( $id );
                wp_cache_delete( $directory, 'term_meta' );
            }
        }
    }

    /**
     * Run.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param callable $prepare Prepare.
     */
    public static function run( string $tool, array $args, callable $prepare ): array {
        Read_Guard::assert_writable();
        Validation::check( ! self::$mutating, __( 'Nested MCP mutations are unsupported. Use one declared native transaction.', 'directorist' ), 'unsupported_operation' );
        Write_Readiness::require_supported( [], true );
        Write_Lock::lock();
        try {
            self::fresh();
            self::refresh_listings( $args );
            Access_Control::permission( $tool, $args );
            $dry = $args['dry_run'] ?? false;
            Validation::check( is_bool( $dry ), __( 'dry_run must be a boolean.', 'directorist' ) );
            $payload = $args;
            unset( $payload['dry_run'], $payload['confirm_token'], $payload['idempotency_key'] );
            $digest               = Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $payload ] );
            $confirmation_payload = $payload;
            unset( $confirmation_payload['expected_version'], $confirmation_payload['schema_version'] );
            foreach ( $confirmation_payload['items'] ?? [] as $index => $item ) {
                unset( $item['expected_version'], $item['schema_version'] );
                $confirmation_payload['items'][ $index ] = $item;
            }
            $confirmation_digest = Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $confirmation_payload ] );
            $durable             = Financial_Operations::retry( $tool, $args );
            if ( null !== $durable ) {
                return $durable;
            }
            $extension_retry = External_Operations::retry( $tool, $args );
            if ( null !== $extension_retry ) {
                return $extension_retry;
            }
            $key = '';
            if ( ! $dry ) {
                $input = $args['idempotency_key'] ?? '';
                Validation::check( is_string( $input ) && preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/', $input ), __( 'Provide an idempotency_key of 8–128 letters, numbers, dots, colons, hyphens or underscores.', 'directorist' ), 'idempotency_required' );
                $key = '_directorist_mcp_op_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $input ] );
                wp_cache_delete( $key, 'options' );
                $old = get_option( $key );
                if ( is_array( $old ) && ( 'done' !== ( $old['state'] ?? '' ) || ( $old['expires'] ?? 0 ) > time() ) ) {
                    Validation::check( hash_equals( $old['digest'], $digest ), __( 'This idempotency key was used for a different action.', 'directorist' ), 'idempotency_conflict' );
                    Validation::check( ( $old['registry'] ?? '' ) === Tool_Registry::instance()->fingerprint(), __( 'The extension contract changed. Reconcile before retrying.', 'directorist' ), 'contract_changed' );
                    if ( 'done' === $old['state'] ) {
                        Operation_Events::event( $tool, 'replay', $args );
                        return self::replay( $old['result'], $args, $tool );
                    }
                    Validation::fail( 'resolved' === ( $old['state'] ?? '' ) ? 'operation_reconciled' : 'outcome_unknown', __( 'This operation cannot be dispatched again. Inspect the target and ask an administrator to reconcile the original request.', 'directorist' ), [ 'committed' => 'unknown', 'next_step' => 'Read the target and reconcile using the request ID. Do not use a replacement key.', 'request_id' => $old['request_id'] ] );
                }
                if ( $old ) {
                    delete_option( $key );
                }
            }
            $plan   = Read_Guard::run( $prepare );
            $stored = [];
            Validation::check( isset( $plan['state'], $plan['preview'], $plan['execute'] ), __( 'The operation is unavailable.', 'directorist' ), 'unsupported_operation' );
            Write_Readiness::require_supported( $plan['tables'] ?? [], true );
            $state = Canonical_Data::hash( [ $plan['state'], get_option( 'directorist_mcp_epoch', '' ), Tool_Registry::instance()->fingerprint() ] );
            if ( $dry ) {
                $token = bin2hex( random_bytes( 24 ) );
                set_transient( 'directorist_mcp_preview_' . hash( 'sha256', $token ), [ 'digest' => $confirmation_digest, 'state' => $state, 'plan_state' => ! empty( $plan['bulk'] ) ? $plan['state'] : null, 'epoch' => get_option( 'directorist_mcp_epoch', '' ), 'user_id' => get_current_user_id(), 'tool' => $tool, 'registry' => Tool_Registry::instance()->fingerprint() ], 300 );
                Operation_Events::event( $tool, 'preview', $args );
                return [ 'dry_run' => true, 'changes' => $plan['preview'], 'confirm_token' => $token, 'expires_in_seconds' => 300, 'warnings' => $plan['warnings'] ?? [] ];
            }
            if ( ! empty( $plan['confirm'] ) ) {
                $token = $args['confirm_token'] ?? '';
                Validation::check( is_string( $token ) && $token, __( 'Preview this action using dry_run:true before executing.', 'directorist' ), 'confirmation_required' );
                $token_key = 'directorist_mcp_preview_' . hash( 'sha256', $token );
                $stored    = get_transient( $token_key );
                Validation::check( $stored && (int) $stored['user_id'] === get_current_user_id() && $stored['tool'] === $tool && hash_equals( $stored['digest'], $confirmation_digest ) && $stored['epoch'] === get_option( 'directorist_mcp_epoch', '' ) && ( $stored['registry'] ?? '' ) === Tool_Registry::instance()->fingerprint(), __( 'The confirmation is invalid or expired. Preview again.', 'directorist' ), 'confirmation_invalid' );
                // Bulk plans bind each record independently; their executor reports stale items.
                if ( empty( $plan['bulk'] ) ) {
                    Validation::check( hash_equals( $stored['state'], $state ), __( 'The state changed since preview. Preview again.', 'directorist' ), 'state_changed' );
                }
                delete_transient( $token_key );
            }
            Transaction::check_tables( $plan['tables'] ?? [] );
            $record = [ 'actor_id' => get_current_user_id(), 'tool' => $tool, 'created' => time(), 'registry' => Tool_Registry::instance()->fingerprint(), 'digest' => $digest, 'state' => 'running', 'expires' => time() + DAY_IN_SECONDS, 'request_id' => Request_Context::request_id() ];
            Validation::check( add_option( $key, $record, '', false ), __( 'Could not reserve this operation. No changes were made.', 'directorist' ), 'operation_in_progress' );
            self::$active_key = $key;
            self::$mutating   = true;
            $run              = static function () use ( $plan, $stored ) {
                return call_user_func( $plan['execute'], $stored['plan_state'] ?? null );
            };
            $result           = empty( $plan['bulk'] ) && empty( $plan['orchestrated'] ) ? Transaction::atomic( $run, $plan['tables'] ?? [], $plan['invalidate'] ?? null ) : $run();
            self::$mutating   = false;
            $record['state']  = 'done';
            $record['result'] = $result;
            if ( ! update_option( $key, $record, false ) ) {
                Validation::fail( 'outcome_unknown', __( 'The operation finished but its retry record could not be saved. Read the target to reconcile.', 'directorist' ), [ 'committed' => 'unknown' ] );
            }
            Operation_Events::event( $tool, true === ( $result['committed'] ?? false ) ? 'completed' : 'no_changes', $args );
            return $result;
        } catch ( \Throwable $error ) {
            if ( self::$mutating ) {
                self::$mutating = false;
                if ( $error instanceof Uncommitted_Failure ) {
                    // A trusted writer proved that persistence never started.
                    delete_option( self::$active_key );
                    Operation_Events::event( $tool, 'rejected', $args, $error->reason );
                    throw $error;
                }
                // Keep the running reservation: never blindly repeat a possibly committed action.
                Operation_Events::event( $tool, 'indeterminate', $args, 'outcome_unknown' );
                throw new Failure( 'outcome_unknown', __( 'The operation did not finish cleanly and may have committed. Read the target before retrying.', 'directorist' ), [ 'committed' => 'unknown', 'request_id' => Request_Context::request_id() ] );
            }
            Operation_Events::event( $tool, 'rejected', $args, $error instanceof Failure ? $error->reason : 'operation_failed' );
            throw $error;
        } finally {
            self::$active_key = '';
            Write_Lock::unlock();
        }
    }

    /**
     * Checkpoint.
     *
     * @param array $results Results.
     */
    public static function checkpoint( array $results ): void {
        if ( self::$active_key ) {
            $record             = get_option( self::$active_key );
            $record['progress'] = $results;
            update_option( self::$active_key, $record, false );
        }
    }

    /**
     * Replay.
     *
     * @param array $result Result.
     * @param array $args Validated tool input.
     * @param string $tool Unprefixed tool action name.
     */
    public static function replay( array $result, array $args, string $tool ): array {
        self::refresh_listings( [ 'listing_id' => $result['listing_id'] ?? null, 'items' => $result['results'] ?? [] ] );
        $definition = Core_Tool_Provider::definitions()[ $tool ];
        if ( isset( $definition['owner'] ) && empty( $definition['bulk_of'] ) ) {
            $result = Read_Guard::run(
                static function () use ( $definition, $result, $args ) {
                    return Result::result( Callback_Invoker::extension_callback( $definition['project'], $result, $args, get_current_user_id() ) );
                }
            );
        } elseif ( isset( $definition['bulk_of'] ) ) {
            $child  = $definition['bulk_of'];
            $inputs = array_column( $args['items'], null, 'client_ref' );
            foreach ( $result['results'] as &$item ) {
                $ref = $item['client_ref'];
                if ( empty( $item['success'] ) ) {
                    continue;
                }
                try {
                    Access_Control::permission( $child, $inputs[ $ref ] );
                    $child_definition = Core_Tool_Provider::definitions()[ $child ];
                    $projected        = Read_Guard::run(
                        static function () use ( $child_definition, $item, $inputs, $ref ) { return Callback_Invoker::extension_callback( $child_definition['project'], $item, $inputs[ $ref ], get_current_user_id() );
                        }
                    );
                    $item             = [ 'client_ref' => $ref ] + Result::result( $projected );
                } catch ( Failure $error ) {
                    $item = [ 'client_ref' => $ref, 'success' => false, 'committed' => false, 'error' => [ 'code' => 'not_found', 'message' => __( 'The item is no longer accessible.', 'directorist' ) ] ];
                }
            }
            unset( $item );
        }
        if ( isset( $result['order_id'] ) ) {
            Order_Policy::get( (int) $result['order_id'] );
        }
        if ( isset( $result['attachment_id'] ) ) {
            Access_Control::attachment( (int) $result['attachment_id'], true );
        }
        // Re-evaluate current field permissions; never replay an old private value snapshot.
        if ( isset( $result['listing_id'] ) ) {
            Access_Control::listing( (int) $result['listing_id'], in_array( $tool, [ 'trash-listing', 'restore-listing' ], true ) ? 'delete' : 'edit' );
        }
        if ( isset( $result['results'] ) ) {
            $result['results'] = array_map(
                static function ( $item ) use ( $tool, $args ) {
                    if ( isset( $item['order_id'] ) ) {
                        try {
                            Order_Policy::get( (int) $item['order_id'] );
                        } catch ( Failure $error ) {
                            return [ 'client_ref' => $item['client_ref'], 'success' => false, 'error' => [ 'code' => 'not_found' ] ];
                        }
                    }
                    if ( isset( $item['listing_id'] ) ) {
                        try {
                            $action = 'bulk-listings' === $tool && in_array( $args['action'] ?? '', [ 'trash', 'restore' ], true ) ? 'delete' : 'edit';
                            Access_Control::listing( (int) $item['listing_id'], $action );
                        } catch ( Failure $error ) {
                            return [ 'client_ref' => $item['client_ref'], 'success' => false, 'error' => [ 'code' => 'not_found', 'message' => __( 'Listing not found.', 'directorist' ) ] ];
                        }
                    }
                    unset( $item['data'], $item['fields'] );
                    return $item;
                }, $result['results']
            );
            if ( isset( $result['summary']['succeeded'], $result['summary']['failed'], $result['summary']['indeterminate'] ) ) {
                $result['summary'] = Bulk_Executor::summary( $result['results'] );
                $result['success'] = 0 === $result['summary']['failed'];
            }
        }
        unset( $result['data'], $result['fields'], $result['values'] );
        $result['idempotent_replay'] = true;
        return $result;
    }
}
