<?php
/**
 * @author  wpWax
 * @since   6.6
 * @version 8.6
 */

use Directorist\Price_Ranges;

if ( ! defined( 'ABSPATH' ) ) exit;

$listing_id              = $listing_form->get_add_listing_id();
$price                   = get_post_meta( $listing_id, '_price', true );
$price_range             = (string) get_post_meta( $listing_id, '_price_range', true );
$price_type              = get_post_meta( $listing_id, '_atbd_listing_pricing', true );
$allow_decimal           = get_directorist_option( 'allow_decimal', 1 );

// Get conditional logic attributes using centralized method
$conditional_logic_attr = $listing_form->get_conditional_logic_attributes( $data );
?>
<div class="directorist-form-group directorist-form-pricing-field price-type-<?php echo esc_attr( $data['pricing_type'] ); ?>"<?php echo $conditional_logic_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped in get_conditional_logic_attributes() ?>>
    <?php $listing_form->field_label_template( $data ); ?>

    <?php if ( $data['pricing_type'] === 'both' ) { ?>
        <div class="directorist-form-pricing-field__options">
            <div class="directorist-checkbox directorist_pricing_options">
                <input type="checkbox" id="price_selected" value="price" name="atbd_listing_pricing" <?php checked( $price_type, 'price' ); ?>>
                <label for="price_selected" class="directorist-checkbox__label" data-option="price"><?php echo esc_html( $data['price_unit_field_label'] );?></label>
            </div>

            <?php if ( ! empty( $price_unit_checkbox ) ) : ?>
                <span class="directorist-form-pricing-field__options__divider"><?php esc_html_e( 'Or', 'directorist' ); ?></span>
            <?php endif; ?>

            <div class="directorist-checkbox directorist_pricing_options">
                <input type="checkbox" id="price_range_selected" value="range" name="atbd_listing_pricing" <?php checked( $price_type, 'range' ); ?>>
                <label for="price_range_selected" class="directorist-checkbox__label" data-option="price_range"><?php echo esc_html( $data['price_range_label'] ); ?></label>
            </div>
        </div>
    <?php } ?>

    <?php if ( $data['pricing_type'] === 'both' || $data['pricing_type'] === 'price_unit' ) { ?>
        <input class="directorist-form-element directory_field directory_pricing_field" id="price" type="<?php echo esc_attr( $data['price_unit_field_type'] ); ?>" name="price" step="<?php echo esc_attr( $allow_decimal ? 'any' : 1 ); ?>" value="<?php echo esc_attr( $price ); ?>" placeholder="<?php echo esc_attr( ! empty( $data['price_unit_field_placeholder'] ) ? $data['price_unit_field_placeholder'] : '' ); ?>" />
    <?php } ?>

    <?php if ( $data['pricing_type'] === 'both' || $data['pricing_type'] === 'price_range' ) { ?>
        <?php
        $options      = Price_Ranges::options( $data );
        $directory_id = $data['price_range_directory_id'] ?? $listing_form->get_current_listing_type();
        $saved_option = Price_Ranges::find( $price_range, $options );
        if ( ! $saved_option && '' !== $price_range && Price_Ranges::can_keep_saved_value( $price_range, $listing_id, $directory_id ) ) {
            $options[] = [ 'option_value' => $price_range, 'option_label' => Price_Ranges::legacy_label( $price_range ) ];
        }
        $selected_range = $saved_option['option_value'] ?? $price_range;
        ?>
        <select class="directorist-form-element directory_field directory_pricing_field" id="price_range" name="price_range">
            <option value=""><?php echo esc_html( ! empty( $data['price_range_placeholder'] ) ? $data['price_range_placeholder'] : '' ); ?></option>
            <?php foreach ( $options as $option ) : ?>
                <option value="<?php echo esc_attr( $option['option_value'] ); ?>" <?php selected( $selected_range, $option['option_value'] ); ?>><?php echo esc_html( $option['option_label'] ); ?></option>
            <?php endforeach; ?>
        </select>
    <?php } ?>

    <?php if ( $data['pricing_type'] === 'price_unit' ) :?>
        <input type="hidden" name="atbd_listing_pricing" value="price">
    <?php endif; ?>

    <?php if ( $data['pricing_type'] === 'price_range' ) :?>
        <input type="hidden" name="atbd_listing_pricing" value="range">
    <?php endif; ?>
</div>
