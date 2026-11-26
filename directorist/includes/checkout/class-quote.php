<?php

namespace Directorist\Checkout;

defined( 'ABSPATH' ) || exit;

use ATBDP_Gateway;
use Directorist\DTO\Order\DTO as Order;
use WP_REST_Request;

final class Quote {
    public static function stored( Order $order ): array {
        return self::decode( $order->get_checkout_quote() );
    }

    public static function decode( ?string $value ): array {
        $decoded = $value ? json_decode( $value, true ) : null;
        return is_array( $decoded ) ? $decoded : [];
    }

    public static function draft(): string {
        return wp_json_encode( [ 'draft_currency' => strtoupper( trim( directorist_get_currency() ) ) ?: 'USD' ] );
    }

    public static function breakdown( float $subtotal, ?string $discount_type, ?float $discount_rate, ?string $tax_type, ?float $tax_rate ): array {
        $discount = min( $subtotal, directorist_compute_fixed_or_percent_amount( $discount_type, $discount_rate, $subtotal ) );
        $tax      = directorist_compute_fixed_or_percent_amount( $tax_type, $tax_rate, $subtotal - $discount );

        return [
            'subtotal' => round( $subtotal, 2 ),
            'discount' => $discount,
            'tax'      => $tax,
            'total'    => round( $subtotal - $discount + $tax, 2 ),
        ];
    }

    public static function totals( string $type, WP_REST_Request $request ): array {
        $subtotal = round( (float) apply_filters( 'directorist_checkout_subtotal', 0, $type, $request ), 2 );
        $total    = round( (float) apply_filters( 'directorist_checkout_total', $subtotal, $type, $request ), 2 );

        return [ $subtotal, $total ];
    }

