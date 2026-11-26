<?php

namespace Directorist\Repositories;


defined( "ABSPATH" ) || exit;

use Directorist\Utils\Exception;
use Directorist\Utils\Repositories\Repository;
use Directorist\Utils\Database\Query\Builder;
use Directorist\Helpers\DateTime;
use Directorist\DTO\Order\DTO;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Enums\Order\TaxType as OrderTaxType;
use Directorist\Enums\Order\DiscountType as OrderDiscountType;
use Directorist\DTO\Order\Read;
use Directorist\DBModels\Post;
use Directorist\DBModels\Order;

class OrderRepository extends Repository {
    public function get_query_builder(): Builder {
        return Order::query( 'd_order' ); // in alias using d_order instead of order. because order keyword is reserved by sql.
    }

    public function get( Read $dto ): array {
        $query = $this->get_query_builder()->select( 'd_order.*', 'UNIX_TIMESTAMP(d_order.created_at) as created_at_timestamp', 'users.user_email', 'users.display_name as user_display_name' );

        $query->left_join( 'users', 'd_order.user_id', '=', 'users.ID' );

        if ( ! empty( $dto->get_search() ) ) {
            $search_term = trim( $dto->get_search() );
            $query->where(
                function( $query ) use ( $search_term ) {
                    $query->where_like( 'd_order.id', $search_term )
                        ->or_where_like( 'd_order.status', $search_term )
                        ->or_where_like( 'users.user_email', $search_term )
                        ->or_where_like( 'users.display_name', $search_term );

                    // Check if search term contains 'featured listing' (case-insensitive)
                    if ( is_int( stripos( 'featured listing', $search_term ) ) ) {
                        $query->or_where( 'd_order.is_featured_listing', 1 );
                    }
                }
            );
        }

        $count_query = clone $query;

        return [
            "items" => $this->get_orders( $query, $dto ),
            "total" => $count_query->count( "d_order.id" )
        ];
    }

    public function get_by_user_id( int $user_id, Read $dto ) {
        $query = $this->get_query_builder()->select( 'd_order.*', 'UNIX_TIMESTAMP(d_order.created_at) as created_at_timestamp' )->where( 'd_order.user_id', $user_id );

        if ( ! empty( $dto->get_search() ) ) {
            $search_term = trim( $dto->get_search() );
            $query->where(
                function( $query ) use ( $search_term ) {
                    $query->where_like( 'd_order.id', $search_term )
                        ->or_where_like( 'd_order.status', $search_term );

                    // Check if search term contains 'featured listing' (case-insensitive)
                    if ( is_int( stripos( 'featured listing', $search_term ) ) ) {
                        $query->or_where( 'd_order.is_featured_listing', 1 );
                    }
                }
            );
        }

        $count_query = clone $query;

        return [
            "items" => $this->get_orders( $query, $dto ),
            "total" => $count_query->count( "d_order.id" )
        ];
    }

    public function listing_has_paid_featured_order( int $listing_id ): bool {
        $order = $this->get_query_builder()->select( 'd_order.id' )
            ->where( 'd_order.ref_type', 'featured_listing' )
            ->where( 'd_order.listing_id', $listing_id )
            ->where( 'd_order.status', OrderStatus::PAID )
            ->first();

        return $order ? true : false;
    }

    public function listing_has_active_featured_entitlement( int $listing_id ): bool {
        $orders = $this->get_query_builder()
            ->select( 'd_order.ref_type', 'd_order.created_at', 'd_order.expires_at' )
            ->where( 'd_order.listing_id', $listing_id )
            ->where( 'd_order.is_featured_listing', 1 )
            ->where( 'd_order.status', OrderStatus::PAID )
            ->get();

        $current_time  = directorist_now()->getTimestamp();
        $featured_days = (int) get_directorist_option( 'featured_listing_time', 30 );
        $is_active     = false;

        foreach ( $orders as $order ) {
            if ( ! empty( $order->expires_at ) ) {
                $is_active = ( new DateTime( $order->expires_at ) )->getTimestamp() > $current_time;
            } elseif ( 'featured_listing' === $order->ref_type ) {
                $is_active = ( new DateTime( $order->created_at ) )->add_days( $featured_days )->getTimestamp() > $current_time;
            } else {
                $is_active = true;
            }

            if ( $is_active ) {
                break;
            }
        }

        return (bool) apply_filters( 'directorist_listing_has_active_featured_entitlement', $is_active, $listing_id, $orders );
    }

    public function get_latest_paid_featured_order_by_listing_id( int $listing_id ) {
        return $this->get_query_builder()
            ->select( 'd_order.created_at', 'd_order.expires_at' )
            ->where( 'd_order.listing_id', $listing_id )
            ->where( 'd_order.is_featured_listing', 1 )
            ->where( 'd_order.ref_type', 'featured_listing' )
            ->where( 'd_order.status', OrderStatus::PAID )
            ->order_by_desc( 'd_order.id' )
            ->first();
    }

