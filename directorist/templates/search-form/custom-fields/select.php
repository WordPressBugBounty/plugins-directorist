<?php
/**
 * @author  wpWax
 * @since   6.6
 * @version 8.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$conditional_logic_attr = $searchform->get_conditional_logic_attributes( $data );
$selected_values        = directorist_get_selected_choice_values( $data['options']['options'] ?? [], $value, 'submitted' );
?>

<div class="directorist-search-field directorist-form-group"<?php echo $conditional_logic_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped in get_conditional_logic_attributes() ?>>

    <div class="directorist-select directorist-search-field__input">

        <?php if ( ! empty( $data['label'] ) ) : ?>
            <label class="directorist-search-field__label"><?php echo esc_attr( $data['label'] ); ?></label>
        <?php endif; ?>

        <select name='custom_field[<?php echo esc_attr( $data['field_key'] ); ?>]' <?php echo ! empty( $data['required'] ) ? 'required="required"' : ''; ?> data-isSearch="true" data-placeholder="<?php echo esc_html( ! empty( $data['placeholder'] ) ? $data['placeholder'] : __( 'Select', 'directorist' ) )?>">

            <option value=""><?php echo esc_html( ! empty( $data['placeholder'] ) ? $data['placeholder'] : __( 'Select', 'directorist' ) )?></option>

            <?php
            if ( $data['options']['options'] ) {
                foreach ( $data['options']['options'] as $option ) {
                    printf( '<option value="%s"%s>%s</option>', esc_attr( $option['option_value'] ), selected( in_array( directorist_get_choice_value( $option['option_value'] ), $selected_values, true ), true, false ), esc_html( $option['option_label'] ) );
                }
            }
            ?>

        </select>

    </div>

    <div class="directorist-search-field__btn directorist-search-field__btn--clear">
        <?php directorist_icon( 'fas fa-times-circle' ); ?> 
    </div>

</div>