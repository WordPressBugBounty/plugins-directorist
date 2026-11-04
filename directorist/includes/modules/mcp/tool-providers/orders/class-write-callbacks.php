<?php
/**
 * Write Callbacks for orders.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Orders;

use Directorist\DTO\Payment\DTO as PaymentDTO;
use Directorist\DTO\Refund\DTO as RefundDTO;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Write Callbacks. */
class Write_Callbacks {
    /**
     * Prepare.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function prepare( string $tool, array $args ): array {
        Order_Policy::permission( $tool, $args );
        if ( 'create-order' === $tool ) {
            $quote  = Order_Quote::quote( $args['customer_id'], $args['item_type'], Validation::object( $args['item'] ) );
            $schema = Read_Callbacks::read( 'get-order-schema', [] )['schema_version'];
            if ( isset( $args['schema_version'] ) ) {
                Validation::version( $args['schema_version'], $schema );
            }
            return [ 'confirm' => true,
                'tables'       => Order_Policy::tables(),
                'state'        => [ $quote['state'], $schema ],
                'preview'      => $quote['preview'],
                'execute'      => static function () use ( $quote ) {
                    $id = directorist_order_repository()->create( $quote['dto'] );
                    Validation::check( $id > 0, __( 'The order could not be created.', 'directorist' ), 'operation_failed' );
                    return Result::result( [ 'order_id' => (int) $id, 'data' => Order_Policy::data( Order_Policy::get( (int) $id ) ) ] );
                }
            ];
        }
        $order   = Order_Policy::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        $version = Order_Policy::version( $order );
        if ( empty( $args['dry_run'] ) ) {
            Validation::version( $args['expected_version'] ?? null, $version );
        }
        $preview = [ 'order_id' => (int) $order->id, 'version' => $version, 'before' => Order_Policy::data( $order ) ];
        $actions = Order_Policy::actions( $order );
        if ( 'update-order' === $tool ) {
            Validation::check( Order_Policy::editable( $order ), __( 'Only eligible unpaid orders can be edited.', 'directorist' ), 'dependency_blocked' );
            $changes = Validation::object( $args['changes'] );
            Validation::allowed( $changes, [ 'recalculate', 'item' ] );
            Validation::check( ! isset( $changes['recalculate'] ) || true === $changes['recalculate'], __( 'Recalculate must be true.', 'directorist' ) );
            $item = Validation::object( $changes['item'] ?? [ 'listing_id' => (int) $order->listing_id ] );
            Validation::check( ! isset( $item['listing_id'] ) || $item['listing_id'] === (int) $order->listing_id, __( 'Order listing transfer is not supported.', 'directorist' ) );
            $quote = Order_Quote::quote( (int) $order->user_id, $order->ref_type, $item, $order );
            $dto   = $quote['dto'];
            $dto->set_id( (int) $order->id )->set_status( $order->status );
            return [ 'confirm' => true,
                'tables'       => Order_Policy::tables(),
                'state'        => [ $version, $quote['state'] ],
                'preview'      => $preview + [ 'after' => $quote['preview'] ],
                'execute'      => static function () use ( $dto, $order ) {
                    directorist_order_repository()->update( $dto );
                    return Result::result( [ 'order_id' => (int) $order->id, 'data' => Order_Policy::data( Order_Policy::get( (int) $order->id ) ) ] );
                }
            ];
        }
        if ( in_array( $tool, [ 'cancel-order', 'change-order-status' ], true ) ) {
            $target = 'cancel-order' === $tool ? 'cancelled' : $args['status'];
            $action = 'cancelled' === $target ? 'cancel' : $target;
            Validation::check( in_array( $action, $actions, true ) && in_array( $target, [ 'cancelled', 'pending', 'unpaid', 'failed', 'expired' ], true ), __( 'This native transition is not eligible.', 'directorist' ), 'dependency_blocked' );
            return [ 'confirm' => true,
                'orchestrated' => true,
                'tables'       => Order_Policy::tables(),
                'state'        => $version,
                'preview'      => $preview + [ 'after_status' => $target, 'effects' => 'Native state/fulfillment hooks; no charge or refund.' ],
                'execute'      => static function () use ( $order, $target, $tool, $args ) {
                    return Financial_Operations::execute(
                        $tool, $args, $order, static function () use ( $order, $target ) {
                            directorist_order_repository()->update_status( (int) $order->id, $target );
                            return Result::result( [ 'order_id' => (int) $order->id, 'status' => $target ] );
                        }
                    );
                }
            ];
        }
        return self::financial( $tool, $args, $order, $version, $preview );
    }

    /**
     * Financial.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param \stdClass $order Order.
     * @param string $version Version.
     * @param array $preview Preview.
     */
    public static function financial( string $tool, array $args, \stdClass $order, string $version, array $preview ): array {
        Validation::check( $args['currency'] === $order->currency, __( 'The currency must match the native order.', 'directorist' ) );
        $minor = Money::minor( $args['amount'], $order->currency );
        Validation::check( $minor > 0, __( 'The amount must be positive.', 'directorist' ) );
        $amount   = Money::native( $args['amount'], $order->currency );
        $provider = [];
        Financial_Operations::check_evidence( $tool, $args );
        if ( 'record-order-payment' === $tool ) {
            Validation::check( in_array( 'record_offline_payment', Order_Policy::actions( $order ), true ) && Order_Policy::offline_ready() && 'bank_transfer' === $args['method'], __( 'Only an eligible native offline receipt can be recorded.', 'directorist' ), 'unsupported_operation' );
            Validation::check( $minor === Money::minor( Order_Policy::total( $order ), $order->currency ), __( 'Record the exact native due amount.', 'directorist' ) );
            Validation::check( '' !== trim( $args['evidence_reference'] ), __( 'Receipt evidence is required.', 'directorist' ) );
            $native = static function () use ( $order, $amount, $args ) {
                $dto = ( new PaymentDTO() )->set_order_id( (int) $order->id )->set_amount( $amount )->set_currency( $order->currency )->set_status( 'paid' )->set_method( 'bank_transfer' )->set_transaction_id( 'mcp-' . substr( Canonical_Data::hash( $args['evidence_reference'] ), 0, 32 ) );
                $id  = directorist_payment_repository()->create( $dto );
                Validation::check( $id > 0, __( 'Payment recording failed.', 'directorist' ), 'operation_failed' );
                return Result::result( [ 'payment_id' => $id, 'mode' => 'offline_recording' ] );
            };
        } else {
            Validation::check( 'create-order-refund' === $tool && in_array( 'refund', Order_Policy::actions( $order ), true ), __( 'This order is not eligible for a refund.', 'directorist' ), 'dependency_blocked' );
            $payment = directorist_payment_repository()->get_by_id( $args['payment_id'] );
            Validation::check( $payment && (int) $payment->order_id === (int) $order->id && 'paid' === $payment->status && $payment->currency === $order->currency, __( 'The refundable payment is invalid.', 'directorist' ) );
            $paid_receipts = array_filter(
                Order_Policy::rows( 1, $order->id ), static function ( $receipt ) { return 'paid' === $receipt->status;
                }
            );
            Validation::check( 1 === count( $paid_receipts ), __( 'Orders with multiple settled receipts require a native allocation adapter.', 'directorist' ), 'unsupported_operation' );
            Validation::check( $minor <= Money::minor( Order_Policy::balance( $order )['refundable'], $order->currency ) && $minor <= Money::minor( Money::stored( $payment->amount, $order->currency ), $order->currency ), __( 'The refund exceeds the available balance.', 'directorist' ), 'dependency_blocked' );
            Validation::check( '' !== trim( $args['reason'] ), __( 'A refund reason is required.', 'directorist' ) );
            if ( 'gateway' === $args['mode'] ) {
                $providers = Tool_Registry::instance()->fields( 'refund_providers', $order );
                Validation::check( isset( $providers[ $args['provider'] ?? '' ] ), __( 'A compatible registered refund provider is required.', 'directorist' ), 'unsupported_operation' );
                $provider = $providers[ $args['provider'] ];
                Validation::check( true === call_user_func( $provider['permission'], $order, 'write' ), __( 'This provider action is forbidden.', 'directorist' ), 'forbidden' );
                Validation::unwrap( rest_validate_value_from_schema( ( $args['provider_input'] ?? [] ) ?: (object) [], $provider['schema'] ) );
                Validation::unwrap( call_user_func( $provider['validate'], $args['provider_input'] ?? [], $order, $payment ) );
            } else {
                Validation::check( ! empty( $args['evidence_reference'] ) && empty( $args['provider'] ) && empty( $args['provider_input'] ), __( 'A manual refund requires evidence and cannot dispatch a provider.', 'directorist' ) );
            }
            $native = static function () use ( $order, $amount, $args ) {
                $dto = ( new RefundDTO() )->set_order_id( (int) $order->id )->set_amount( $amount )->set_status( 'paid' )->set_reason( sanitize_text_field( $args['reason'] ) );
                $id  = ( new \Directorist\Repositories\RefundRepository() )->create( $dto );
                Validation::check( $id > 0, __( 'Refund recording failed.', 'directorist' ), 'operation_failed' );
                $balance = Order_Policy::balance( Order_Policy::get( (int) $order->id ) );
                if ( Money::minor( $balance['settled'], $order->currency ) === Money::minor( Order_Policy::total( $order ), $order->currency ) && 'refunded' !== Order_Policy::get( (int) $order->id )->status ) {
                    directorist_order_repository()->update_status( (int) $order->id, 'refunded' );
                }
                return Result::result( [ 'refund_id' => $id, 'mode' => $args['mode'], 'gateway_refund_performed' => 'gateway' === $args['mode'] ] );
            };
        }
        return [ 'confirm' => true,
            'orchestrated' => true,
            'tables'       => Order_Policy::tables(),
            'state'        => [ $version, $provider['contract_version'] ?? '' ],
            'preview'      => $preview + [ 'amount' => $args['amount'], 'currency' => $order->currency, 'mode' => $args['mode'] ?? 'offline_recording', 'balance' => Order_Policy::balance( $order ), 'effects' => 'Native ledger, order, entitlement and notification hooks. Ledger-only recording does not return money.' ],
            'execute'      => static function () use ( $tool, $args, $order, $native, $provider ) {
                return Financial_Operations::execute( $tool, $args, $order, $native, $provider );
            }
        ];
    }

    /**
     * Handle create-order through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function create_order( array $args = [] ): array {
        return self::prepare( 'create-order', $args );
    }

    /**
     * Handle update-order through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function update_order( array $args = [] ): array {
        return self::prepare( 'update-order', $args );
    }

    /**
     * Handle change-order-status through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function change_order_status( array $args = [] ): array {
        return self::prepare( 'change-order-status', $args );
    }

    /**
     * Handle cancel-order through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function cancel_order( array $args = [] ): array {
        return self::prepare( 'cancel-order', $args );
    }

    /**
     * Handle record-order-payment through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function record_order_payment( array $args = [] ): array {
        return self::prepare( 'record-order-payment', $args );
    }

    /**
     * Handle create-order-refund through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function create_order_refund( array $args = [] ): array {
        return self::prepare( 'create-order-refund', $args );
    }
}