    protected function get_orders( Builder $query, Read $dto ) {
        $query = apply_filters( 'directorist_order_list_query', $query, $dto );

        $query->with(
            [
                'payment' => function( $query ) {
                    $query->select( 'id', 'order_id', 'method', 'transaction_id' )->order_by_desc( 'id' );
                }
            ]
        );

        $orders = array_map(
            function( $order ) {
                if ( ! empty( $order->payment ) ) {
                    $order->payment_method = $this->get_payment_method_title( $order->payment->method );
                    $order->transaction_id = $order->payment->transaction_id ?? null;
                }

                $order->total_amount = directorist_order_total_amount( $order );

                return apply_filters( 'directorist_order_data', $order );
            }, $query->order_by_desc( 'd_order.id' )->pagination( $dto->get_page(), $dto->get_per_page(), 1, 100 )
        );

        return $orders;
    }

    /**
     * Create a new order.
     *
     * @param \Directorist\DTO\Order\DTO $dto The DTO containing order data.
     * @return int The ID of the newly created order.
     * @throws Exception If the insert operation fails.
     */
    public function create( \Directorist\Utils\DTO $dto ) {
        do_action( 'directorist_before_order_create', $dto );

        $order_id = parent::create( $dto );

        $dto->set_id( $order_id );

        do_action( 'directorist_after_order_create', $dto );

        return $order_id;
    }

    public function create_many( array $dtos ) {
        return $this->get_query_builder()->insert(
            array_map(
                function( $dto ) {
                    return $this->process_values( $dto->to_array() );
                }, $dtos
            )
        );
    }

