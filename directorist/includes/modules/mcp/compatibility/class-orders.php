<?php
/**
 * Compatibility facade for the original Orders API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Tool_Providers\Orders\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Orders\Tool_Provider;
use Directorist\MCP\Tool_Providers\Orders\Write_Callbacks;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Orders {
    /** Forward definitions to its implementation. */
    public static function definitions() {
        return Tool_Provider::definitions();
    }

    /** Forward tables to its implementation. */
    public static function tables() {
        return Order_Policy::tables();
    }

    /** Forward ready to its implementation. */
    public static function ready() {
        return Order_Policy::ready();
    }

    /** Forward admission to its implementation. */
    public static function admission() {
        return Order_Policy::admission();
    }

    /**
     * Forward permission to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function permission( $tool, array $args ) {
        return Order_Policy::permission( $tool, $args );
    }

    /**
     * Forward get to its implementation.
     *
     * @param mixed $id Id.
     * @param mixed $legacy Legacy.
     */
    public static function get( $id, $legacy = null ) {
        return Order_Policy::get( $id, $legacy );
    }

    /**
     * Forward version to its implementation.
     *
     * @param mixed $order Order.
     */
    public static function version( $order ) {
        return Order_Policy::version( $order );
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
     * Forward references to its implementation.
     *
     * @param array $args Validated tool input.
     */
    public static function references( array $args ) {
        return Read_Callbacks::references( $args );
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
