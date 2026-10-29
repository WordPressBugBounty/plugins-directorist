<?php
/**
 * Directorist Email Field class.
 *
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Email_Field extends Base_Field {
    public $type = 'email';

    public function validate( $posted_data ) {
        $value = $this->get_value( $posted_data );

        if ( ! directorist_is_contact_string( $value ) || ! is_email( $value ) || sanitize_email( $value ) !== $value ) {
            $this->add_error( __( 'Invalid email address.', 'directorist' ) );

            return false;
        }

        return true;
    }

    public function sanitize( $posted_data ) {
        $value = $this->get_value( $posted_data );
        return directorist_is_contact_string( $value ) && is_email( $value ) ? $value : '';
    }
}

Fields::register( new Email_Field() );
