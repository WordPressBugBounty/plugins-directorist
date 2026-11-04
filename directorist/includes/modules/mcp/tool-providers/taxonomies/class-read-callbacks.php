<?php
/**
 * Read Callbacks for taxonomies.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Taxonomies;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Read Callbacks. */
class Read_Callbacks {
    /**
     * Read.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param bool $assignment Assignment.
     */
    public static function read( string $tool, array $args, bool $assignment = false ): array {
        if ( 'list-taxonomies' === $tool ) {
            return self::schemas();
        }
        $taxonomy = Validation::taxonomy( $args['taxonomy'] );
        Access_Control::term_cap( $taxonomy, $assignment ? 'assign' : 'read' );
        if ( 'get-term' === $tool ) {
            return self::data( Term_Validator::term( $taxonomy, $args['term_id'] ) );
        }
        list( $page, $size ) = Pagination::paging( $args );
        $query               = [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC', 'search' => $args['search'] ?? '' ];
        if ( isset( $args['ids'] ) ) {
            $query['include'] = Validation::ids( $args['ids'] );
            if ( ! $query['include'] ) {
                return Pagination::collection( [], 0, $page, $size );
            }
        }
        if ( isset( $args['parent'] ) ) {
            $query['parent'] = $args['parent'];
        }
        if ( $assignment || isset( $args['directory_id'] ) ) {
            $directory = $args['directory_id'] ?? directorist_get_default_directory();
            Validation::directory( $directory );
            if ( directorist_is_multi_directory_enabled() ) {
                $query['meta_query'] = [ 'relation' => 'OR', [ 'key' => '_directory_type', 'value' => 'i:' . $directory . ';', 'compare' => 'LIKE' ], [ 'key' => '_directory_type', 'value' => '"' . $directory . '"', 'compare' => 'LIKE' ] ];
                if ( (int) $directory === (int) directorist_get_default_directory() ) {
                    $query['meta_query'][] = [ 'key' => '_directory_type', 'compare' => 'NOT EXISTS' ];
                }
            }
        }
        $total = Validation::unwrap( wp_count_terms( $query ) );
        $terms = Validation::unwrap( get_terms( $query + [ 'number' => $size, 'offset' => ( $page - 1 ) * $size ] ) );
        return Pagination::collection(
            array_map(
                static function ( $term ) use ( $assignment ) {
                    return self::data( $term, $assignment );
                }, $terms
            ), $total, $page, $size
        );
    }

    /** Schemas. */
    public static function schemas(): array {
        $items = [];
        foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $name ) {
            $tax     = get_taxonomy( $name );
            $items[] = [
                'taxonomy'     => $name,
                'label'        => $tax->label,
                'hierarchical' => (bool) $tax->hierarchical,
                'properties'   => ATBDP_TAGS === $name ? [ 'name', 'slug', 'description', 'directories' ] : [ 'name', 'slug', 'description', 'parent', 'directories', 'image' ],
                'permissions'  => [ 'manage' => current_user_can( $tax->cap->manage_terms ), 'edit' => current_user_can( $tax->cap->edit_terms ), 'delete' => current_user_can( $tax->cap->delete_terms ), 'assign' => current_user_can( $tax->cap->assign_terms ) ]
            ];
        }
        return [ 'items' => $items ];
    }

    /**
     * Data.
     *
     * @param \WP_Term $term Term.
     * @param bool $assignment Assignment.
     */
    public static function data( \WP_Term $term, bool $assignment = false ): array {
        $data = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'parent' => (int) $term->parent ];
        if ( ! $assignment ) {
            $data += [
                'taxonomy'    => $term->taxonomy,
                'description' => $term->description,
                'count'       => (int) $term->count,
                'directories' => Term_Validator::scope( $term->term_id ),
                'version'     => Term_Validator::version( $term )
            ];
            if ( ATBDP_CATEGORY === $term->taxonomy ) {
                $data['icon'] = get_term_meta( $term->term_id, 'category_icon', true );
            }
            if ( ATBDP_TAGS !== $term->taxonomy ) {
                $data['image'] = (int) get_term_meta( $term->term_id, 'image', true );
            }
        }
        return $data;
    }

    /**
     * Handle list-taxonomies through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_taxonomies( array $args = [] ): array {
        return self::read( 'list-taxonomies', $args );
    }

    /**
     * Handle list-terms through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_terms( array $args = [] ): array {
        return self::read( 'list-terms', $args );
    }

    /**
     * Handle get-term through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_term( array $args = [] ): array {
        return self::read( 'get-term', $args );
    }
}
