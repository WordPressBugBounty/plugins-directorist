<?php
/**
 * Tool Provider for orders.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Orders;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Safety\Bulk_Executor;


defined( 'ABSPATH' ) || exit;

/** Tool Provider. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Definitions. */
    public static function definitions(): array {
        $s       = [ 'type' => 'string', 'maxLength' => 256 ];
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $o       = [ 'type' => 'object', 'additionalProperties' => true ];
        $paging  = [ 'page' => $id, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $s ];
        $order   = [ 'order_id' => $id, 'legacy_id' => $id ];
        $filters = [ 'scope' => [ 'type' => 'string', 'enum' => [ 'own', 'management' ] ], 'status' => $s, 'customer_id' => $id, 'listing_id' => $id, 'item_type' => $s, 'currency' => $s, 'created_after' => $s, 'created_before' => $s, 'order' => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ] ], 'ids' => [ 'type' => 'array', 'items' => $id, 'maxItems' => 100 ] ];
        $money   = [ 'amount' => [ 'type' => 'string', 'maxLength' => 32 ], 'currency' => [ 'type' => 'string', 'maxLength' => 4 ] ];
        $rows    = [
            [ 'get-order-schema', $order + [ 'item_type' => $s ], [], false, 'Discover caller-specific order fields, exact money rules, native actions and provider capabilities.' ],
            [ 'list-orders', $paging + $filters, [], false, 'Find authorized purchases. Customer scope is always restricted to the purchaser.' ],
            [ 'get-order', $order, [], false, 'Read a permitted native order, version and eligible actions.' ],
            [ 'create-order', [ 'customer_id' => $id, 'item_type' => $s, 'item' => $o, 'schema_version' => $s ], [ 'customer_id', 'item_type', 'item' ], true, 'Preview a native unpaid order from existing references. Never initiates a charge.' ],
            [ 'update-order', $order + [ 'changes' => $o ], [ 'changes' ], true, 'Preview explicit eligible unpaid-order changes with native recalculation.' ],
            [ 'change-order-status', $order + [ 'status' => $s, 'reason' => $s ], [ 'status' ], true, 'Preview an eligible nonfinancial lifecycle transition. Paid/refunded flags are forbidden.' ],
            [ 'cancel-order', $order + [ 'reason' => $s ], [], true, 'Preview native cancellation. Customers may cancel only their own pending purchase.' ],
            [ 'record-order-payment', $order + $money + [ 'method' => $s, 'evidence_reference' => $s ], [ 'amount', 'currency', 'method', 'evidence_reference' ], true, 'Record an eligible already received offline payment with duplicate evidence protection.' ],
            [ 'list-order-payments', $order + $paging + [ 'status' => $s, 'created_after' => $s, 'created_before' => $s ], [], false, 'Read bounded authorized payment history without gateway payloads or secrets.' ],
            [ 'list-order-refunds', $order + $paging + [ 'status' => $s ], [], false, 'Read bounded refund history and settled/reserved/refundable balances.' ],
            [ 'create-order-refund', $order + $money + [ 'payment_id' => $id, 'reason' => $s, 'mode' => [ 'type' => 'string', 'enum' => [ 'record_only', 'gateway' ] ], 'provider' => $s, 'provider_input' => $o, 'evidence_reference' => $s ], [ 'amount', 'currency', 'payment_id', 'reason', 'mode' ], true, 'Preview a partial/full refund with explicit ledger-only or registered gateway mode.' ],
            [ 'bulk-orders', [ 'action' => [ 'type' => 'string', 'enum' => [ 'update', 'cancel' ] ], 'items' => [ 'type' => 'array', 'items' => $o, 'minItems' => 1, 'maxItems' => 50 ] ], [ 'action', 'items' ], true, 'Preview up to 50 explicit unpaid edits or eligible cancellations with independent outcomes.' ],
            [ 'get-order-operation', [ 'operation_id' => $s ], [ 'operation_id' ], false, 'Read a durable authorized order operation without dispatching another financial action.' ],
        ];
        $out     = [];
        foreach ( $rows as $row ) {
            list( $name, $properties, $required, $write, $description ) = $row;
            $out[ $name ]                                               = [ 'group' => 'orders', 'write' => $write, 'description' => __( 'Directorist orders: ', 'directorist' ) . $description, 'schema' => [ 'type' => 'object', 'properties' => $properties + ( $write ? Tool_Registry::mutation_controls() : [] ), 'required' => $required, 'additionalProperties' => false ] ];
        }
        return $out;
    }

    /** Return internal callbacks; these are not part of the persisted fingerprint. */
    public static function callbacks(): array {
        return [
            'get-order-schema'     => [ Read_Callbacks::class, 'get_order_schema' ],
            'list-orders'          => [ Read_Callbacks::class, 'list_orders' ],
            'get-order'            => [ Read_Callbacks::class, 'get_order' ],
            'create-order'         => [ Write_Callbacks::class, 'create_order' ],
            'update-order'         => [ Write_Callbacks::class, 'update_order' ],
            'change-order-status'  => [ Write_Callbacks::class, 'change_order_status' ],
            'cancel-order'         => [ Write_Callbacks::class, 'cancel_order' ],
            'record-order-payment' => [ Write_Callbacks::class, 'record_order_payment' ],
            'list-order-payments'  => [ Read_Callbacks::class, 'list_order_payments' ],
            'list-order-refunds'   => [ Read_Callbacks::class, 'list_order_refunds' ],
            'create-order-refund'  => [ Write_Callbacks::class, 'create_order_refund' ],
            'bulk-orders'          => static function ( array $args ): array { return Bulk_Executor::bulk( 'bulk-orders', $args ); },
            'get-order-operation'  => [ Read_Callbacks::class, 'get_order_operation' ],
        ];
    }

    /**
     * Enforce this feature's existing gates in their original policy order.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param string $phase Authorization phase around restrictive extension policies.
     */
    public static function authorize( string $tool, array $args, string $phase ): void {
        if ( 'before' === $phase ) {
            Order_Policy::permission( $tool, $args ); }
    }

    /**
     * Publish only the tools visible to the current account.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool {
        return Order_Policy::ready() && ( current_user_can( 'manage_options' ) || in_array( $tool, [ 'get-order-schema', 'list-orders', 'get-order', 'cancel-order', 'list-order-payments', 'list-order-refunds', 'get-order-operation' ], true ) );
    }
}
