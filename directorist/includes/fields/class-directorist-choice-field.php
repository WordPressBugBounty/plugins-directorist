<?php
namespace Directorist\Fields;

defined( 'ABSPATH' ) || exit;

abstract class Choice_Field extends Base_Field {
    public function get_options() {
        return array_map(
            static function ( $option ) {
                return directorist_get_choice_value( $option['option_value'] );
            },
            (array) $this->options
        );
    }

    public function validate( $posted_data ) {
        $value = $this->get_value( $posted_data );
        if ( 'checkbox' !== $this->type && ! is_scalar( $value ) ) {
            $this->add_error( __( 'Invalid choice.', 'directorist' ) );
            return false;
        }

        foreach ( (array) $value as $item ) {
            if ( ! is_scalar( $item ) || ! directorist_get_selected_choice_values( $this->options, $item, 'submitted' ) ) {
                $this->add_error( __( 'Invalid choice.', 'directorist' ) );
                return false;
            }
        }

        return true;
    }

    public function sanitize( $posted_data ) {
        $values = directorist_get_selected_choice_values( $this->options, $this->get_value( $posted_data ), 'submitted' );
        $stored = array_map( 'directorist_get_choice_storage_value', $values );

        return 'checkbox' === $this->type ? $stored : ( $stored[0] ?? '' );
    }
}