    public static function choices( string $type, WP_REST_Request $request, float $subtotal, float $total ): array {
        $display  = strtoupper( trim( directorist_get_currency() ) ) ?: 'USD';
        $matches  = Currency::matches_display();
        $checkout = $matches ? $display : trim( atbdp_get_payment_currency() );
        $order    = 'payment' === $type ? directorist_get_order_by_id( (int) $request->get_param( 'order_id' ) ) : null;
        $stored   = $order ? self::decode( $order->checkout_quote ?? null ) : [];
        $legacy   = $order && ! $stored;

        $source = [ 'subtotal' => $subtotal, 'discount' => 0.0, 'tax' => max( 0, $total - $subtotal ), 'total' => $total ];
        $terms    = [ 'due' => $source ];
        if ( $order && isset( $stored['draft_currency'] ) ) {
            $display = $stored['draft_currency'];
            $terms['due'] = self::breakdown( $order->sub_total, $order->coupon_discount_type, $order->coupon_discount, $order->tax_type, $order->tax_rate );
        } elseif ( $legacy ) {
            $display = strtoupper( trim( $order->currency ) );
            // Older orders can store the subtotal in amount, or have no separate subtotal.
            $legacy_subtotal = (float) $order->sub_total ?: (float) $order->amount;
            $terms['due'] = self::breakdown( $legacy_subtotal, $order->coupon_discount_type, $order->coupon_discount, $order->tax_type, $order->tax_rate );
        }

        // Product providers supply their display-currency breakdown and any recurring schedule.
        $terms = apply_filters( 'directorist_checkout_quote_terms', $terms, $type, $request, $order );

        $needs_gateway = ( empty( $terms['coupon'] ) ? $subtotal : $terms['due']['total'] ) > 0
            || ( $order && (float) $order->amount > 0 ) || ! empty( $stored['renewal'] ) || ! empty( $terms['recurring'] );
        $show_gateways = apply_filters( 'directorist_checkout_show_payment_gateways', $needs_gateway, $type, $subtotal, $request );
        $gateways = $show_gateways ? apply_filters( 'directorist_checkout_active_gateways', ATBDP_Gateway::get_active_gateways(), $type, $request ) : [ '' ];
        $pricing_plan = 'plan' === $type || 'pricing_plan' === ( $order->ref_type ?? '' );
        $product_supports_quotes = ! $pricing_plan || ! empty( $terms['pricing_plans'] );
        $processors = directorist_get_payment_processors();
        $bank_currency = get_directorist_option( 'bank_transfer_currency', Currency::DEFAULT_BANK_TRANSFER_CURRENCY );
        $choices = [];
        $show_conversion = ! $matches && ! $legacy;
        $checkout_exchange_rate = $show_conversion ? Currency::exchange_rate( $display, $checkout ) : null;
        $equivalent = $checkout_exchange_rate ? self::convert_breakdown( $terms['due'], $checkout_exchange_rate, Currency::precision( $checkout ) ) : null;

        if ( isset( $stored['payable'] ) ) {
            $display = $stored['display_currency'];
            $checkout = $stored['checkout_currency'] ?? $stored['currency'];
            $checkout_exchange_rate = $stored['checkout_exchange_rate'] ?? null;
            $equivalent = $stored['equivalent'] ?? null;
            $show_conversion = ! empty( $stored['show_conversion'] );
            $gateway = $stored['gateway'];
            if ( in_array( $gateway, $gateways, true ) && ( '' === $gateway || is_callable( [ $processors[$gateway] ?? '', 'checkout_currency_precision' ] ) ) ) {
                $choices[$gateway] = $stored;
            }
        } else {
            foreach ( $gateways as $gateway ) {
                $processor = $processors[$gateway] ?? '';
                $native = '' === $gateway || ( $product_supports_quotes && is_callable( [ $processor, 'checkout_currency_precision' ] ) );
                $bank_display = 'bank_transfer' === $gateway && 'display' === $bank_currency;
                $pay_currency = $legacy || $matches || $bank_display || '' === $gateway ? $display : $checkout;

                if ( ! preg_match( '/^[A-Z]{3}$/D', $pay_currency ) || ( '' !== $gateway && ! $processor ) ) {
                    continue;
                }

                $payable_exchange_rate = $legacy ? '1' : Currency::exchange_rate( $display, $pay_currency );
                if ( ! $native || null === $payable_exchange_rate ) {
                    // Before the rate and compatible extensions are in place, let these
                    // processors use their existing pricing without applying a quote.
                    if ( ! $legacy || ( $display === $checkout && (float) $order->sub_total > 0 ) ) {
                        $choices[$gateway] = [ 'gateway' => $gateway, 'currency' => $checkout, 'native' => false ];
                    }
                    continue;
                }

                $precision = '' !== $gateway ? $processor::checkout_currency_precision( $pay_currency ) : Currency::precision( $pay_currency );
                if ( null === $precision || $precision > 2 ) {
                    continue;
                }

                $due = self::convert_breakdown( $terms['due'], $payable_exchange_rate, $precision );
                $recurring = '' !== $gateway && ! empty( $terms['recurring'] ) ? $terms['recurring'] : null;
                $renewal = $recurring ? self::convert_breakdown( $recurring, $payable_exchange_rate, $precision ) : null;
                if ( ! $due || ( $recurring && ! $renewal ) || ( $legacy && abs( $due['total'] - $terms['due']['total'] ) > 0.000001 ) ) {
                    continue;
                }

                $choices[$gateway] = [
                    'gateway'                => $gateway,
                    'display_currency'       => $display,
                    'currency'               => $pay_currency,
                    'exchange_rate'          => $payable_exchange_rate,
                    'show_conversion'        => $show_conversion,
                    'checkout_currency'      => $checkout,
                    'checkout_exchange_rate' => $checkout_exchange_rate,
                    'equivalent'             => $equivalent,
                    'precision'              => $precision,
                    'source'                 => $terms['due'],
                    'payable'                => $due,
                    'source_renewal'         => $recurring,
                    'renewal'                => $renewal,
                    'schedule'               => $recurring ? ( $terms['schedule'] ?? [] ) : [],
                    'legacy'                 => (bool) $legacy,
                    'native'                 => $native,
                ];
                if ( ! empty( $terms['coupon'] ) ) {
                    $choices[$gateway]['coupon'] = $terms['coupon'];
                }
            }
        }

        return [
            'choices'         => $choices,
            'show_gateways'   => $show_gateways,
            'show_conversion' => $show_conversion,
            'legacy'          => (bool) ( $stored['legacy'] ?? $legacy ),
            'display'         => $display,
            'checkout'        => $checkout,
            'exchange_rate'   => $checkout_exchange_rate,
            'converted'       => $equivalent,
            'source'          => $stored['source'] ?? $terms['due'],
            'total'           => $stored['source']['total'] ?? $terms['due']['total'],
            'review_required' => ! $matches || (bool) $order,
        ];
    }

    public static function hides_conversion( array $quote ): bool {
        return ! $quote['native'] || ( 'bank_transfer' === $quote['gateway'] && $quote['currency'] === $quote['display_currency'] );
    }

