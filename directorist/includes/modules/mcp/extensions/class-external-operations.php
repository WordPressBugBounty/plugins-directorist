<?php
/**
 * External Operations for extension operations.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Safety\Read_Guard;
use Directorist\MCP\Safety\Transaction;
use Directorist\MCP\Safety\Write_Lock;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** External Operations. */
class External_Operations {
    /**
     * Key.
     *
     * @param mixed $id Id.
     */
    private static function key( $id ): string {
        Validation::check( is_string( $id ) && preg_match( '/^[a-f0-9-]{36}$/', $id ), __( 'Operation not found.', 'directorist' ), 'not_found' );
        return '_directorist_mcp_extension_operation_' . $id;
    }

    /**
     * Save.
     *
     * @param array $record Record.
     */
    private static function save( array $record ): void {
        $key = self::key( $record['operation_id'] );
        update_option( $key, $record, false );
        Validation::check( get_option( $key ) === $record, __( 'The operation state could not be saved.', 'directorist' ), 'outcome_unknown' );
    }

    /**
     * Payload.
     *
     * @param array $args Validated tool input.
     */
    private static function payload( array $args ): array {
        unset( $args['dry_run'], $args['confirm_token'], $args['idempotency_key'] );
        return $args;
    }

    /**
     * Retry.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function retry( $tool, array $args ): ?array {
        if ( ! empty( $args['dry_run'] ) || empty( $args['idempotency_key'] ) ) {
            return null;
        }
        $key = '_directorist_mcp_extension_key_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $args['idempotency_key'] ] );
        $id  = get_option( $key );
        if ( ! $id ) {
            return null;
        }
        $record = get_option( self::key( $id ) );
        Validation::check( $record && hash_equals( $record['digest'], Canonical_Data::hash( self::payload( $args ) ) ), __( 'The operation key belongs to a different action.', 'directorist' ), 'idempotency_conflict' );
        Access_Control::permission( $tool, $args );
        return self::projection( $record ) + [ 'idempotent_replay' => true ];
    }

    /**
     * Execute.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param array $d Registered descriptor.
     * @param callable $writer Writer.
     */
    public static function execute( $tool, array $args, array $d, callable $writer ): array {
        $old = self::retry( $tool, $args );
        if ( null !== $old ) {
            return $old;
        }
        $id     = wp_generate_uuid4();
        $record = [
            'operation_id'       => $id,
            'tool'               => $tool,
            'owner'              => $d['owner'],
            'actor'              => get_current_user_id(),
            'contract'           => $d['contract_version'],
            'registry'           => Tool_Registry::instance()->fingerprint(),
            'args'               => self::payload( $args ),
            'digest'             => Canonical_Data::hash( self::payload( $args ) ),
            'state'              => 'reserved',
            'external_effect'    => 'none',
            'committed'          => false,
            'provider_reference' => $id,
            'request_id'         => Request_Context::request_id(),
            'created_at'         => gmdate( 'c' ),
        ];
        Validation::check( add_option( self::key( $id ), $record, '', false ), __( 'Could not reserve the external operation.', 'directorist' ), 'operation_in_progress' );
        $key = '_directorist_mcp_extension_key_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $args['idempotency_key'] ] );
        Validation::check( add_option( $key, $id, '', false ), __( 'This external action is already reserved.', 'directorist' ), 'operation_in_progress' );
        try {
            $record['state']           = 'dispatching';
            $record['external_effect'] = 'unknown';
            self::save( $record );
            $outcome = Read_Guard::run(
                static function () use ( $d, $id, $record ) { return Callback_Invoker::extension_callback( $d['dispatch'], $id, $record['args'] );
                }, true
            );
            $record  = self::observe( $record, $outcome );
            self::save( $record );
            if ( 'settled' === $record['state'] ) {
                $record['state'] = 'recording';
                self::save( $record );
                $record['result']    = Transaction::atomic( $writer, $d['tables'], $d['invalidate'] );
                $record['committed'] = true;
                $record['state']     = 'settled';
                self::save( $record );
            }
        } catch ( \Throwable $error ) {
            $record['state']     = 'unknown';
            $record['committed'] = 'unknown';
            self::save( $record );
        }
        return self::projection( $record );
    }

