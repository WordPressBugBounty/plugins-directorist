<?php
/**
 * Directorist Social Info Field class.
 *
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Social_Info_Field extends Base_Field {
    public $type = 'social_info';

    public function get_value( $posted_data ) {
        if ( ! isset( $posted_data['social'] ) ) {
            return [];
        }

        $items = directorist_normalize_contact_field( $posted_data['social'], $this->type );
        return is_array( $items ) ? array_values(
            array_filter(
                $items, static function( $item ) {
                    return ! is_array( $item ) || ! isset( $item['url'] ) || '' !== $item['url'];
                }
            )
        ) : $items;
    }

    public function validate( $posted_data ) {
        $items = $this->get_value( $posted_data );

        if ( ! is_array( $items ) ) {
            $this->add_error( __( 'Invalid social info.', 'directorist' ) );
            return false;
        }
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['id'] ) || ! is_string( $item['id'] ) || ! isset( $item['url'] ) || ! directorist_is_contact_string( $item['url'] ) || '' === esc_url_raw( $item['url'] ) ) {
                $this->add_error( __( 'Invalid social info.', 'directorist' ) );
                return false;
            }
        }

        $items = array_filter(
            $items, static function( $item ) {
                return ! ( empty( $item['id'] ) || empty( $item['url'] ) );
            }
        );

        if ( ! count( $items ) ) {
            $this->add_error( __( 'Invalid social info.', 'directorist' ) );
            return false;
        }

        return true;
    }

    public function sanitize( $posted_data ) {
        $_items = $this->get_value( $posted_data );

        if ( ! is_array( $_items ) ) {
            return [];
        }

        $items = [];

        foreach ( $_items as $item ) {
            if ( empty( $item['id'] ) || empty( $item['url'] ) ) {
                continue;
            }

            $items[] = [
                'id'  => $item['id'],
                'url' => sanitize_url( $item['url'] )
            ];
        }

        return $items;
    }
}

Fields::register( new Social_Info_Field() );
