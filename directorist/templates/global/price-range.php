<?php
/**
 * @author  wpWax
 * @since   6.6
 * @version 7.3.1
 */

use Directorist\Price_Ranges;

if ( ! defined( 'ABSPATH' ) ) exit;

$html = Price_Ranges::display_content( $active_items, $currency, $price_range_text );
?>

<span class="directorist-listing-price-range directorist-tooltip" data-label="<?php echo esc_attr( $price_range_text ); ?>"><?php echo wp_kses_post( $html );?></span>
