<?php

defined( 'ABSPATH' ) || exit;

use Directorist\Checkout\Currency;
use Directorist\Checkout\Quote;

$show_conversion = $currency_review['show_conversion'] && $currency_review['display'] !== $currency_review['checkout'];
$converted_total = $currency_review['converted']['total'] ?? null;
$selected_quote  = $choices[$selected] ?? null;
?>
<?php if ( $show_conversion ) : ?>
    <tr class="directorist-summery-total" data-checkout-conversion-row<?php echo $selected_quote && Quote::hides_conversion( $selected_quote ) ? ' hidden' : ''; ?>>
        <td colspan="2">
            <span class="directorist-summery-label">
                <?php esc_html_e( 'Total amount after conversion', 'directorist' ); ?>
            </span>
            <?php if ( null !== $converted_total ) : ?>
                <br>
                <span class="directorist-summery-label">
                    <?php echo esc_html( sprintf( '1 %1$s = %2$s %3$s', $currency_review['display'], $currency_review['exchange_rate'], $currency_review['checkout'] ) ); ?>
                </span>
            <?php endif; ?>
        </td>
        <td class="directorist-text-right" data-checkout-equivalent>
            <div class="directorist-summery-amount">
                <?php if ( null !== $converted_total ) : ?>
                    <?php echo esc_html( Currency::format( $converted_total, $currency_review['checkout'], min( 2, Currency::precision( $currency_review['checkout'] ) ) ) ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Conversion unavailable', 'directorist' ); ?>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php endif; ?>
<?php foreach ( $choices as $gateway => $quote ) : ?>
    <?php
    if ( ! $quote['native'] ) :
        if ( $show_conversion ) :
            ?>
            <tr data-checkout-gateway="<?php echo esc_attr( $gateway ); ?>" <?php echo $gateway === $selected ? '' : 'hidden'; ?>>
                <td colspan="3">
                    <?php
                    /* translators: %s: checkout currency code. */
                    printf( esc_html__( 'Currency conversion is unavailable for this method. It uses the existing checkout pricing in %s.', 'directorist' ), esc_html( $quote['currency'] ) );
                    ?>
                </td>
            </tr>
            <?php
        endif;
        continue;
    endif;

    if ( 'bank_transfer' === $gateway ) {
        continue;
    }

    $conversion_matches_due = $show_conversion && null !== $converted_total
        && $currency_review['checkout'] === $quote['currency']
        && (float) $converted_total === (float) $quote['payable']['total'];

    if ( ! $conversion_matches_due && ( $show_conversion || $quote['renewal'] || $quote['legacy'] || $quote['currency'] !== $quote['display_currency'] ) ) :
        ?>
        <tr data-checkout-gateway="<?php echo esc_attr( $gateway ); ?>" <?php echo $gateway === $selected ? '' : 'hidden'; ?>>
            <td colspan="2">
                <?php esc_html_e( 'Amount due today', 'directorist' ); ?>
            </td>
            <td class="directorist-text-right"><?php echo esc_html( Currency::format( $quote['payable']['total'], $quote['currency'], $quote['precision'] ) ); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ( $quote['renewal'] ) : ?>
        <tr data-checkout-gateway="<?php echo esc_attr( $gateway ); ?>" <?php echo $gateway === $selected ? '' : 'hidden'; ?>>
            <td colspan="2">
                <?php echo ! empty( $quote['schedule']['trial_count'] ) ? esc_html__( 'Recurring amount after trial', 'directorist' ) : esc_html__( 'Recurring amount', 'directorist' ); ?>
            </td>
            <td class="directorist-text-right"><?php echo esc_html( Currency::format( $quote['renewal']['total'], $quote['currency'], $quote['precision'] ) ); ?></td>
        </tr>
    <?php endif; ?>
<?php endforeach; ?>