    public static function review_payload( string $type, WP_REST_Request $request ): array {
        [ $subtotal, $total ] = self::totals( $type, $request );
        $currency_review = self::choices( $type, $request, $subtotal, $total );
        $choices = $currency_review['choices'];
        $selected = (string) $request->get_param( 'payment_gateway' );
        if ( ! isset( $choices[$selected] ) ) {
            $selected = (string) ( array_key_first( $choices ) ?? '' );
        }

        $gateways = [];
        foreach ( $choices as $gateway => $quote ) {
            $gateways[] = [
                'gateway'         => $gateway,
                'token'           => self::token( $quote, $type, $request ),
                'hide_conversion' => self::hides_conversion( $quote ),
            ];
        }

        return [
            'total'         => $currency_review['total'],
            'total_display' => Currency::format( $currency_review['total'], $currency_review['display'] ),
            'tax'           => $currency_review['source']['tax'],
            'tax_display'   => Currency::format( $currency_review['source']['tax'], $currency_review['display'] ),
            'show_gateways' => $currency_review['show_gateways'],
            'gateways'      => $gateways,
            'rows'          => \Directorist\Utils\Template::get( 'checkout/currency-review', compact( 'currency_review', 'choices', 'selected' ) ),
        ];
    }

    private static function convert_breakdown( array $source, string $exchange_rate, int $precision ): ?array {
        $total    = Currency::convert( $source['total'], $exchange_rate, $precision );
        $discount = Currency::convert( $source['discount'], $exchange_rate, $precision );
        $tax      = Currency::convert( $source['tax'], $exchange_rate, $precision );
        if ( null === $total || null === $discount || null === $tax || ( $source['total'] > 0 && 0.0 === $total ) ) {
            return null;
        }

        // Keep the displayed components consistent with the independently rounded total.
        $tax = min( $tax, $total + $discount );
        $subtotal = round( $total + $discount - $tax, $precision );
        if ( $subtotal > 99999999.99 ) {
            return null;
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax'      => $tax,
            'total'    => $total,
        ];
    }

    public static function token( array $quote, string $type, WP_REST_Request $request ): string {
        $context = array_map(
            static function ( $key ) use ( $request ) {
                return (int) $request->get_param( $key );
            },
            [ 'order_id', 'listing_id', 'plan_id', 'is_featured', 'is_trial', 'plan_reassignment', 'listing_renewal' ]
        );

        return hash_hmac( 'sha256', wp_json_encode( [ get_current_user_id(), wp_get_session_token(), $type, $context, $quote ] ), wp_salt( 'nonce' ) );
    }

    public static function apply( Order $order, array $quote ): void {
        $payable = $quote['payable'];
        $order->set_checkout_quote( wp_json_encode( $quote ) )
            ->set_currency( $quote['currency'] )
            ->set_amount( $payable['total'] )
            ->set_sub_total( $payable['subtotal'] );

        if ( $order->is_initialized( 'tax_type' ) && in_array( $order->get_tax_type(), [ 'flat', 'fixed' ], true ) ) {
            $order->set_tax_rate( $payable['tax'] );
        }
        if ( $order->is_initialized( 'coupon_discount_type' ) && in_array( $order->get_coupon_discount_type(), [ 'flat', 'fixed' ], true ) ) {
            $order->set_coupon_discount( $payable['discount'] );
        }
    }

    public static function agrees( array $quote, float $amount, string $currency, bool $renewal = false ): bool {
        if ( empty( $quote['payable'] ) ) {
            return true;
        }
        $expected = $renewal ? $quote['renewal']['total'] ?? null : $quote['payable']['total'];
        return null !== $expected && $quote['currency'] === strtoupper( $currency )
            && abs( $expected - $amount ) < 0.000001;
    }

    public static function assert_payment( Order $order, string $gateway, float $amount, string $currency, bool $renewal = false ): void {
        $quote = self::stored( $order );
        if ( isset( $quote['payable'] ) && ( $quote['gateway'] !== $gateway || ! self::agrees( $quote, $amount, $currency, $renewal ) ) ) {
            throw new \UnexpectedValueException( 'Gateway payment does not match the accepted Directorist checkout quote.' );
        }
    }

    public static function with_payment_lock( string $identity, callable $callback ) {
        global $wpdb;
        $key = 'dir-payment-' . substr( hash( 'sha256', $wpdb->prefix . ':' . $identity ), 0, 48 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
            throw new \RuntimeException( 'Directorist payment is already being processed.' );
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
        }
    }

    public static function gateway_request_id( Order $order, string $operation, int $retry_window ): string {
        $elapsed = $order->is_initialized( 'created_at' ) ? max( 0, time() - $order->get_created_at()->getTimestamp() ) : 0;
        $window = intdiv( $elapsed, $retry_window );

        return substr( hash( 'sha256', home_url() . ':' . $operation . ':' . $order->get_id() . ':' . $window ), 0, 36 );
    }

    public static function renewal( array $quote ): array {
        if ( empty( $quote['renewal'] ) ) {
            return [];
        }
        $quote['source']  = $quote['source_renewal'];
        $quote['payable'] = $quote['renewal'];
        return $quote;
    }
}
