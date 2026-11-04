<?php
/**
 * Read Callbacks for listings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Listings;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Read Callbacks. */
class Read_Callbacks {
    const EXACT_POLICY_LIMIT = 256;

    /**
     * Read.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function read( string $tool, array $args ): array {
        if ( 'get-listing-schema' === $tool ) {
            return Listing_Schema::schema( $args );
        }
        $scope = $args['scope'] ?? 'management';
        if ( 'get-listing' === $tool ) {
            return Listing_Schema::data( Access_Control::listing( $args['listing_id'], 'read', $scope ), $scope );
        }
        list( $page, $size ) = Pagination::paging( $args );
        $type                = get_post_type_object( ATBDP_POST_TYPE );
        $sort                = [ 'id' => 'ID', 'title' => 'title', 'date' => 'date', 'modified' => 'modified' ][ $args['orderby'] ?? 'id' ];
        $orderby             = [ $sort => strtoupper( $args['order'] ?? 'asc' ) ] + [ 'ID' => 'ASC' ];
        $query               = [
            'post_type'      => ATBDP_POST_TYPE,
            'post_status'    => 'public' === $scope ? 'publish' : [ 'publish', 'pending', 'draft', 'private', 'trash', 'expired', 'rejected' ],
            's'              => $args['search'] ?? '',
            'posts_per_page' => $size,
            'paged'          => $page,
            'orderby'        => $orderby
        ];
        if ( isset( $args['author'] ) ) {
            $query['author'] = Validation::id( $args['author'] );
        }
        if ( 'management' === $scope ) {
            Validation::check( current_user_can( $type->cap->edit_posts ), __( 'Listing management access is required.', 'directorist' ), 'forbidden' );
            if ( ! current_user_can( $type->cap->edit_others_posts ) ) {
                Validation::check( ! isset( $args['author'] ) || $args['author'] === get_current_user_id(), __( 'Listing not found.', 'directorist' ), 'not_found' );
                $query['author'] = get_current_user_id();
            }
            if ( current_user_can( $type->cap->edit_others_posts ) && ! current_user_can( $type->cap->read_private_posts ) ) {
                // A query-level condition keeps protected records out of totals as well as rows.
                $query['post_status'] = [ 'publish', 'pending', 'draft', 'trash', 'expired', 'rejected' ];
            }
        }
        if ( isset( $args['status'] ) ) {
            Validation::check( in_array( $args['status'], (array) $query['post_status'], true ) || $args['status'] === $query['post_status'], __( 'This status is unavailable in this read scope.', 'directorist' ) );
            $query['post_status'] = $args['status'];
        }
        if ( isset( $args['ids'] ) ) {
            $query['post__in'] = Validation::ids( $args['ids'] );
            if ( ! $query['post__in'] ) {
                return Pagination::collection( [], 0, $page, $size );
            }
        }
        $tax_query = [];
        foreach ( [ 'directory_id' => ATBDP_DIRECTORY_TYPE, 'categories' => ATBDP_CATEGORY, 'locations' => ATBDP_LOCATION, 'tags' => ATBDP_TAGS ] as $key => $taxonomy ) {
            if ( isset( $args[ $key ] ) ) {
                $tax_query[] = [ 'taxonomy' => $taxonomy, 'terms' => 'directory_id' === $key ? [ Validation::directory( $args[ $key ] )->term_id ] : Validation::ids( $args[ $key ] ), 'include_children' => false ];
            }
        }
        if ( $tax_query ) {
            $query['tax_query'] = $tax_query;
        }
        foreach ( [ 'created' => 'post_date_gmt', 'modified' => 'post_modified_gmt' ] as $prefix => $column ) {
            $date = [ 'column' => $column, 'inclusive' => true ];
            foreach ( [ 'after', 'before' ] as $bound ) {
                if ( isset( $args[ $prefix . '_' . $bound ] ) ) {
                    Validation::check( false !== strtotime( $args[ $prefix . '_' . $bound ] ), __( 'Invalid date filter.', 'directorist' ) );
                    $date[ $bound ] = $args[ $prefix . '_' . $bound ];
                }
            }
            if ( count( $date ) > 2 ) {
                $query['date_query'][] = $date;
            }
        }
        if ( 'public' === $scope ) {
            $query['has_password'] = false;
            Validation::check( ! get_directorist_option( 'disable_single_listing', false ), __( 'Public listing access is disabled.', 'directorist' ), 'forbidden' );
        }
        $featured = isset( $args['featured'] ) ? rest_sanitize_boolean( $args['featured'] ) : null;
        $query    = apply_filters( 'directorist_mcp_listing_read_query', $query, $scope, get_current_user_id() );
        if ( 'public' === $scope && self::has_sql_visibility() ) {
            $results = self::query( $query, $featured );
            $posts   = self::authorized( $results->posts, $scope );
            if ( count( $posts ) === count( $results->posts ) && 'publish' === $results->get( 'post_status' ) && false === $results->get( 'has_password' ) && ATBDP_POST_TYPE === $results->get( 'post_type' ) ) {
                // WP_Query skips found_rows on empty pages. Count from the first page in that case.
                $total = ! $posts && $page > 1 ? self::query( [ 'paged' => 1, 'posts_per_page' => 1 ] + $query, $featured )->found_posts : $results->found_posts;
                return Pagination::collection( self::project( $posts, $scope ), $total, $page, $size );
            }
        }

        // Small scopes keep exact visible totals. Never scan an entire large scope
        // to evaluate a PHP-only permission policy.
        $probe = self::query( [ 'paged' => 1, 'posts_per_page' => self::EXACT_POLICY_LIMIT + 1, 'no_found_rows' => true ] + $query, $featured );
        if ( count( $probe->posts ) <= self::EXACT_POLICY_LIMIT ) {
            $posts = self::authorized( $probe->posts, $scope );
            return Pagination::collection( self::project( array_slice( $posts, ( $page - 1 ) * $size, $size ), $scope ), count( $posts ), $page, $size );
        }

        $offset = ( $page - 1 ) * $size;
        $window = 1 === $page ? array_slice( $probe->posts, 0, $size + 1 ) : self::query( [ 'offset' => $offset, 'posts_per_page' => $size + 1, 'no_found_rows' => true ] + $query, $featured )->posts;
        $posts  = self::authorized( array_slice( $window, 0, $size ), $scope );
        return [
            'items'           => self::project( $posts, $scope ),
            'page'            => $page,
            'per_page'        => $size,
            'total'           => null,
            'total_pages'     => null,
            'has_more'        => count( $window ) > $size,
            'pagination_mode' => 'candidate_windows',
        ];
    }

    /** Whether the public SQL predicates completely describe the native visibility policy. */
    private static function has_sql_visibility(): bool {
        global $wp_filter;
        $native = $wp_filter['directorist_rest_check_permissions']->callbacks ?? [];
        foreach ( $native as $callbacks ) {
            foreach ( $callbacks as $callback ) {
                if ( 'directorist_allow_read_context_permission' !== $callback['function'] || $callback['accepted_args'] < 4 ) {
                    return false;
                }
            }
        }
        return ! has_filter( 'directorist_mcp_public_listing_visible' )
            && ! has_filter( 'directorist_mcp_listing_read_query' )
            && ! has_filter( 'get_post_status' )
            && ! empty( get_post_status_object( 'publish' )->public );
    }

