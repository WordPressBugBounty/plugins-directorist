<?php
/**
 * Compatibility facade for the original Settings API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Settings\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Settings\Settings_Schema;
use Directorist\MCP\Tool_Providers\Settings\Write_Callbacks;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Settings {
    /** Forward registry to its implementation. */
    public static function registry() {
        return Settings_Schema::registry();
    }

    /**
     * Forward field schema to its implementation.
     *
     * @param array $field Field.
     */
    public static function field_schema( array $field ) {
        return Settings_Schema::field_schema( $field );
    }

    /**
     * Forward read to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function read( $tool, array $args ) {
        return Read_Callbacks::read( $tool, $args );
    }

    /**
     * Forward prepare to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function prepare( $tool, array $args ) {
        return Write_Callbacks::prepare( $tool, $args );
    }
}
