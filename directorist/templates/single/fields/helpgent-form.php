<?php
/**
 * HelpGent enquiry widget on a Directorist listing.
 *
 * @package DirectoristHelpGentIntegration
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Directorist\Integrations\HelpGent\Integration::class, false ) ) {
    return;
}

$form_id         = absint( $value ?? 0 );
$listing_id      = absint( $data['listing_id'] ?? get_the_ID() );
$heading         = sanitize_text_field( (string) ( $data['heading'] ?? '' ) );
$description     = sanitize_textarea_field( (string) ( $data['description'] ?? '' ) );
$protect_details = \Directorist\Integrations\HelpGent\Integration::NEW_LEAD_LOCKS_ENABLED && ! empty( $data['protect_details'] );

if ( ! $form_id || ! $listing_id ) {
    return;
}

\Directorist\Integrations\HelpGent\Assets::listing();
?>
<section
    class="directorist-helpgent-integration"
    data-listing-id="<?php echo esc_attr( $listing_id ); ?>"
    data-form-id="<?php echo esc_attr( $form_id ); ?>"
    data-protect-details="<?php echo $protect_details ? '1' : '0'; ?>"
>
    <?php if ( $heading ) : ?>
        <h3 class="directorist-helpgent-integration__title"><?php echo esc_html( $heading ); ?></h3>
    <?php endif; ?>
    <?php if ( $description ) : ?>
        <p class="directorist-helpgent-integration__description"><?php echo esc_html( $description ); ?></p>
    <?php endif; ?>
    <?php echo \Directorist\Integrations\HelpGent\Integration::instance()->render_form( $form_id, $listing_id, $protect_details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</section>
