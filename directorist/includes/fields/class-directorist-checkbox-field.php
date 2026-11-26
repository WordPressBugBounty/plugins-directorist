<?php
/**
 * Directorist Checkbox Field class.
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checkbox_Field extends Choice_Field {
    public $type = 'checkbox';
}

Fields::register( new Checkbox_Field() );
