<?php
/**
 * Guidance for dashboard guidance.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Dashboard;

use Directorist\MCP\Extensions\Read_Contributions;
use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Guidance. */
class Guidance {
    /**
     * Read.
     *
     * @param array $args Validated tool input.
     * @param array $contributions Contributions.
     */
    public static function read( array $args, array $contributions ): array {
        $site       = 'site' === $args['scope'];
        $extensions = array_filter(
            $contributions, static function ( $d ) { return 'guidance' === $d['kind'];
            }
        );
        $allowed    = array_merge( $site ? [ 'setup', 'quick_actions', 'recommendations' ] : [ 'quick_actions' ], array_keys( $extensions ) );
        $selected   = Read_Support::selected( $args, 'sections', $site ? [ 'setup', 'quick_actions', 'recommendations' ] : [ 'quick_actions' ], $allowed, 8 );
        Validation::check( ! isset( $args['directory_id'] ) || ! array_intersect( $selected, [ 'setup', 'recommendations' ] ), __( 'Only quick actions support a directory filter.', 'directorist' ), 'unsupported_operation' );
        $result = [];
        foreach ( $selected as $key ) {
            if ( isset( $extensions[ $key ] ) ) {
                $result[ $key ] = Read_Contributions::read( $extensions[ $key ], $args );
            } elseif ( 'recommendations' === $key ) {
                $result[ $key ] = Read_Support::section( null, 'registered_public_recommendations', 'unavailable', 'no_compatible_registered_source' );
            } elseif ( 'setup' === $key ) {
                $result[ $key ] = self::setup( $args );
            } else {
                $result[ $key ] = self::actions( $args );
            }
        }
        return $result;
    }

    /**
     * Setup.
     *
     * @param array $args Validated tool input.
     */
    private static function setup( array $args ): array {
        if ( ! class_exists( '\ATBDP_Extension_Activity' ) ) {
            return Read_Support::section( null, 'native_setup', 'unavailable', 'source_unavailable' );
        }
        // The public setup method only evaluates local predicates; never call get_dashboard_metrics or page rendering.
        $metrics = Listing_Data::listings( $args, Read_Callbacks::range( [] ) );
        $data    = ( new \ATBDP_Extension_Activity() )->get_dashboard_setup_details( [ 'published_listings' => $metrics['counts']['publish'] ] );
        $steps   = [];
        foreach ( $data['steps'] ?? [] as $index => $step ) {
            $url   = Read_Support::safe_url( $step['url'] ?? '' );
            $label = Read_Support::safe_value( $step['label'] ?? '' );
            if ( $url && $label ) {
                $steps[] = [ 'key' => 'setup-' . $index, 'label' => $label, 'complete' => ! empty( $step['complete'] ), 'url' => $url ];
            }
        }
        return Read_Support::section( [ 'visible' => ! empty( $data['is_visible'] ) && ! get_user_meta( get_current_user_id(), 'directorist_te_dashboard_checklist_dismissed', true ), 'dismissed' => (bool) get_user_meta( get_current_user_id(), 'directorist_te_dashboard_checklist_dismissed', true ), 'progress' => (int) ( $data['progress'] ?? 0 ), 'steps' => $steps, 'meaning' => 'native_configuration_predicates_not_gateway_verification' ], 'native_setup' );
    }

    /**
     * Action.
     *
     * @param string $key Stable guidance action key.
     * @param string $label Public action label.
     * @param string|false $url Native permalink or URL to validate before projection.
     * @param string|null $tool Optional registered tool reference.
     */
    private static function action( string $key, string $label, $url, ?string $tool = null ): ?array {
        $safe = Read_Support::safe_url( $url );
        return $safe ? [ 'key' => $key, 'label' => $label, 'url' => $safe, 'tool' => Read_Support::tool_reference( $tool ) ] : null;
    }

    /**
     * Actions.
     *
     * @param array $args Validated tool input.
     */
    private static function actions( array $args ): array {
        $items = [];
        $type  = get_post_type_object( ATBDP_POST_TYPE );
        if ( 'own' === $args['scope'] ) {
            Validation::check( ! isset( $args['directory_id'] ), __( 'Own quick-action navigation has no directory allocation.', 'directorist' ), 'unsupported_operation' );
            $page = (int) get_directorist_option( 'dashboard_page', 0 );
            $url  = $page > 0 ? get_permalink( $page ) : home_url( '/' );
            if ( Read_Callbacks::listing_access() ) {
                $items[] = self::action( 'my-listings', 'My listings', $url, 'directorist-list-listings' );
            }
            if ( Order_Policy::admission() ) {
                $items[] = self::action( 'my-orders', 'My purchase history', $url, 'directorist-list-orders' );
            }
        } else {
            $query = [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC' ];
            if ( isset( $args['directory_id'] ) ) {
                $query['include'] = [ $args['directory_id'] ];
            }
            list( $page, $size ) = Pagination::paging( $args );
            $total               = Validation::unwrap( wp_count_terms( $query ) );
            $directories         = Validation::unwrap( get_terms( $query + [ 'number' => $size, 'offset' => ( $page - 1 ) * $size ] ) );
            foreach ( $directories as $directory ) {
                $id      = (int) $directory->term_id;
                $actions = [];
                if ( $type && current_user_can( $type->cap->create_posts ) ) {
                    $actions[] = self::action( 'add-listing', 'Add listing', add_query_arg( [ 'post_type' => ATBDP_POST_TYPE, 'directory_type' => $id ], admin_url( 'post-new.php' ) ), 'directorist-create-listing' );
                }
                $taxonomy = get_taxonomy( ATBDP_CATEGORY );
                if ( $taxonomy && current_user_can( $taxonomy->cap->manage_terms ) ) {
                    $actions[] = self::action( 'manage-categories', 'Manage categories', add_query_arg( [ 'taxonomy' => ATBDP_CATEGORY, 'post_type' => ATBDP_POST_TYPE, 'directory_type' => $id ], admin_url( 'edit-tags.php' ) ), 'directorist-list-terms' );
                }
                $builder = [ 'post_type' => ATBDP_POST_TYPE, 'page' => directorist_is_multi_directory_enabled() ? 'atbdp-directory-types' : 'atbdp-layout-builder' ];
                if ( directorist_is_multi_directory_enabled() ) {
                    $builder += [ 'listing_type_id' => $id, 'action' => 'edit' ];
                }
                $actions[] = self::action( 'listing-layout', 'Customize listing layout', add_query_arg( $builder, admin_url( 'edit.php' ) ) . '#single_page_layout__contents', 'directorist-get-builder-schema' );
                $items[]   = [ 'directory_id' => $id, 'label' => sanitize_text_field( $directory->name ), 'actions' => array_values( array_filter( $actions ) ) ];
            }
            $global_actions = [ self::action( 'create-directory', 'Create or configure directory', admin_url( 'edit.php?post_type=at_biz_dir&page=' . ( directorist_is_multi_directory_enabled() ? 'atbdp-directory-types&action=add_new' : 'atbdp-layout-builder' ) ), 'directorist-create-directory' ), self::action( 'email-notifications', 'Email notifications', admin_url( 'edit.php?post_type=at_biz_dir&page=atbdp-settings' ) . '#email_settings', 'directorist-discover-settings' ) ];
            return Read_Support::section( Pagination::collection( $items, $total, $page, $size ) + [ 'global_actions' => array_values( array_filter( $global_actions ) ) ], 'native_directory_navigation' );
        }
        return Read_Support::section( Pagination::page( array_values( array_filter( $items ) ), $args ), 'native_own_navigation' );
    }
}
