<?php
/**
 * Directorist Select Field class.
 *
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Select_Field extends Choice_Field {
    public $type = 'select';
}

Fields::register( new Select_Field() );
