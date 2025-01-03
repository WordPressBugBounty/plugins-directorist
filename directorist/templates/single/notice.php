<?php
/**
 * @author  wpWax
 * @since   6.6
 * @version 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( !$notice_text ) {
	return;
}
?>

<section class="directorist-alert directorist-alert-info directorist-single-listing-notice">

	<div class="directorist-alert__content">

		<?php echo esc_html( $notice_text ); ?>

		<button type="button" class="directorist-alert__close"><span aria-hidden="true">&times;</span></button>

	</div>

</section>