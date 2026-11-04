<?php
/**
 * Reference Callbacks for tools.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Context;

use Directorist\MCP\Tool_Providers\Orders\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Taxonomies\Read_Callbacks as Taxonomies_Read_Callbacks;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Reference Callbacks. */
class Reference_Callbacks {
    /**
     * References.
     *
     * @param array $args Validated tool input.
     */
    public static function references( array $args ): array {
        $kind = $args['kind'];
        if ( 'order_references' === $kind ) {
            return Read_Callbacks::references( $args );
        }
        if ( 'assignment_terms' === $kind ) {
            Validation::check( isset( $args['taxonomy'], $args['directory_id'] ), __( 'Specify taxonomy and directory_id for term choices.', 'directorist' ) );
            return Taxonomies_Read_Callbacks::read( 'list-terms', $args, true );
        }
        if ( 'directories' === $kind ) {
            list( $page, $size ) = Pagination::paging( $args );
            $query               = [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'orderby' => 'term_id', 'search' => $args['search'] ?? '' ];
            if ( isset( $args['ids'] ) ) {
                $query['include'] = Validation::ids( $args['ids'] );
                if ( ! $query['include'] ) {
                    return Pagination::collection( [], 0, $page, $size );
                }
            }
            $total = Validation::unwrap( wp_count_terms( $query ) );
            $terms = Validation::unwrap( get_terms( $query + [ 'number' => $size, 'offset' => ( $page - 1 ) * $size ] ) );
            $type  = get_post_type_object( ATBDP_POST_TYPE );
            return Pagination::collection(
                array_map(
                    static function ( $term ) use ( $type ) {
                        return [ 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'can_submit' => current_user_can( $type->cap->create_posts ) ];
                    }, $terms
                ), $total, $page, $size
            );
        }
        list( $page, $size ) = Pagination::paging( $args );
        if ( 'pages' === $kind ) {
            Access_Control::admin();
        }
        Validation::check( in_array( $kind, [ 'pages', 'media' ], true ), __( 'Unsupported reference kind.', 'directorist' ) );
        if ( 'media' === $kind && ! current_user_can( 'edit_posts' ) ) {
            return Pagination::collection( [], 0, $page, $size );
        }
        $query = [
            'post_type'      => 'pages' === $kind ? 'page' : 'attachment',
            'post_status'    => 'pages' === $kind ? [ 'publish', 'private', 'draft' ] : 'inherit',
            'posts_per_page' => $size,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            's'              => $args['search'] ?? ''
        ];
        if ( 'media' === $kind && ! current_user_can( 'edit_others_posts' ) ) {
            $query['author'] = get_current_user_id();
        }
        if ( isset( $args['ids'] ) ) {
            $query['post__in'] = Validation::ids( $args['ids'] );
            if ( ! $query['post__in'] ) {
                return Pagination::collection( [], 0, $page, $size );
            }
        }
        $posts = new \WP_Query( $query );
        $items = [];
        foreach ( $posts->posts as $post ) {
            if ( current_user_can( 'pages' === $kind ? 'read_post' : 'edit_post', $post->ID ) ) {
                $items[] = [ 'id' => (int) $post->ID, 'title' => (string) $post->post_title ];
            }
        }
        return Pagination::collection( $items, $posts->found_posts, $page, $size );
    }

    /**
     * Handle get-reference-data through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_reference_data( array $args = [] ): array {
        return self::references( $args );
    }
}