    /**
     * Observe.
     *
     * @param array $record Record.
     * @param mixed $result Result.
     */
    private static function observe( array $record, $result ): array {
        Validation::check( is_array( $result ) && in_array( $result['state'] ?? '', [ 'pending', 'settled', 'failed', 'unknown' ], true ), __( 'Invalid external outcome.', 'directorist' ), 'outcome_unknown' );
        $record['state']           = $result['state'];
        $record['external_effect'] = $result['state'];
        if ( isset( $result['reference'] ) ) {
            Validation::check( is_string( $result['reference'] ) && strlen( $result['reference'] ) <= 256, __( 'Invalid external reference.', 'directorist' ), 'outcome_unknown' );
            $record['provider_reference'] = $result['reference'];
        }
        return $record;
    }

    /**
     * Read any.
     *
     * @param array $args Validated tool input.
     */
    public static function read_any( array $args ): array {
        $record = get_option( self::key( $args['operation_id'] ) );
        Validation::check( $record && (int) $record['actor'] === get_current_user_id(), __( 'Operation not found.', 'directorist' ), 'not_found' );
        return self::read( $record['tool'], $args );
    }

    /**
     * Read.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function read( $tool, array $args ): array {
        $record = get_option( self::key( $args['operation_id'] ) );
        Validation::check( $record && $record['tool'] === $tool && ( (int) $record['actor'] === get_current_user_id() || current_user_can( 'manage_options' ) ), __( 'Operation not found.', 'directorist' ), 'not_found' );
        $definitions = Core_Tool_Provider::definitions();
        $d           = $definitions[ $tool ] ?? null;
        if ( ! $d || ! Tool_Registry::instance()->admitted( $record['owner'] ) ) {
            // Preserve a safe initiating-user tombstone without exposing the old input/result snapshot.
            Validation::check( (int) $record['actor'] === get_current_user_id(), __( 'Operation not found.', 'directorist' ), 'not_found' );
            return [ 'success' => true, 'operation_id' => $record['operation_id'], 'state' => 'provider_unavailable', 'completed' => false, 'committed' => $record['committed'], 'external_effect' => $record['external_effect'], 'request_id' => $record['request_id'], 'next_step' => 'Restore a compatible integration to reconcile. Do not repeat the external action.' ];
        }
        try {
            Access_Control::permission( $tool, $record['args'] );
        } catch ( Failure $error ) {
            Validation::fail( 'not_found', __( 'Operation not found.', 'directorist' ) );
        }
        if ( $d['contract_version'] === $record['contract'] && in_array( $record['state'], [ 'pending', 'dispatching', 'unknown' ], true ) ) {
            try {
                $record = self::observe( $record, Callback_Invoker::extension_callback( $d['reconcile'], $record['operation_id'], $record['provider_reference'], $record['args'] ) );
            } catch ( Failure $error ) {
                // Preserve the original uncertain outcome; never dispatch or rerun the writer from a read.
            }
        }
        return self::projection( $record );
    }

    /**
     * Complete.
     *
     * @param mixed $operation_id Operation id.
     * @param array $native_result Native result.
     */
    public static function complete( $operation_id, array $native_result ): array {
        Access_Control::admin();
        $owns_lock = ! Write_Lock::is_locked();
        Write_Lock::lock();
        try {
            $record = get_option( self::key( $operation_id ) );
            Validation::check( $record, __( 'Operation not found.', 'directorist' ), 'not_found' );
            if ( true === $record['committed'] ) {
                Validation::check( Canonical_Data::hash( $record['result'] ) === Canonical_Data::hash( $native_result ), __( 'The operation already has a different native result.', 'directorist' ), 'idempotency_conflict' ); return self::projection( $record );
            }
            $d = Core_Tool_Provider::definitions()[ $record['tool'] ] ?? null;
            Validation::check( $d && Tool_Registry::instance()->active( $record['owner'] ) && $d['contract_version'] === $record['contract'] && is_callable( $d['verify_record'] ?? null ), __( 'A compatible native result verifier is required.', 'directorist' ), 'extension_unavailable' );
            Access_Control::permission( $record['tool'], $record['args'] );
            $outcome = Read_Guard::run(
                static function () use ( $d, $record ) { return Callback_Invoker::extension_callback( $d['reconcile'], $record['operation_id'], $record['provider_reference'], $record['args'] );
                }
            );
            Validation::check( 'settled' === ( $outcome['state'] ?? '' ), __( 'External settlement is not verified.', 'directorist' ), 'dependency_blocked' );
            $valid = Read_Guard::run(
                static function () use ( $d, $record, $native_result ) { return Callback_Invoker::extension_callback( $d['verify_record'], $record['operation_id'], $record['args'], $native_result );
                }
            );
            Validation::check( true === $valid, __( 'The native result cannot be verified.', 'directorist' ), 'dependency_blocked' );
            Validation::unwrap( rest_validate_value_from_schema( $native_result, $d['output_schema'] ) );
            $record['result']          = $native_result;
            $record['state']           = 'settled';
            $record['external_effect'] = 'settled';
            $record['committed']       = true;
            self::save( $record );
            return self::projection( $record );
        } finally {
            if ( $owns_lock ) {
                Write_Lock::unlock();
            }
        }
    }

