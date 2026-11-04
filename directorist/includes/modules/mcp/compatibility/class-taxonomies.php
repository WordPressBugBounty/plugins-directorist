<?php
/**
 * Compatibility facade for the original Taxonomies API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Taxonomies\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Taxonomies\Term_Validator;
use Directorist\MCP\Tool_Providers\Taxonomies\Write_Callbacks;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Taxonomies {
    /**
     * Forward scope to its implementation.
     *
     * @param mixed $id Id.
     */
    public static function scope( $id ) {
        return Term_Validator::scope( $id );
    }

    /**
     * Forward term to its implementation.
     *
     * @param mixed $taxonomy Taxonomy.
     * @param mixed $id Id.
     */
    public static function term( $taxonomy, $id ) {
        return Term_Validator::term( $taxonomy, $id );
    }

    /**
     * Forward version to its implementation.
     *
     * @param mixed $term Term.
     */
    public static function version( $term ) {
        return Term_Validator::version( $term );
    }

    /**
     * Forward dependencies to its implementation.
     *
     * @param mixed $term Term.
     */
    public static function dependencies( $term ) {
        return Term_Validator::dependencies( $term );
    }

    /**
     * Forward data to its implementation.
     *
     * @param mixed $term Term.
     * @param mixed $assignment Assignment.
     */
    public static function data( $term, $assignment = false ) {
        return Read_Callbacks::data( $term, $assignment );
    }

    /** Forward schemas to its implementation. */
    public static function schemas() {
        return Read_Callbacks::schemas();
    }

    /**
     * Forward read to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param mixed $assignment Assignment.
     */
    public static function read( $tool, array $args, $assignment = false ) {
        return Read_Callbacks::read( $tool, $args, $assignment );
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
