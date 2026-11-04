<?php
/**
 * Legacy catalog facade.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Existing catalog API, backed by one registry. */
class Catalog {
    /** Return the registered inventory. */
    public static function definitions() {
        return Registration\Tool_Registry::instance()->definitions(); }

    /** Return core descriptors before extension registration. */
    public static function core_definitions() {
        return Registration\Core_Tool_Provider::core_definitions(); }
}
