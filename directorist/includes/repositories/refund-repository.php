<?php

namespace Directorist\Repositories;

defined( "ABSPATH" ) || exit;

use Directorist\Utils\Repositories\Repository;
use Directorist\Utils\Database\Query\Builder;
use Directorist\Utils\Exception;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Enums\Refund\Status;
use Directorist\DTO\Refund\Read;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\DTO\Refund\DTO;
use Directorist\DBModels\Refund;
use Directorist\Repositories\OrderRepository;

class RefundRepository extends Repository {
    private OrderRepository $order_repository;

    public function __construct() {
        $this->order_repository = new OrderRepository();
    }

    public function get_query_builder(): Builder {
        return Refund::query();
    }

    public function get( Read $dto ): array {
        $query = $this->get_query_builder()->select( '*', 'UNIX_TIMESTAMP(created_at) as created_at_timestamp' )->where( 'order_id', $dto->get_order_id() );

        if ( $dto->get_search() ) {
            $query->where( 'reason', 'like', '%' . $dto->get_search() . '%' );
        }

        $count = clone $query;

        return [
            'total'          => $count->count(),
            'total_refunded' => $this->get_order_total_refunded( $dto->get_order_id() ),
            'items'          => $query->order_by_desc( 'id' )->pagination( $dto->get_page(), $dto->get_per_page() ),
        ];
    }

