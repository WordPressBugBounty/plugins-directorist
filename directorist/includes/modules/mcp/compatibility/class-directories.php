<?php
/**
 * Compatibility facade for the original Directories API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Directories\Directory_Configuration;
use Directorist\MCP\Tool_Providers\Directories\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Directories\Write_Callbacks;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Directories {
    const AREAS = Directory_Configuration::AREAS;

    /** Forward definitions to its implementation. */
    public static function definitions() {
        return Directory_Configuration::definitions();
    }

    /**
     * Forward configuration to its implementation.
     *
     * @param mixed $id Id.
     */
    public static function configuration( $id ) {
        return Directory_Configuration::configuration( $id );
    }

    /**
     * Forward version to its implementation.
     *
     * @param mixed $id Id.
     */
    public static function version( $id ) {
        return Directory_Configuration::version( $id );
    }

    /**
     * Forward dependencies to its implementation.
     *
     * @param mixed $id Id.
     */
    public static function dependencies( $id ) {
        return Directory_Configuration::dependencies( $id );
    }

    /**
     * Forward summary to its implementation.
     *
     * @param mixed $term Term.
     */
    public static function summary( $term ) {
        return Directory_Configuration::summary( $term );
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
