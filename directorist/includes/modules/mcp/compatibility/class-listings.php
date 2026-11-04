<?php
/**
 * Compatibility facade for the original Listings API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Listings\Listing_Schema;
use Directorist\MCP\Tool_Providers\Listings\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Listings\Write_Callbacks;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Listings {
    /**
     * Forward directory to its implementation.
     *
     * @param mixed $id Id.
     */
    public static function directory( $id ) {
        return Listing_Schema::directory( $id );
    }

    /**
     * Forward schema to its implementation.
     *
     * @param array $args Validated tool input.
     */
    public static function schema( array $args ) {
        return Listing_Schema::schema( $args );
    }

    /**
     * Forward version to its implementation.
     *
     * @param mixed $post Post.
     */
    public static function version( $post ) {
        return Listing_Schema::version( $post );
    }

    /**
     * Forward data to its implementation.
     *
     * @param mixed $post Post.
     * @param mixed $scope Scope.
     * @param mixed $summary Summary.
     */
    public static function data( $post, $scope = 'management', $summary = false ) {
        return Listing_Schema::data( $post, $scope, $summary );
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
