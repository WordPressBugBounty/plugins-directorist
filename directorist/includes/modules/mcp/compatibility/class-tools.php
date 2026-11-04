<?php
/**
 * Compatibility facade for the original Tools API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Runtime\Tool_Discovery;
use Directorist\MCP\Runtime\Tool_Runner;
use Directorist\MCP\Safety\Bulk_Executor;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Tools {
    /**
     * Forward execute to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function execute( $tool, array $args = [] ) {
        return Tool_Runner::execute( $tool, $args );
    }

    /**
     * Forward prepare to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function prepare( $tool, array $args ) {
        return Tool_Runner::prepare( $tool, $args );
    }

    /**
     * Forward available to its implementation.
     *
     * @param mixed $name Registered action or object name.
     * @param array $definition Registered descriptor.
     */
    public static function available( $name, array $definition ) {
        return Tool_Discovery::available( $name, $definition );
    }

    /**
     * Forward bulk to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param mixed $registered_child Registered child.
     */
    public static function bulk( $tool, array $args, $registered_child = null ) {
        return Bulk_Executor::bulk( $tool, $args, $registered_child );
    }
}