    /**
     * Update an existing order.
     *
     * @param \Directorist\DTO\Order\DTO $dto The DTO containing updated order data.
     * @return int The number of affected rows.
     * @throws Exception If the update operation fails.
     */
    public function update( \Directorist\Utils\DTO $dto ) {
        return \Directorist\Order\Mutation::run(
            function () use ( $dto ) {
                do_action( 'directorist_mcp_before_native_order_write' );
                $old_order = \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) );

                if ( ! $old_order ) {
                      throw new Exception( 'Order not found' );
                }

                do_action( 'directorist_before_order_update', $dto, $old_order );

                $update = parent::update( $dto );
                \Directorist\Order\Mutation::check_result( $update );
                $updated_order = \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) );

                if ( ! $updated_order ) {
                    throw new Exception( 'Failed to update order' );
                }

                $activity_id = \Directorist\Order\Activity::order_changed( $old_order, $updated_order );
                $updated_dto = $this->to_dto( $updated_order );
                do_action( 'directorist_after_order_update', $updated_dto, $old_order );

                if ( $activity_id && OrderStatus::PAID === $updated_order->status ) {
                    \Directorist\Order\Mutation::after_commit(
                        $activity_id,
                        static function () use ( $updated_dto, $old_order ) {
                            do_action( 'directorist_order_paid_after_commit', $updated_dto, $old_order );
                        }
                    );
                }

                return $update;
            }
        );
    }

    /**
     * Update an existing order silently.
     *
     * @param \Directorist\DTO\Order\DTO $dto The DTO containing updated order data.
     * @return int The number of affected rows.
     * @throws Exception If the update operation fails.
     */
    public function silent_update( \Directorist\Utils\DTO $dto ) {
        do_action( 'directorist_mcp_before_native_order_write' );
        return \Directorist\Order\Mutation::run(
            function () use ( $dto ) {
                $before = \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) );
                $result = \Directorist\Order\Mutation::check_result( parent::update( $dto ) );
                $after  = \Directorist\Order\Mutation::locked_row( $this->get_query_builder()->where( 'id', $dto->get_id() ) );
                if ( $before && $after ) {
                      \Directorist\Order\Activity::order_changed( $before, $after );
                }
                return $result;
            }
        );
    }

    public function accept_checkout_quote( DTO $dto, ?string $previous ): bool {
        $query = $this->get_query_builder()->where( 'id', $dto->get_id() )
            ->where( 'status', $dto->get_status() );
        if ( null === $previous ) {
            $query->where_null( 'checkout_quote' );
        } else {
            $query->where( 'checkout_quote', $previous );
        }

        if ( $query->update( [
            'checkout_quote' => $dto->get_checkout_quote(),
            'amount'         => $dto->get_amount(),
            'sub_total'      => $dto->get_sub_total(),
            'currency'       => $dto->get_currency(),
            'coupon_discount' => $dto->get_coupon_discount(),
            'tax_rate'        => $dto->get_tax_rate(),
        ] ) ) {
            return true;
        }

        $order = $this->get_by_id( $dto->get_id() );
        return $order && $order->status === $dto->get_status() && $order->checkout_quote === $dto->get_checkout_quote();
    }

    public function update_status( int $order_id, string $status ) {
        $dto    = ( new DTO )->set_id( $order_id )->set_status( $status );
        $update = $this->update( $dto );
        return $update;
    }

    public function single( $id ) {
        $order = $this->get_query_builder()->select( 'd_order.*', 'UNIX_TIMESTAMP(d_order.created_at) as created_at_timestamp', 'directorist_listing.post_title as listing_title' )->with(
            [
                'user'     => function( $query ) {
                    $query->select( 'ID', 'user_email', 'display_name' );
                },
                'payments' => function( $query ) {
                    $query->select( '*', 'UNIX_TIMESTAMP(created_at) as created_at_timestamp' )->order_by_desc( 'id' );
                }
            ]
        )->left_join( Post::get_table_name() . ' as directorist_listing', 'd_order.listing_id', '=', 'directorist_listing.ID' )->where( 'd_order.id', $id )->first();

        if ( ! $order ) {
            return null;
        }

        if ( ! empty( $order->payments[0] ) ) {
            $order->payment_method = $this->get_payment_method_title( $order->payments[0]->method );
            $order->transaction_id = $order->payments[0]->transaction_id ?? null;
        } else {
            $order->payment_method = 'N/A';
            $order->transaction_id = null;
        }

        $sub_total = (float) $order->sub_total;

        $order->discount_label  = null;
        $order->discount_amount = null;

        if ( ! empty( $order->coupon_discount ) && ! empty( $order->coupon_discount_type ) ) {
            $discount_amount = directorist_compute_fixed_or_percent_amount( $order->coupon_discount_type, $order->coupon_discount, $sub_total );
            $sub_total       = max( 0, $sub_total - $discount_amount );

            $order->discount_amount = $discount_amount > 0 ? $discount_amount : null;
            $order->discount_label  = OrderDiscountType::PERCENT === $order->coupon_discount_type ? sprintf( esc_html__( 'Discount ( %s%% )', 'directorist' ), $order->coupon_discount ) : esc_html__( 'Discount', 'directorist' );
        }

        $order->tax_label  = null;
        $order->tax_amount = null;
        $tax_amount        = directorist_compute_fixed_or_percent_amount( $order->tax_type, $order->tax_rate, $sub_total );

        if ( $tax_amount > 0 ) {
            $order->tax_amount = $tax_amount;
            $order->tax_label  = OrderTaxType::PERCENT === $order->tax_type ? sprintf( esc_html__( 'Tax ( %s%% )', 'directorist' ), $order->tax_rate ) : esc_html__( 'Tax', 'directorist' );
        }

        $quote = \Directorist\Checkout\Quote::decode( $order->checkout_quote ?? null );
        if ( isset( $quote['payable'] ) ) {
            $order->discount_amount = $quote['payable']['discount'] ?: null;
            $order->tax_amount = $quote['payable']['tax'] ?: null;
            if ( OrderTaxType::PERCENT === $order->tax_type ) {
                $order->tax_label = sprintf( esc_html__( 'Tax ( %s%% )', 'directorist' ), $order->tax_rate );
            }
        }
        $order->total_amount = directorist_order_total_amount( $order );

        $order->transaction_options = \Directorist\Order\ManualPaymentService::describe( $order );
        foreach ( $order->payments as $payment ) {
            $payment->can_mark_paid = \Directorist\Order\ManualPaymentService::can_mark_paid( $order, $payment, $order->transaction_options );
            $payment->can_delete    = 'bank_transfer' === $payment->method;
        }

        return apply_filters( 'directorist_order_data', $order );
    }

    public function to_dto( $order ) {
        if ( ! $order ) {
            return null;
        }
        $dto = ( new DTO )
            ->set_id( $order->id )
            ->set_subscription_id( $order->subscription_id )
            ->set_user_id( $order->user_id )
            ->set_listing_id( $order->listing_id )
            ->set_is_featured_listing( $order->is_featured_listing )
            ->set_ref( $order->ref ?? null )
            ->set_ref_type( $order->ref_type ?? null )
            ->set_amount( $order->amount )
            ->set_currency( $order->currency )
            ->set_coupon_code( $order->coupon_code )
            ->set_coupon_discount( $order->coupon_discount )
            ->set_coupon_discount_type( $order->coupon_discount_type )
            ->set_tax_type( $order->tax_type ?? '' )
            ->set_tax_rate( $order->tax_rate ?? 0.0 )
            ->set_sub_total( $order->sub_total )
            ->set_status( $order->status )
            ->set_created_at( new DateTime( $order->created_at ) );

        if ( ! empty( $order->checkout_quote ) ) {
            $dto->set_checkout_quote( $order->checkout_quote );
        }

        if ( ! empty( $order->updated_at ) ) {
            $dto->set_updated_at( new DateTime( $order->updated_at ) );
        }

        if ( ! empty( $order->expires_at ) ) {
            $dto->set_expires_at( new DateTime( $order->expires_at ) );
        }

        return $dto;
    }

    private function get_payment_method_title( $payment_method ) {
        $payment_method_title = get_directorist_option( "{$payment_method}_title" );
        return ! empty( $payment_method_title ) ? $payment_method_title : $payment_method;
    }
}
