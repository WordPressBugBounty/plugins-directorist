<?php
/**
 * @author  wpWax
 * @since   6.6
 * @version 7.0.5.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( empty( $data['label'] ) ) {
	return;
}
?>

<label class="directorist-form-label" for="<?php echo esc_html( $data['field_key']); ?>">
	<?php echo esc_html( $data['label'] ); ?>:<?php echo !empty( $data['required'] ) ? '<span class="directorist-form-required"> *</span>' : ''; ?>
</label>