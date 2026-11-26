<?php

namespace Directorist\PaymentProcessors;

defined( "ABSPATH" ) || exit;

use Directorist\DTO\Payment\DTO;
use Directorist\Enums\Payment\Status;
use Directorist\Contracts\PaymentInterface;
use Directorist\DTO\Order\DTO as OrderDTO;

class BankTransfer extends Payment implements PaymentInterface {
    public static function get_key(): string {
        return 'bank_transfer';
    }

    public static function checkout_currency_precision( string $currency ): int {
        return \Directorist\Checkout\Currency::precision( $currency );
    }

    public function pay( OrderDTO $dto, array $params = [] ): ?string {
        $repository = directorist_payment_repository();
        $payment = $repository->get_last_payment( $dto->get_id() );
        $amount = directorist_order_payable( $dto );
        if ( $payment && static::get_key() === $payment->method && Status::PENDING === $payment->status
            && $dto->get_currency() === $payment->currency && $amount === (float) $payment->amount ) {
            return null;
        }

        $payment_dto = ( new DTO )
        ->set_order_id( $dto->get_id() )
        ->set_amount( $amount )
        ->set_currency( $dto->get_currency() )
        ->set_status( Status::PENDING )
        ->set_method( static::get_key() )
        ->set_transaction_id( wp_generate_password( 15, false ) );

        $repository->create( $payment_dto );
        return null;
    }
}
