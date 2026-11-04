<?php
/**
 * Prepare explicit batches and checkpoint individual authorized item outcomes.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Runtime\Tool_Runner;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Operation_Events;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Prepare explicit batches and checkpoint individual authorized item outcomes. */
class Bulk_Executor {
    /**
     * Bulk.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param string|null $registered_child Registered child.
     */
    public static function bulk( string $tool, array $args, ?string $registered_child = null ): array {
        $listing = 'bulk-listings' === $tool;
        $map     = $listing ? [ 'create' => 'create-listing', 'update' => 'update-listing', 'assign_terms' => 'update-listing', 'change_status' => 'change-listing-status', 'trash' => 'trash-listing', 'restore' => 'restore-listing' ] : [ 'create' => 'create-term', 'update' => 'update-term', 'delete' => 'delete-term' ];
        if ( 'bulk-orders' === $tool ) {
            $map = [ 'update' => 'update-order', 'cancel' => 'cancel-order' ];
        }
        $child = $registered_child ?: $map[ $args['action'] ];
        Validation::check( count( $args['items'] ) >= 1 && count( $args['items'] ) <= 50, __( 'A batch requires 1–50 explicit items.', 'directorist' ) );
        $refs        = [];
        $previews    = [];
        $states      = [];
        $definitions = Core_Tool_Provider::definitions();
        $items       = [];
        foreach ( $args['items'] as $input ) {
            $item = Validation::object( $input );
            $ref  = $item['client_ref'] ?? '';
            Validation::check( is_string( $ref ) && preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $ref ) && ! isset( $refs[ $ref ] ), __( 'Each item needs a unique client_ref of at most 64 safe characters.', 'directorist' ) );
            $refs[ $ref ] = true;
            unset( $item['client_ref'] );
            Validation::check( ! isset( $item['items'] ) && ! isset( $item['action'] ), __( 'Nested batch actions are not supported.', 'directorist' ) );
            if ( 'bulk-terms' === $tool ) {
                Validation::check( ! isset( $item['taxonomy'] ), __( 'Specify taxonomy once at batch level.', 'directorist' ) );
                $item['taxonomy'] = $args['taxonomy'];
            }
            $items[ $ref ] = $item;
            try {
                Validation::allowed( $item, array_diff( array_keys( $definitions[ $child ]['schema']['properties'] ), [ 'dry_run', 'confirm_token', 'idempotency_key' ] ) );
                if ( 'assign_terms' === ( $args['action'] ?? '' ) ) {
                    Validation::allowed( Validation::object( $item['changes'] ?? [] ), [ 'categories', 'locations', 'tags' ] );
                }
                Validation::unwrap( rest_validate_value_from_schema( $item ?: (object) [], $definitions[ $child ]['schema'] ) );
                Access_Control::permission( $child, $item );
                $plan           = Read_Guard::run(
                    static function () use ( $child, $item ) { return Tool_Runner::prepare( $child, $item + [ 'dry_run' => true ] );
                    }
                );
                $previews[]     = [ 'client_ref' => $ref, 'success' => true, 'preview' => $plan['preview'] ];
                $states[ $ref ] = Canonical_Data::hash( $plan['state'] );
            } catch ( Failure $error ) {
                $previews[]     = [ 'client_ref' => $ref, 'success' => false, 'error' => [ 'code' => $error->reason, 'message' => $error->getMessage() ] ];
                $states[ $ref ] = null;
            }
        }
        return [
            'bulk'    => true,
            'state'   => $states,
            'confirm' => true,
            'preview' => [ 'items' => $previews ],
            'execute' => static function ( $reviewed_states ) use ( $items, $child, $args ) {
                $results = [];
                $unknown = false;
                foreach ( $items as $ref => $item ) {
                    $item['idempotency_key'] = 'bulk-' . Canonical_Data::hash( [ $args['idempotency_key'] ?? '', $ref ] );
                    try {
                        Validation::check( ! $unknown, __( 'An earlier item has an uncertain outcome. Reconcile before continuing.', 'directorist' ), 'outcome_unknown' );
                        Validation::check( isset( $reviewed_states[ $ref ] ), __( 'This item was not eligible in the preview. Correct it and preview again.', 'directorist' ), 'validation_failed' );
                        Access_Control::permission( $child, $item );
                        $plan = Read_Guard::run(
                            static function () use ( $child, $item ) { return Tool_Runner::prepare( $child, $item );
                            }
                        );
                        Validation::check( hash_equals( $reviewed_states[ $ref ], Canonical_Data::hash( $plan['state'] ) ), __( 'This item changed since preview.', 'directorist' ), 'state_changed' );
                        // Persist a before-item marker so a timeout cannot replay a partially committed item.
                        Write_Guard::checkpoint( array_merge( $results, [ [ 'client_ref' => $ref, 'committed' => 'unknown' ] ] ) );
                        try {
                            $result = ! empty( $plan['orchestrated'] ) ? call_user_func( $plan['execute'] ) : Transaction::atomic( $plan['execute'], $plan['tables'] ?? [], $plan['invalidate'] ?? null );
                        } catch ( \Throwable $error ) {
                            $unknown = true;
                            throw new Failure( 'outcome_unknown', __( 'This item may have committed. Read it to reconcile.', 'directorist' ), [ 'committed' => 'unknown' ] );
                        }
                        $unknown   = $unknown || 'unknown' === ( $result['committed'] ?? false );
                        $results[] = [ 'client_ref' => $ref ] + $result;
                        Operation_Events::event( $child, 'completed', $item );
                    } catch ( Failure $error ) {
                        $results[] = [ 'client_ref' => $ref, 'success' => false, 'committed' => $error->details['committed'] ?? false, 'error' => [ 'code' => $error->reason, 'message' => $error->getMessage() ] ];
                        Operation_Events::event( $child, 'rejected', $item, $error->reason );
                    }
                    Write_Guard::checkpoint( $results );
                }
                $summary = self::summary( $results );
                return Result::result(
                    [
                        'success'   => 0 === $summary['failed'],
                        'committed' => $summary['succeeded'] > 0 ? true : ( $unknown ? 'unknown' : false ),
                        'results'   => $results,
                        'summary'   => $summary,
                    ]
                );
            }
        ];
    }

    /**
     * Summarize the item results visible in this response.
     *
     * @param array $results Batch results.
     */
    public static function summary( array $results ): array {
        $summary = [ 'succeeded' => 0, 'failed' => 0, 'indeterminate' => 0 ];
        foreach ( $results as $item ) {
            ++$summary[ ! empty( $item['success'] ) ? 'succeeded' : 'failed' ];
            if ( 'unknown' === ( $item['committed'] ?? false ) ) {
                ++$summary['indeterminate'];
            }
        }
        return $summary;
    }
}
