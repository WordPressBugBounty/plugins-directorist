<?php
/**
 * Directorist Pricing Field class.
 *
 */
namespace Directorist\Fields;

use Directorist\Price_Ranges;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pricing_Field extends Base_Field {
    public $type = 'pricing';

    public function get_value( $posted_data ) {
        $listing_id   = (int) ( $posted_data['listing_id'] ?? 0 );
        $directory_id = (int) ( $posted_data['directory_id'] ?? 0 );

        // Partial edits may omit pricing altogether. Preserve the saved choice
        // in the same directory, then apply the same normalization as a form edit.
        $pricing_mode = $this->get_price_type_prop();
        if ( ! array_key_exists( 'atbd_listing_pricing', $posted_data )
            && ! array_key_exists( 'price_range', $posted_data )
            && ! array_key_exists( 'price', $posted_data ) ) {
            $saved_range = Price_Ranges::saved_range_for_edit( $this->get_props(), $listing_id, $directory_id );
            if ( null !== $saved_range ) {
                $posted_data['atbd_listing_pricing'] = 'range';
                $posted_data['price_range']          = $saved_range;
            }
        }

        if ( 'both' !== $pricing_mode ) {
            $posted_data['atbd_listing_pricing'] = $pricing_mode;
        }

        if ( ! isset( $posted_data['atbd_listing_pricing'] ) && ( isset( $posted_data['price'] ) || isset( $posted_data['price_range'] ) ) ) {
            return [];
        }

        $price_type = Price_Ranges::clean( $posted_data['atbd_listing_pricing'] ?? '' );
        $range      = $posted_data['price_range'] ?? '';

        return [
            'price_type'  => $price_type,
            'price'       => round( (float) ( $posted_data['price'] ?? 0 ), 2 ),
            'price_range' => 'range' === $price_type
                ? Price_Ranges::submitted_value( $range, $this->get_props(), $listing_id, $directory_id )
                : Price_Ranges::clean( $range ),
        ];
    }

    public function validate( $posted_data ) {
        $value = $this->get_value( $posted_data );

        if ( ! empty( $value['price_type'] ) && ! in_array( $value['price_type'], $this->get_price_types(), true ) ) {
            /* translators: %s: Price type value */
            $this->add_error( sprintf( __( 'Invalid price type: %s', 'directorist' ), esc_html( $value['price_type'] ) ) );
        }

        if ( ( $value['price_type'] ?? '' ) === 'range' && null === $value['price_range'] ) {
            $this->add_error( __( 'Invalid price range.', 'directorist' ) );
        }

        if ( $this->has_error() ) {
            return false;
        }

        return true;
    }

    protected function get_price_types() {
        return [ 'price', 'range' ];
    }

    protected function get_price_type_prop() {
        $pricing_type = $this->__get( 'pricing_type' );

        if ( $pricing_type === 'price_unit' ) {
            return 'price';
        } elseif ( $pricing_type === 'price_range' ) {
            return 'range';
        } else {
            return 'both';
        }
    }
}

Fields::register( new Pricing_Field() );
