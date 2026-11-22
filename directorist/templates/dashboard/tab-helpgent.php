<?php
/**
 * Listing-owner enquiry dashboard mount point.
 *
 * @package DirectoristHelpGentIntegration
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Directorist\Integrations\HelpGent\Integration::class, false ) ) {
    return;
}
?>
<div class="dhgi-dashboard" id="dhgi-dashboard">
    <div class="dhgi-dashboard__loading"><?php esc_html_e( 'Loading enquiries…', 'directorist' ); ?></div>
</div>
