<?php
/**
 * Term Validator for taxonomies.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Taxonomies;

use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Term Validator. */
class Term_Validator {
    /**
     * Term.
     *
     * @param mixed $taxonomy Taxonomy.
     * @param mixed $id Id.
     */
    public static function term( $taxonomy, $id ): \WP_Term {
        $term = get_term( Validation::id( $id ), Validation::taxonomy( $taxonomy ) );
        Validation::check( $term && ! is_wp_error( $term ), __( 'Term not found.', 'directorist' ), 'not_found' );
        return $term;
    }

    /**
     * Version.
     *
     * @param \WP_Term $term Term.
     */
    public static function version( \WP_Term $term ): string {
        return Canonical_Data::hash( [ $term->to_array(), get_term_meta( $term->term_id ), self::dependencies( $term ) ] );
    }

    /**
     * Dependencies.
     *
     * @param \WP_Term $term Term.
     */
    public static function dependencies( \WP_Term $term ): array {
        global $wpdb;
        return [
            'relationships' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $term->term_taxonomy_id ) ),
            'children'      => get_terms( [ 'taxonomy' => $term->taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids' ] ),
            'protected'     => (bool) apply_filters( 'directorist_mcp_term_has_dependencies', false, $term )
        ];
    }

    /**
     * Scope.
     *
     * @param int $id Id.
     */
    public static function scope( int $id ): array {
        if ( ! directorist_is_multi_directory_enabled() ) {
            return [ (int) directorist_get_default_directory() ];
        }
        $value = get_term_meta( $id, '_directory_type', true );
        // Follow Directorist's legacy effective scope for terms without associations.
        return is_array( $value ) && $value ? array_values( array_map( 'intval', $value ) ) : [ (int) directorist_get_default_directory() ];
    }
}
