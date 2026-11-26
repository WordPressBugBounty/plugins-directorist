<?php
/**
 * @author  wpWax
 * @since   7.0
 * @version 7.7.0
 */
use Directorist\Checkout\Currency;
use Directorist\Enums\Payment\Status;
use Directorist\Helper;
use Directorist\DTO\Order\DTO;
use Directorist\DTO\Payment\DTO as PaymentDTO;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Enums\Order\TaxType as OrderTaxType;
use Directorist\Enums\Order\DiscountType as OrderDiscountType;

/**
 * @var array $order_items
 * @var DTO $order
 * @var ?PaymentDTO $payment
 * @var float $discount_amount
 * @var float $tax_amount
 * @var array $quote
 */
$source             = $quote['source'] ?? [];
$source_subtotal    = $source['subtotal'] ?? null;
$order_currency     = $order->get_currency();
$display_currency   = $quote['display_currency'] ?? $order_currency;
$precision          = $quote['precision'] ?? 2;
$show_conversion    = isset( $source['subtotal'], $source['total'], $quote['payable']['total'] ) && $display_currency !== $order_currency;
$display_precision  = $show_conversion ? 2 : $precision;
$format             = static function ( float $amount ) use ( $display_currency, $display_precision ): string {
    return Currency::format( $amount, $display_currency, $display_precision );
};
$single_item       = 1 === count( (array) $order_items );
$quoted_item_price = $single_item ? $source_subtotal : null;
$show_item_prices  = ! $show_conversion || $single_item;
$show_discount     = isset( $source['discount'] ) ? $source['discount'] > 0 : ! empty( $order->get_coupon_discount() );
$show_tax          = isset( $source['tax'] ) ? $source['tax'] > 0 : ! empty( $order->get_tax_rate() );
?>
<div id="directorist" class="atbd_wrapper directorist directory_wrapper single_area directorist-w-100">
    <div class="<?php Helper::directorist_container_fluid(); ?>">
        <div class="<?php Helper::directorist_row(); ?>">
            <div class="directorist-col-md-8 directorist-offset-md-2">
                <div class="directorist-payment-receipt">
                    <p class="directorist-payment-thanks-text"><?php esc_html_e( 'Thank you for your order!', 'directorist' ); ?></p>

                    <?php do_action( 'directorist_payment_receipt_before_order_details', $order ); ?>
                    
                    <div class="directorist-payment-table directorist-table-responsive directorist-mb-30">
                        <table class="directorist-table">
                            <thead>
                                <tr>
                                    <th colspan="2"><?php esc_html_e( 'Order Summary', 'directorist' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="directorist-payment-table__label"><?php esc_html_e( 'Order ID', 'directorist' ); ?></td>
                                    <td><?php echo esc_html( $order->get_id() ); ?></td>
                                </tr>
                                <tr>
                                    <td class="directorist-payment-table__label"><?php esc_html_e( 'Date', 'directorist' ); ?></td>
                                    <td><?php echo esc_html( $order->get_created_at()->format( get_option( 'date_format' ) ) ); ?></td>
                                </tr>
                                <tr>
                                    <td class="directorist-payment-table__label"><?php esc_html_e( 'Transaction ID', 'directorist' ); ?></td>
                                    <td><?php echo isset( $payment ) ? esc_html( $payment->get_transaction_id() ) : 'NIL'; ?></td>
                                </tr>
                                <?php if ( $payment ) { ?>
                                <tr>
                                    <td class="directorist-payment-table__label"><?php esc_html_e( 'Payment Method', 'directorist' ); ?></td>
                                    <td>
                                        <?php
                                            $gw_title = get_directorist_option( "{$payment->get_method()}_title" );
                                            echo ! empty( $gw_title ) ? esc_html( $gw_title ) : esc_html( $payment->get_method() );
                                        ?>
                                    </td>
                                </tr>
                                <?php } ?>
                                <tr>
                                    <td class="directorist-payment-table__label"><?php esc_html_e( 'Payment Status', 'directorist' ); ?></td>
                                    <td>
                                        <?php echo esc_html( Status::get_i18n( $order->get_status() ) ); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="directorist-payment-table__label"><?php esc_html_e( 'Amount', 'directorist' ); ?></td>
                                    <td><?php echo esc_html( $format( $source_subtotal ?? $order->get_sub_total() ) ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="directorist-payment-table directorist-table-responsive directorist-payment-summery-table">
                        <table class="directorist-table">
                            <thead>
                                <tr>
                                    <th colspan="2"><?php esc_html_e( 'Item summary', 'directorist' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>

                            <?php if ( ! empty( $order_items ) ): ?>
                            <?php foreach ( $order_items as $order_item ): ?>
                            <tr>
                                <td>
                                    <?php
                                    if ( ! empty( $order_item['title'] ) ) {
                                        printf( '<h5 class="directorist-payment-table__title">%s</h5>', esc_html( $order_item['title'] ) );
                                    }

                                    if ( ! empty( $order_item['desc'] ) ) { ?>
                                    <p> <?php echo esc_html( $order_item['desc'] ); ?> </p> 
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ( $show_item_prices ) : ?>
                                        <?php echo esc_html( $format( $quoted_item_price ?? (float) $order_item['price'] ) ); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>

                            <?php // The saved quote has a subtotal, but no individual item prices. ?>
                            <?php if ( ! $show_item_prices ): ?>
                            <tr>
                                <td class="directorist-payment-table__title"><?php esc_html_e( 'Subtotal', 'directorist' ); ?></td>
                                <td><?php echo esc_html( $format( $source_subtotal ) ); ?></td>
                            </tr>
                            <?php endif; ?>

                            <?php if ( $show_discount ): ?>
                            <tr>
                                <td class="directorist-payment-table__title">
                                    <?php echo $order->get_coupon_discount_type() === OrderDiscountType::PERCENT ? sprintf( esc_html__('Discount ( %d%% )', 'directorist' ), $order->get_coupon_discount() ) : esc_html__( 'Discount', 'directorist' ); ?>
                                </td>
                                <td>
                                    <?php echo '-' . esc_html( $format( $source['discount'] ?? $discount_amount ) ); ?>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <?php if ( $show_tax ): ?>
                            <tr>
                                <td class="directorist-payment-table__title">
                                    <?php
                                    if ( OrderTaxType::PERCENT === $order->get_tax_type() ) {
                                        $tax_rate = $order->get_tax_rate();
                                        $decimals = floor( $tax_rate ) === $tax_rate ? 0 : 2;

                                        printf(
                                            /* translators: %s: tax rate percentage. */
                                            esc_html__( 'Tax ( %s%% )', 'directorist' ),
                                            esc_html( number_format_i18n( $tax_rate, $decimals ) )
                                        );
                                    } else {
                                        esc_html_e( 'Tax', 'directorist' );
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo esc_html( $format( $source['tax'] ?? $tax_amount ) ); ?>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <tr class="directorsit-payment-table-total">
                                <td class="directorist-payment-table__title"><?php esc_html_e( 'Total amount', 'directorist' ); ?></td>
                                <td>
                                    <?php 
                                        echo esc_html( $format( $source['total'] ?? $quote['payable']['total'] ?? directorist_compute_order_total_amount(
                                            $order->get_sub_total(), 
                                            $order->get_tax_rate(), 
                                            $order->get_tax_type(), 
                                            $order->get_coupon_discount(), 
                                            $order->get_coupon_discount_type()
                                        ) ) );
                                    ?>
                                </td>
                            </tr>
                            <?php if ( $show_conversion ): ?>
                            <tr class="directorsit-payment-table-total">
                                <td class="directorist-payment-table__title">
                                    <?php esc_html_e( 'Total Amount After Conversion', 'directorist' ); ?>
                                    <?php if ( ! empty( $quote['exchange_rate'] ) ): ?>
                                        <br>
                                        <?php echo esc_html( sprintf( '1 %1$s = %2$s %3$s', $display_currency, $quote['exchange_rate'], $order_currency ) ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( Currency::format( $quote['payable']['total'], $order_currency, $precision ) ); ?></td>
                            </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                $url  = apply_filters( 'atbdp_payment_receipt_button_link', ATBDP_Permalink::get_dashboard_page_link(), $order->get_id() );
                $text = apply_filters( 'atbdp_payment_receipt_button_text', __( 'View your listings', 'directorist' ), $order->get_id() );
                ?>
                <div class="directorist-text-center directorist-mt-30"><a href="<?php echo esc_url( $url ); ?>" class="directorist-btn directorist-btn-lg directorist-btn-view-listing"><?php echo esc_attr( $text ); ?></a></div>
                <?php
                    $is_retry = ( $order->get_amount() > 0 || ! empty( $quote['renewal'] ) ) && in_array( $order->get_status(), [ OrderStatus::PENDING, OrderStatus::FAILED ], true );
                    $is_allowed_retry_payment = apply_filters( 'directorist_payment_receipt_is_allowed_retry_payment', $is_retry, $payment, $order );

                if ( $is_allowed_retry_payment ) {
                    ?>
                        <div class="directorist-text-center directorist-mt-30">
                            <?php if ( empty( $quote['gateway'] ) ) : ?>
                                <a class="directorist-btn directorist-btn-lg" href="<?php echo esc_url( directorist_get_checkout_page_url( 'payment', [ 'order_id' => $order->get_id() ] ) ); ?>"><?php esc_html_e( 'Review payment', 'directorist' ); ?></a>
                            <?php elseif ( 'bank_transfer' !== $quote['gateway'] ) : ?>
                                <button id="directorist-payment-receipt-retry-payment" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" class="directorist-btn directorist-btn-lg directorist-btn-view-listing"><?php esc_html_e( 'Retry Payment', 'directorist' ); ?></button>
                            <?php endif; ?>
                        </div>
                    <?php
                }
                ?>
            </div>
        </div>
    </div>
</div>
