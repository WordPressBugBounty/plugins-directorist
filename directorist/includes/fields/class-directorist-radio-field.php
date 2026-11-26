<?php
/**
 * Directorist Radio Field class.
 *
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Radio_Field extends Choice_Field {
    public $type = 'radio';
}

Fields::register( new Radio_Field() );
