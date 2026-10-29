<?php
/**
 * @author  wpWax
 * @since   6.7
 * @version 7.0.5.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$value = directorist_contact_email_for_display( $value );
if ( '' === $value ) {
    return;
}
?>

<div class="directorist-single-info directorist-single-info-email">

    <div class="directorist-single-info__label">
        <span class="directorist-single-info__label-icon"><?php directorist_icon( $icon );?></span>
        <span class="directorist-single-info__label__text"><?php echo esc_html( $data['label'] ); ?></span>
    </div>

    <div class="directorist-single-info__value">
        <a target="_top" href="mailto:<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $value ); ?></a>
    </div>

</div>