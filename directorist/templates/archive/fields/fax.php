<?php
/**
 * @author  wpWax
 * @since   6.6
 * @version 8.0
 */

use \Directorist\Helper;

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<li class="directorist-listing-card-fax">
    <?php directorist_icon( $icon ); ?>
    <?php $listings->print_label( $label ); ?>
    <a href="tel:<?php Helper::formatted_tel( $value ); ?>"><?php echo esc_html( $value ); ?></a>
</li>