    /**
     * Apply the same stored Featured value as the detail projection, including absent
     * metadata and older records with more than one value.
     *
     * @param array $args Query arguments.
     * @param bool|null $featured Featured filter, when supplied.
     */
    private static function query( array $args, ?bool $featured ): \WP_Query {
        if ( null === $featured ) {
            return new \WP_Query( $args );
        }

        $query = new \WP_Query();
        $where = static function ( $sql, $current ) use ( $query, $featured ) {
            if ( $query !== $current ) {
                return $sql;
            }
            global $wpdb;
            $operator = $featured ? 'NOT IN' : 'IN';
            return $sql . " AND CAST(COALESCE((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = '_featured' ORDER BY meta_id LIMIT 1), '') AS BINARY) {$operator} ('', '0')";
        };
        add_filter( 'posts_where', $where, 10, 2 );
        try {
            $args['suppress_filters'] = false;
            $query->query( $args );
            return $query;
        } finally {
            remove_filter( 'posts_where', $where, 10 );
        }
    }

    /**
     * Filter records using the same object policy as single-listing reads.
     *
     * @param array $posts Candidate posts.
     * @param string $scope Projection scope.
     */
    private static function authorized( array $posts, string $scope ): array {
        $allowed = [];
        foreach ( $posts as $post ) {
            try {
                $allowed[] = Access_Control::listing( (int) $post->ID, 'read', $scope );
            } catch ( Failure $error ) {
                if ( ! in_array( $error->reason, [ 'not_found', 'forbidden' ], true ) ) {
                    throw $error;
                }
            }
        }
        return $allowed;
    }

    /**
     * Project the authorized page.
     *
     * @param array $posts Authorized posts.
     * @param string $scope Projection scope.
     */
    private static function project( array $posts, string $scope ): array {
        return array_map(
            static function ( $post ) use ( $scope ) {
                return Listing_Schema::data( $post, $scope, true );
            }, $posts
        );
    }

    /**
     * Handle list-listings through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_listings( array $args = [] ): array {
        return self::read( 'list-listings', $args );
    }

    /**
     * Handle get-listing through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_listing( array $args = [] ): array {
        return self::read( 'get-listing', $args );
    }

    /**
     * Handle get-listing-schema through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_listing_schema( array $args = [] ): array {
        return self::read( 'get-listing-schema', $args );
    }
}
