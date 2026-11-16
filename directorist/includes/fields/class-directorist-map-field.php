<?php
/**
 * Directorist Map Field class.
 *
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Map_Field extends Base_Field {
    public $type = 'map';

    public function get_value( $posted_data ) {
        $latitude  = isset( $posted_data['manual_lat'] ) && is_scalar( $posted_data['manual_lat'] ) ? trim( (string) $posted_data['manual_lat'] ) : '';
        $longitude = isset( $posted_data['manual_lng'] ) && is_scalar( $posted_data['manual_lng'] ) ? trim( (string) $posted_data['manual_lng'] ) : '';
        if ( '' === $latitude && '' === $longitude && ! isset( $posted_data['hide_map'] ) ) {
            return [];
        }

        return [
            'hide_map'   => (bool) sanitize_text_field( $posted_data['hide_map'] ?? '' ),
            'manual_lat' => sanitize_text_field( $latitude ),
            'manual_lng' => sanitize_text_field( $longitude )
        ];
    }
}

Fields::register( new Map_Field() );
