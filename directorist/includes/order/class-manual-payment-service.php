<?php
/** Explicit administrative bank-transfer commands. Gateway writes retain their own rules. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

use Directorist\DTO\Payment\DTO;

class ManualPaymentService {
    public static function describe( $order ) {
        global $wpdb;
        // The balance is a form default only; administrators may record any amount.
        $paid = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}directorist_payments WHERE order_id = %d AND status = 'paid' AND currency = %s",
                $order->id, $order->currency
            )
        );
        return [
            'can_add_transaction' => true,
            'remaining'           => number_format( max( 0, directorist_order_total_amount( $order ) - $paid ), 2, '.', '' ),
            'currency'            => $order->currency,
            'precision'           => 2,
            'error'               => '',
        ];
    }

    public static function can_mark_paid( $order, $payment, ?array $options = null ) {
        return in_array( $order->status, [ 'pending', 'unpaid' ], true )
            && 'bank_transfer' === $payment->method
            && in_array( $payment->status, [ 'pending', 'unpaid' ], true )
            && $payment->currency === $order->currency;
    }

    private static function order( $id ) {
        $repository = directorist_order_repository();
        $order      = Mutation::in_transaction()
            ? Mutation::locked_row( $repository->get_query_builder()->where( 'id', (int) $id ) )
            : Mutation::check_result( $repository->get_by_id( (int) $id ) );
        if ( ! $order ) {
            throw new \InvalidArgumentException( __( 'Order not found.', 'directorist' ), 404 );
        }
        return $order;
    }

    private static function permission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            throw new \RuntimeException( __( 'You cannot manage order transactions.', 'directorist' ), 403 );
        }
    }

    public static function defaults( $order_id ) {
        self::permission();
        $options           = self::describe( self::order( $order_id ) );
        $options['method'] = 'bank_transfer';
        $options['status'] = 'pending';
        do {
            $options['transaction_id'] = 'bank-' . wp_generate_uuid4();
        } while ( directorist_payment_repository()->get_by( 'transaction_id', $options['transaction_id'] ) );
        return $options;
    }

    public static function create( $order_id, array $values ) {
        self::permission();
        $amount = self::amount( $values['amount'] ?? null );
        return Actor::run(
            'admin', static function () use ( $order_id, $values, $amount ) {
                return Mutation::run(
                    static function () use ( $order_id, $values, $amount ) {
                        $order = self::order( $order_id );
                        if ( ( $values['method'] ?? 'bank_transfer' ) !== 'bank_transfer' || ! in_array( $values['status'] ?? '', [ 'pending', 'paid' ], true ) ) {
                            throw new \InvalidArgumentException( __( 'Only pending or paid bank-transfer transactions can be added.', 'directorist' ), 400 );
                        }
                        if ( isset( $values['currency'] ) && $values['currency'] !== $order->currency ) {
                            throw new \InvalidArgumentException( __( 'The transaction currency must match the order currency.', 'directorist' ), 400 );
                        }
                        $raw = $values['transaction_id'] ?? '';
                        $id  = is_string( $raw ) ? sanitize_text_field( trim( $raw ) ) : '';
                        if ( '' === $id || strlen( $id ) > 100 ) {
                            throw new \InvalidArgumentException( __( 'A transaction ID of at most 100 characters is required.', 'directorist' ), 400 );
                        }
                        $repository = directorist_payment_repository();
                        if ( Mutation::locked_row( $repository->get_query_builder()->where( 'transaction_id', $id ) ) ) {
                            throw new \InvalidArgumentException( __( 'This transaction ID is already used by another payment.', 'directorist' ), 409 );
                        }
                        $payment_id = $repository->create(
                            ( new DTO() )->set_order_id( (int) $order->id )
                            ->set_method( 'bank_transfer' )->set_transaction_id( $id )->set_status( $values['status'] )
                            ->set_amount( $amount )->set_currency( $order->currency )
                        );
                        if ( ! $payment_id ) {
                            throw new \RuntimeException( __( 'The transaction could not be saved.', 'directorist' ), 500 );
                        }
                        return Mutation::locked_row( $repository->get_query_builder()->where( 'id', $payment_id ) );
                    }
                );
            }
        );
    }

    private static function amount( $value ): float {
        if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
            $decimal = trim( (string) $value );
            if ( preg_match( '/\A(?:[0-9]+(?:\.[0-9]{1,2}0*)?|\.[0-9]{1,2}0*)\z/', $decimal ) ) {
                $amount = (float) $decimal;
                // The payments table stores DECIMAL(10,2).
                if ( $amount > 0 && $amount <= 99999999.99 ) {
                    return $amount;
                }
            }
        }

        throw new \InvalidArgumentException(
            __( 'Enter a positive payment amount with up to two decimal places and a maximum of 99,999,999.99.', 'directorist' ),
            400
        );
    }

    public static function mark_paid( $order_id, $payment_id ) {
        self::permission();
        return Actor::run(
            'admin', static function () use ( $order_id, $payment_id ) {
                return Mutation::run(
                    static function () use ( $order_id, $payment_id ) {
                        $order      = self::order( $order_id );
                        $repository = directorist_payment_repository();
                        $payment    = Mutation::locked_row( $repository->get_query_builder()->where( 'id', (int) $payment_id ) );
                        if ( ! $payment || (int) $payment->order_id !== (int) $order->id ) {
                            throw new \InvalidArgumentException( __( 'Payment not found for this order.', 'directorist' ), 404 );
                        }
                        if ( 'bank_transfer' === $payment->method && 'paid' === $payment->status ) {
                            return $payment; // A retry must not append duplicate transitions.
                        }
                        if ( ! self::can_mark_paid( $order, $payment ) ) {
                            throw new \InvalidArgumentException( __( 'Only eligible pending/unpaid bank transfers can be marked paid.', 'directorist' ), 409 );
                        }
                        $repository->update( ( new DTO() )->set_id( (int) $payment->id )->set_status( 'paid' ) );
                        $payment = Mutation::locked_row( $repository->get_query_builder()->where( 'id', (int) $payment->id ) );
                        self::complete_order( $payment );
                        return $payment;
                    }
                );
            }
        );
    }

    public static function delete( $order_id, $payment_id ) {
        self::permission();
        return Actor::run(
            'admin', static function () use ( $order_id, $payment_id ) {
                return Mutation::run(
                    static function () use ( $order_id, $payment_id ) {
                        $order      = self::order( $order_id );
                        $repository = directorist_payment_repository();
                        $payment    = Mutation::locked_row( $repository->get_query_builder()->where( 'id', (int) $payment_id ) );
                        if ( ! $payment || (int) $payment->order_id !== (int) $order->id ) {
                            throw new \InvalidArgumentException( __( 'Payment not found for this order.', 'directorist' ), 404 );
                        }
                        if ( 'bank_transfer' !== $payment->method ) {
                            throw new \InvalidArgumentException( __( 'Only bank-transfer transactions can be deleted.', 'directorist' ), 405 );
                        }
                        return $repository->delete_by_id( (int) $payment->id );
                    }
                );
            }
        );
    }

    public static function complete_order( $payment ) {
        $order = self::order( $payment->order_id );
        if ( 'bank_transfer' === $payment->method && 'paid' === $payment->status
            && 'paid' !== $order->status && $payment->currency === $order->currency
            && (float) $payment->amount >= round( directorist_order_total_amount( $order ), 2 ) ) {
            directorist_order_repository()->update_status( (int) $order->id, 'paid' );
        }
    }
}
