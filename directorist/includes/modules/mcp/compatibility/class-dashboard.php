<?php
/**
 * Compatibility facade for the original Dashboard API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Dashboard\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Dashboard\Tool_Provider;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Dashboard {
    /** Forward definitions to its implementation. */
    public static function definitions() {
        return Tool_Provider::definitions();
    }

    /** Forward listing access to its implementation. */
    public static function listing_access() {
        return Read_Callbacks::listing_access();
    }

    /**
     * Forward permission to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function permission( $tool, array $args = [] ) {
        return Read_Callbacks::permission( $tool, $args );
    }

    /**
     * Forward range to its implementation.
     *
     * @param array $args Validated tool input.
     */
    public static function range( array $args ) {
        return Read_Callbacks::range( $args );
    }

    /**
     * Forward sections to its implementation.
     *
     * @param mixed $scope Scope.
     */
    public static function sections( $scope ) {
        return Read_Callbacks::sections( $scope );
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
}
