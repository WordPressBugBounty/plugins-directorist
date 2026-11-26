<?php

namespace Directorist\Repositories;

defined( "ABSPATH" ) || exit;

use Directorist\Helpers\DateTime;
use Directorist\Utils\Repositories\Repository;
use Directorist\Utils\Database\Query\Builder;
use Directorist\DTO\Payment\DTO;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Enums\Payment\Status as PaymentStatus;
use Directorist\DBModels\Payment;

class PaymentRepository extends Repository {
    public OrderRepository $order_repository;

    public function __construct() {
        $this->order_repository = new OrderRepository();
    }

    public function get_query_builder(): Builder {
        return Payment::query( 'payment' );
    }

    public function get( $order_id ) {
        $query = $this->get_query_builder()->select( 'payment.*', 'UNIX_TIMESTAMP(payment.created_at) as created_at_timestamp' );
        return $query->where( 'order_id', $order_id )->order_by_desc( 'id' )->get();
    }

    /**
     * Summary of create
     * @param DTO $dto
     * @return int
     */
    public function create( \Directorist\Utils\DTO $dto ) {
        return \Directorist\Order\Mutation::run(
            function () use ( $dto ) {
                $payment_id = $this->create_without_order_update( $dto );
                $payment    = $payment_id ? \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $payment_id ) ) : null;

                if ( ! $payment || (int) $payment->id !== $payment_id || $payment->status !== $dto->get_status() ) {
                      return 0;
                }

                if ( $dto->is_initialized( 'status' ) && $dto->get_status() === PaymentStatus::PAID ) {
                    if ( 'bank_transfer' === $payment->method ) {
                        \Directorist\Order\ManualPaymentService::complete_order( $payment );
                    } else {
                        $this->order_repository->update_status( $dto->get_order_id(), OrderStatus::PAID );
                    }
                }

                return $payment_id;
            }
        );
    }

    /**
     * Create a payment without synchronizing the related order status.
     *
     * @param \Directorist\Utils\DTO $dto Payment data.
     * @return int Payment ID, or zero when the insert fails.
     */
    public function create_without_order_update( \Directorist\Utils\DTO $dto ) {
        do_action( 'directorist_mcp_before_native_payment_write' );
        global $wpdb;

        return \Directorist\Order\Mutation::run(
            function () use ( $dto ) {
                $payment_id = \Directorist\Order\Mutation::check_result( parent::create( $dto ) );
                if ( $payment_id ) {
                      \Directorist\Order\Activity::payment_changed( null, \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $payment_id ) ) );
                }
                return (int) $payment_id;
            }
        );
    }

    public function update( \Directorist\Utils\DTO $dto ) {
        do_action( 'directorist_mcp_before_native_payment_write' );
        return \Directorist\Order\Mutation::run(
            function () use ( $dto ) {
                $before = \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) );
                if ( ! $before ) {
                      return 0;
                }
                \Directorist\Order\ActivityMigration::baseline( $before );
                $result = \Directorist\Order\Mutation::check_result( parent::update( $dto ) );
                $after  = \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) );
                \Directorist\Order\Activity::payment_changed( $before, $after );
                return $result;
            }
        );
    }

    public function delete_by( string $column, $value ) {
        do_action( 'directorist_mcp_before_native_payment_write' );
        return \Directorist\Order\Mutation::run(
            function () use ( $column, $value ) {
                $rows = \Directorist\Order\Mutation::locked_rows( $this->get_query_builder()->where( $column, $value ) );
                foreach ( $rows as $payment ) {
                      \Directorist\Order\ActivityMigration::baseline( $payment );
                }
                $result = \Directorist\Order\Mutation::check_result( parent::delete_by( $column, $value ) );
                foreach ( $rows as $payment ) {
                    \Directorist\Order\Activity::payment_deleted( $payment );
                }
                return $result;
            }
        );
    }

    public function update_status_by_order_id( int $order_id, string $status ): int {
        do_action( 'directorist_mcp_before_native_payment_write' );
        // Kept for integrations explicitly requesting a bulk payment update.
        // Updating an order no longer calls this method.
        return \Directorist\Order\Mutation::run(
            function () use ( $order_id, $status ) {
                $affected = 0;
                foreach ( \Directorist\Order\Mutation::locked_rows( $this->get_query_builder()->where( 'order_id', $order_id ) ) as $payment ) {
                    if ( $payment->status !== $status ) {
                        $affected += $this->update( ( new DTO() )->set_id( (int) $payment->id )->set_status( $status ) );
                    }
                }
                return $affected;
            }
        );
    }

    public function get_last_payment( $order_id ) {
        $query = $this->get_query_builder();
        return $query->where( 'order_id', $order_id )->order_by_desc( 'id' )->first();
    }

    public function to_dto( $payment ) {
        $dto = ( new DTO )->set_id( $payment->id )
            ->set_order_id( $payment->order_id )
            ->set_amount( $payment->amount )
            ->set_currency( $payment->currency )
            ->set_status( $payment->status )
            ->set_method( $payment->method )
            ->set_transaction_id( $payment->transaction_id )
            ->set_created_at( new DateTime( $payment->created_at ) );

        if ( ! empty( $payment->updated_at ) ) {
            $dto->set_updated_at( new DateTime( $payment->updated_at ) );
        }

        return $dto;
    }
}
