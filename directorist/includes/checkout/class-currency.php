<?php

namespace Directorist\Checkout;

defined( 'ABSPATH' ) || exit;

final class Currency {
    const DEFAULT_BANK_TRANSFER_CURRENCY = 'checkout';

    public static function exchange_rate_pair( array $options ): string {
        $display = strtoupper( trim( (string) ( $options['g_currency'] ?? 'USD' ) ) );
        $checkout = strtoupper( trim( (string) ( $options['payment_currency'] ?? $display ) ) );
        return ( $display ?: 'USD' ) . ':' . ( $checkout ?: 'USD' );
    }

    public static function matches_display(): bool {
        $setting = get_directorist_option( 'payment_currency_match_display', null );
        if ( null !== $setting ) {
            return (bool) $setting;
        }

        $display = strtoupper( trim( directorist_get_currency() ) );
        return $display === atbdp_get_payment_currency()
            && get_directorist_option( 'g_currency_position', 'before' ) === get_directorist_option( 'payment_currency_position', 'before' );
    }

    public static function validate_settings( $validation, array $options ) {
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $exchange_rate = $options['payment_currency_exchange_rate'] ?? '';
        if ( '' !== $exchange_rate && ! self::is_valid_exchange_rate( $exchange_rate ) ) {
            return new \WP_Error( 'invalid_exchange_rate', __( 'Enter a positive decimal exchange rate using a period and up to 12 decimal places.', 'directorist' ) );
        }

        if ( isset( $options['bank_transfer_currency'] ) && ! in_array( $options['bank_transfer_currency'], [ 'display', 'checkout' ], true ) ) {
            return new \WP_Error( 'invalid_bank_currency', __( 'Select display currency or checkout currency for bank transfer.', 'directorist' ) );
        }

        if ( isset( $options['payment_currency_match_display'] ) && ! in_array( $options['payment_currency_match_display'], [ '0', '1', 0, 1, false, true ], true ) ) {
            return new \WP_Error( 'invalid_currency_match', __( 'Select a valid setting for Match display currency.', 'directorist' ) );
        }

        return $validation;
    }

    private static function is_valid_exchange_rate( $value ): bool {
        return ( is_string( $value ) || is_int( $value ) )
            && preg_match( '/^[0-9]{1,8}(?:\.[0-9]{1,12})?$/D', $value )
            && (float) $value > 0;
    }

    public static function exchange_rate( string $display, string $checkout ): ?string {
        if ( $display === $checkout ) {
            return '1';
        }

        $exchange_rate = get_directorist_option( 'payment_currency_exchange_rate', '' );
        $saved_pair = get_directorist_option( 'payment_currency_exchange_pair', '' );

        return "$display:$checkout" === $saved_pair && self::is_valid_exchange_rate( $exchange_rate ) ? (string) $exchange_rate : null;
    }

    public static function precision( string $currency ): int {
        if ( in_array( $currency, [ 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ], true ) ) {
            return 0;
        }

        return in_array( $currency, [ 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ], true ) ? 3 : 2;
    }

    /**
     * Multiply decimal digits before rounding so half-cent boundaries are deterministic.
     */
    public static function convert( float $amount, string $exchange_rate, int $precision ): ?float {
        if ( ! self::is_valid_exchange_rate( $exchange_rate ) || ! is_finite( $amount ) || $amount < 0 || $amount > 99999999.99 || $precision < 0 || $precision > 2 ) {
            return null;
        }

        $amount_digits        = str_replace( '.', '', number_format( $amount, 2, '.', '' ) );
        $parts                = explode( '.', $exchange_rate, 2 );
        $fraction             = $parts[1] ?? '';
        $exchange_rate_digits = strrev( $parts[0] . $fraction );
        $amount_digits        = strrev( $amount_digits );
        $product              = array_fill( 0, strlen( $exchange_rate_digits ) + strlen( $amount_digits ), 0 );

        foreach ( str_split( $exchange_rate_digits ) as $i => $digit ) {
            foreach ( str_split( $amount_digits ) as $j => $amount_digit ) {
                $product[$i + $j] += (int) $digit * (int) $amount_digit;
            }
        }

        for ( $i = 0, $last = count( $product ) - 1; $i < $last; ++$i ) {
            $product[$i + 1] += intdiv( $product[$i], 10 );
            $product[$i] %= 10;
        }

        $cut   = 2 + strlen( $fraction ) - $precision;
        $minor = ltrim( implode( '', array_reverse( array_slice( $product, $cut ) ) ), '0' );
        if ( strlen( $minor ) > 10 ) {
            return null;
        }

        $result = ( (float) $minor + ( ( $product[$cut - 1] ?? 0 ) >= 5 ? 1 : 0 ) ) / ( 10 ** $precision );
        return $result <= 99999999.99 ? $result : null;
    }

    public static function format( float $amount, string $currency, int $precision = 2 ): string {
        $display = strtoupper( trim( directorist_get_currency() ) );
        $position = $display === $currency ? directorist_get_currency_position() : atbdp_get_payment_currency_settings()['position'];
        $value = number_format_i18n( $amount, $precision );

        return 'after' === $position ? "$value $currency" : "$currency $value";
    }
}