    /**
     * Projection.
     *
     * @param array $record Record.
     */
    private static function projection( array $record ): array {
        $complete = 'settled' === $record['state'] && true === $record['committed'];
        $result   = [
            'success'         => ! in_array( $record['state'], [ 'unknown', 'failed' ], true ),
            'completed'       => $complete || 'failed' === $record['state'],
            'operation_id'    => $record['operation_id'],
            'state'           => $record['state'],
            'committed'       => $record['committed'],
            'external_effect' => $record['external_effect'],
            'request_id'      => $record['request_id'],
            'next_step'       => $complete ? 'Read the current native object.' : 'Reconcile the original operation. Do not repeat the dispatch or native writer.',
        ];
        if ( isset( $record['result'] ) ) {
            $d = Core_Tool_Provider::definitions()[ $record['tool'] ] ?? null;
            if ( $d && $d['contract_version'] === $record['contract'] ) {
                $result['result'] = Read_Guard::run(
                    static function () use ( $d, $record ) { return Callback_Invoker::extension_callback( $d['project'], $record['result'], $record['args'], get_current_user_id() );
                    }
                );
            }
        }
        return $result;
    }

    /**
     * Output schema.
     *
     * @param array $d Registered descriptor.
     */
    public static function output_schema( array $d ): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => [ 'operation_id', 'state', 'committed', 'completed', 'external_effect' ],
            'properties'           => [
                'success'           => [ 'type' => 'boolean' ],
                'completed'         => [ 'type' => 'boolean' ],
                'operation_id'      => [ 'type' => 'string', 'maxLength' => 36 ],
                'state'             => [ 'type' => 'string', 'maxLength' => 32 ],
                'committed'         => [ 'type' => [ 'boolean', 'string' ] ],
                'external_effect'   => [ 'type' => 'string', 'maxLength' => 32 ],
                'request_id'        => [ 'type' => 'string', 'maxLength' => 36 ],
                'next_step'         => [ 'type' => 'string', 'maxLength' => 256 ],
                'idempotent_replay' => [ 'type' => 'boolean' ],
                'result'            => $d['output_schema'],
            ],
        ];
    }
}
