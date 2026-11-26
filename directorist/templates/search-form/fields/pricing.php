<?php
/**
 * @author  wpWax
 * @since   6.6
 * @version 8.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$conditional_logic_attr = $searchform->get_conditional_logic_attributes( $data );
$ptype                  = $searchform->get_pricing_type();
$show_price_unit        = 'both' === $ptype || 'price_unit' === $ptype;
$show_price_range       = 'both' === $ptype || 'price_range' === $ptype;
$max_placeholder        = ! empty( $data['price_range_max_placeholder'] ) ? $data['price_range_max_placeholder'] : '';
$min_placeholder        = ! empty( $data['price_range_min_placeholder'] ) ? $data['price_range_min_placeholder'] : '';
$label                  = ! empty( $data['label'] ) ? $data['label'] : __( 'Pricing', 'directorist' );
$currency               = $searchform->c_symbol ?? '$';
?>

<div class="directorist-search-field directorist-search-form-dropdown directorist-form-group <?php echo esc_attr( $empty_label ); ?>"<?php echo $conditional_logic_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped in get_conditional_logic_attributes() ?>>
    <div class="directorist-search-basic-dropdown directorist-search-field__input">
        <?php if ( ! empty( $label ) ) : ?>
            <label class="directorist-search-field__label directorist-search-basic-dropdown-label">
                <?php echo esc_html( $label ); ?>
                <?php directorist_icon( 'fas fa-chevron-down' ); ?> 
            </label>
        <?php endif; ?>
        <div class="directorist-search-basic-dropdown-content">
            <?php if ( $show_price_unit ) : ?>
                <div class="directorist-price-ranges__limits directorist-flex directorist-mt-10" style="gap: 30px;">
                    <div class="directorist-col">
                        <?php if ( ! empty( $min_placeholder ) ) : ?>
                            <label for="pricing-slider-range__input-values__min"><?php echo esc_html( $min_placeholder ); ?></label>
                        <?php endif; ?>
                        <div class="directorist-form-group__with-prefix">
                            <span class="directorist-form-group__prefix"><?php echo esc_html( $currency ); ?></span>
                            <input type="number" name="price[0]" class="directorist-form-element pricing-slider-range__input-values__min" id="pricing-slider-range__input-values__min" value="<?php echo esc_attr( $searchform->price_value( 'min' ) ); ?>" min="0">
                        </div>
                    </div>
                    <div class="directorist-col">
                        <?php if ( ! empty( $max_placeholder ) ) : ?>
                            <label for="pricing-slider-range__input-values__max"><?php echo esc_html( $max_placeholder ); ?></label>
                        <?php endif; ?>
                        <div class="directorist-form-group__with-prefix">
                            <span class="directorist-form-group__prefix"><?php echo esc_html( $currency ); ?></span>
                            <input type="number" name="price[1]" class="directorist-form-element pricing-slider-range__input-values__max" id="pricing-slider-range__input-values__max" value="<?php echo esc_attr( $searchform->price_value( 'max' ) ); ?>" min="0">
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $show_price_range ) : ?>
                <?php
                $options        = $searchform->get_price_range_options();
                $selected_range = $searchform->selected_price_range( $options );
                ?>

                <?php if ( $options ) : ?>
                    <div class="directorist-price-ranges__options directorist-mt-20">
                        <?php foreach ( $options as $index => $option ) : ?>
                            <?php
                            $id = function_exists( 'wp_unique_id' )
                                ? wp_unique_id( 'directorist-price-range-' )
                                : str_replace( '.', '-', uniqid( 'directorist-price-range-', true ) );
                            ?>
                            <div class="directorist-radio directorist-radio-circle<?php echo $index ? ' directorist-mt-10' : ''; ?>">
                                <input type="radio" name="price_range" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $option['option_value'] ); ?>" <?php checked( $selected_range, $option['option_value'] ); ?>>
                                <label class="directorist-radio__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $option['option_label'] ); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="directorist-search-field__btn directorist-search-field__btn--clear">
        <?php directorist_icon( 'fas fa-times-circle' ); ?> 
    </div>

</div>
