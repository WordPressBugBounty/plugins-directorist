<?php
/**
 * Compatibility facade for the original Site_Health API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Site_Health\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Site_Health\Section_Catalog;
use Directorist\MCP\Tool_Providers\Site_Health\Tool_Provider;



defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Site_Health {
    const POLICY_VERSION = Section_Catalog::POLICY_VERSION;

    /** Forward definitions to its implementation. */
    public static function definitions() {
        return Tool_Provider::definitions();
    }

    /** Forward available to its implementation. */
    public static function available() {
        return Section_Catalog::available();
    }

    /** Forward permission to its implementation. */
    public static function permission() {
        return Section_Catalog::permission();
    }

    /** Forward core fields to its implementation. */
    public static function core_fields() {
        return Section_Catalog::core_fields();
    }

    /**
     * Forward field schema to its implementation.
     *
     * @param mixed $id Id.
     * @param mixed $key Key.
     * @param mixed $type Type.
     */
    public static function field_schema( $id, $key, $type ) {
        return Section_Catalog::field_schema( $id, $key, $type );
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