    /**
     * Create a new refund for an order
     *
     * This method handles the complete refund creation workflow including:
     * - Order validation and retrieval
     * - Refund amount validation against remaining order amount
     * - Refund creation in database
     * - Order status update if fully refunded
     * - Action hooks for extensibility
     *
     * @param DTO $dto Data Transfer Object containing refund information
     * @return int The ID of the created refund
     * @throws Exception When order not found, already refunded, or refund amount exceeds remaining amount
     */
    public function create( $dto ) {
        return \Directorist\Order\Mutation::run(
            function () use ( $dto ) {
                do_action( 'directorist_mcp_before_native_refund_write' );
                // Retrieve and validate the order exists
                $order = $this->order_repository->to_dto( \Directorist\Order\Mutation::locked_row( $this->order_repository->get_query_builder()->where( 'id', $dto->get_order_id() ) ) );

                if ( ! $order ) {
                      throw new Exception( esc_html__( "Order not found", 'directorist' ) );
                }

                $total = directorist_compute_order_total_amount(
                    $order->get_sub_total(),
                    $order->get_tax_rate(),
                    $order->get_tax_type(),
                    $order->get_coupon_discount(),
                    $order->get_coupon_discount_type()
                );

                // Calculate total amount already refunded for this order
                $already_refunded = $this->locked_total_refunded( $dto->get_order_id() );

                // Prevent refunding if order is already fully refunded
                if ( $already_refunded >= $total ) {
                    throw new Exception( esc_html__( "Order is already refunded", 'directorist' ) );
                }

                // Calculate remaining amount that can still be refunded
                $remaining_amount = round( $total - $already_refunded, 2 );

                // Validate that refund amount doesn't exceed remaining refundable amount
                if ( $remaining_amount < $dto->get_amount() ) {
                      // translators: %s is the remaining amount
                      throw new Exception( sprintf( esc_html__( "You cannot refund more than the remaining amount: %s", "directorist" ), $remaining_amount ) );
                }

                // Allow external code to hook into the refund creation process
                do_action( 'directorist_before_refund_created', $dto, $order, $remaining_amount, $already_refunded );

                // Insert the refund record and get the new refund ID
                $refund_id = \Directorist\Order\Mutation::check_result( parent::create( $dto ) );
                \Directorist\Order\Activity::refund_changed( null, \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $refund_id ) ), $order->get_currency() );

                // After refund creation, recalculate remaining refundable amount
                $remaining_amount = round( $already_refunded + $dto->get_amount(), 2 );

                // If order is now fully refunded, update order status to REFUNDED
                if ( Status::PAID === $dto->get_status() && $remaining_amount === $total ) {
                    $status_dto = ( new OrderDTO )->set_id( $order->get_id() )->set_status( OrderStatus::REFUNDED );
                    $this->order_repository->update( $status_dto );
                }

                // Allow external code to hook into post-refund creation process
                do_action( 'directorist_after_refund_created', $refund_id, $dto, $order, $remaining_amount, $already_refunded );

                return $refund_id;
            }
        );
    }

    public function update( \Directorist\Utils\DTO $dto ) {
        do_action( 'directorist_mcp_before_native_refund_write' );
        return \Directorist\Order\Mutation::run(
            function () use ( $dto ) {
                $before = \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) );
                if ( ! $before ) {
                      throw new Exception( __( 'Refund not found.', 'directorist' ), 404 );
                }
                if ( $dto->is_initialized( 'order_id' ) && $dto->get_order_id() !== (int) $before->order_id ) {
                    throw new Exception( __( 'The refund does not belong to this order.', 'directorist' ), 400 );
                }
                $order = \Directorist\Order\Mutation::locked_row( $this->order_repository->get_query_builder()->where( 'id', (int) $before->order_id ) );
                if ( ! $order ) {
                    throw new Exception( __( 'Order not found.', 'directorist' ), 404 );
                }
                $amount = $dto->is_initialized( 'amount' ) ? $dto->get_amount() : (float) $before->amount;
                $status = $dto->is_initialized( 'status' ) ? $dto->get_status() : $before->status;
                $paid   = $this->locked_total_refunded( (int) $order->id ) - ( Status::PAID === $before->status ? (float) $before->amount : 0 );
                $paid   = round( $paid + ( Status::PAID === $status ? $amount : 0 ), 2 );
                $total  = directorist_order_total_amount( $order );
                if ( ! is_finite( $amount ) || $amount <= 0 || $amount > $total || $paid > $total ) {
                    throw new Exception( __( 'The refund exceeds the available order balance.', 'directorist' ), 400 );
                }
                $result = \Directorist\Order\Mutation::check_result( parent::update( $dto ) );
                \Directorist\Order\Activity::refund_changed( $before, \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) ), $order->currency );
                if ( Status::PAID === $status && $paid === $total && OrderStatus::REFUNDED !== $order->status ) {
                    $this->order_repository->update_status( (int) $order->id, OrderStatus::REFUNDED );
                }
                return $result;
            }
        );
    }

    public function delete_by( string $column, $value ) {
        do_action( 'directorist_mcp_before_native_refund_write' );
        return \Directorist\Order\Mutation::run(
            function () use ( $column, $value ) {
                $rows   = \Directorist\Order\Mutation::locked_rows( $this->get_query_builder()->where( $column, $value ) );
                $result = \Directorist\Order\Mutation::check_result( parent::delete_by( $column, $value ) );
                foreach ( $rows as $refund ) {
                      $order = \Directorist\Order\Mutation::locked_row( $this->order_repository->get_query_builder()->where( 'id', (int) $refund->order_id ) );
                    if ( $order ) {
                        \Directorist\Order\Activity::refund_changed( $refund, null, $order->currency, true );
                    }
                }
                return $result;
            }
        );
    }

    private function locked_total_refunded( int $order_id ): float {
        $rows = \Directorist\Order\Mutation::locked_rows( $this->get_query_builder()->where( 'order_id', $order_id )->where( 'status', Status::PAID ) );
        return round( array_sum( array_column( $rows, 'amount' ) ), 2 );
    }

    public function get_order_total_refunded( int $order_id ) {
        global $wpdb;

        // The query builder's aggregate() casts sums to integers, losing cents.
        return (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE( SUM( amount ), 0 ) FROM {$wpdb->prefix}directorist_refunds WHERE order_id = %d AND status = %s",
                $order_id,
                Status::PAID
            )
        );
    }
}
