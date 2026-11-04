<?php
/**
 * Compatibility facade for the original Registry API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Registration\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Registry {
    const API_VERSION          = 1;
    const READ_ADAPTER_VERSION = 1;

    private static $instance;

    /** Return the legacy registration facade. */
    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self(); }
        Tool_Registry::instance();
        return self::$instance;
    }

    /**
     * Forward register extension to its implementation.
     *
     * @param array $d Registered descriptor.
     */
    public function register_extension( array $d ) {
        return Tool_Registry::instance()->register_extension( $d );
    }

    /**
     * Forward register tool to its implementation.
     *
     * @param array $d Registered descriptor.
     */
    public function register_tool( array $d ) {
        return Tool_Registry::instance()->register_tool( $d );
    }

    /** Forward mutation controls to its implementation. */
    public static function mutation_controls() {
        return Tool_Registry::mutation_controls();
    }

    /**
     * Forward schema to its implementation.
     *
     * @param array $s S.
     * @param mixed $depth Depth.
     */
    public static function schema( array $s, $depth = 0 ) {
        return Tool_Registry::schema( $s, $depth );
    }

    /**
     * Forward register field adapter to its implementation.
     *
     * @param array $d Registered descriptor.
     */
    public function register_field_adapter( array $d ) {
        return Tool_Registry::instance()->register_field_adapter( $d );
    }

    /**
     * Forward register policy to its implementation.
     *
     * @param array $d Registered descriptor.
     */
    public function register_policy( array $d ) {
        return Tool_Registry::instance()->register_policy( $d );
    }

    /**
     * Forward register read adapter to its implementation.
     *
     * @param array $d Registered descriptor.
     */
    public function register_read_adapter( array $d ) {
        return Tool_Registry::instance()->register_read_adapter( $d );
    }

    /**
     * Forward read adapters to its implementation.
     *
     * @param mixed $domain Domain.
     * @param array $args Validated tool input.
     */
    public function read_adapters( $domain, array $args ) {
        return Tool_Registry::instance()->read_adapters( $domain, $args );
    }

    /**
     * Forward active to its implementation.
     *
     * @param mixed $owner Owner.
     */
    public function active( $owner ) {
        return Tool_Registry::instance()->active( $owner );
    }

    /**
     * Forward admitted to its implementation.
     *
     * @param mixed $owner Owner.
     */
    public function admitted( $owner ) {
        return Tool_Registry::instance()->admitted( $owner );
    }

    /** Forward admission to its implementation. */
    public function admission() {
        return Tool_Registry::instance()->admission();
    }

    /** Forward definitions to its implementation. */
    public function definitions() {
        return Tool_Registry::instance()->definitions();
    }

    /**
     * Forward fields to its implementation.
     *
     * @param mixed $domain Domain.
     * @param mixed $context Context.
     */
    public function fields( $domain, $context = null ) {
        return Tool_Registry::instance()->fields( $domain, $context );
    }

    /**
     * Forward permission to its implementation.
     *
     * @param mixed $name Registered action or object name.
     * @param array $args Validated tool input.
     * @param mixed $discover Discover.
     */
    public function permission( $name, array $args, $discover = false ) {
        return Tool_Registry::instance()->permission( $name, $args, $discover );
    }

    /**
     * Forward available to its implementation.
     *
     * @param mixed $name Registered action or object name.
     */
    public function available( $name ) {
        return Tool_Registry::instance()->available( $name );
    }

    /** Forward fingerprint to its implementation. */
    public function fingerprint() {
        return Tool_Registry::instance()->fingerprint();
    }

    /** Forward lifecycle to its implementation. */
    public static function lifecycle() {
        return Tool_Registry::lifecycle();
    }

    /**
     * Forward integrations to its implementation.
     *
     * @param array $args Validated tool input.
     */
    public function integrations( array $args ) {
        return Tool_Registry::instance()->integrations( $args );
    }

    /**
     * Forward read to its implementation.
     *
     * @param mixed $name Registered action or object name.
     * @param array $args Validated tool input.
     */
    public function read( $name, array $args ) {
        return Tool_Registry::instance()->read( $name, $args );
    }

    /**
     * Forward ability output schema to its implementation.
     *
     * @param array $d Registered descriptor.
     */
    public static function ability_output_schema( array $d ) {
        return Tool_Registry::ability_output_schema( $d );
    }

    /**
     * Forward prepare to its implementation.
     *
     * @param mixed $name Registered action or object name.
     * @param array $args Validated tool input.
     */
    public function prepare( $name, array $args ) {
        return Tool_Registry::instance()->prepare( $name, $args );
    }
}
