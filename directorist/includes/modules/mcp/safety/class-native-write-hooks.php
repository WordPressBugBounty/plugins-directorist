<?php
/**
 * Native WordPress and Directorist writes participating in MCP serialization.
 *
 * @package Directorist\MCP
 */
namespace Directorist\MCP\Safety;

use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Runtime\Request_Context;

defined( 'ABSPATH' ) || exit;

/** Named hook callbacks keep write coordination separate from module startup. */
class Native_Write_Hooks {
    /** Register at the same priorities as the original module. */
    public static function register_hooks(): void {
        foreach ( [ 'directorist_before_order_create', 'directorist_mcp_before_native_order_write', 'directorist_mcp_before_native_payment_write', 'directorist_mcp_before_native_refund_write', 'wp_ajax_cptm_save_post_type_data', 'wp_ajax_save_post_type_data', 'admin_post_delete_listing_type', 'directorist_checkout_validation' ] as $hook ) {
            add_action( $hook, [ Write_Lock::class, 'native_lock' ], 0 );
        }
        add_filter( 'query', [ self::class, 'order_query' ], 0 );
        add_filter( 'rest_dispatch_request', [ self::class, 'rest_write' ], 0, 3 );
        add_filter( 'wp_insert_post_data', [ self::class, 'listing_write' ], 1 );
        add_action( 'add_term_relationship', [ self::class, 'term_relationship' ], 0, 3 );
        foreach ( [ 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ] as $hook ) {
            add_filter( $hook, [ self::class, 'listing_metadata' ], 0, 2 );
        }
        foreach ( [ 'add_term_metadata', 'update_term_metadata', 'delete_term_metadata' ] as $hook ) {
            add_filter( $hook, [ self::class, 'term_metadata' ], 0, 3 );
        }
        foreach ( [ 'edit_terms', 'pre_delete_term' ] as $hook ) {
            add_action( $hook, [ self::class, 'term_write' ], 0, 2 );
        }
        add_filter( 'pre_insert_term', [ self::class, 'term_insert' ], 0, 2 );
    }

    /**
     * Lock native order-table writes before SQL execution.
     *
     * @param string $sql Sql.
     */
    public static function order_query( string $sql ): string {
        if ( preg_match( '/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match ) && in_array( $match[1], Order_Policy::tables(), true ) ) {
            Write_Lock::native_lock();
        }
        return $sql;
    }

    /**
     * Lock authorized native REST writes before they read mutable state.
     *
     * @param mixed $result Existing REST short-circuit value, passed through unchanged.
     * @param \WP_REST_Request $request Request.
     * @param string $route Route.
     * @return mixed Original REST short-circuit value.
     */
    public static function rest_write( $result, \WP_REST_Request $request, string $route ) {
        if ( null === $result && ! in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true ) && preg_match( '#^/directorist/v[12]/(?:orders?|admin/payments|checkout)(?:/|$)#', $route ) ) {
            Write_Lock::native_lock();
        }
        return $result;
    }

    /**
     * Coordinate listing changes with dependency checks.
     *
     * @param array $data Data.
     */
    public static function listing_write( array $data ): array {
        if ( ATBDP_POST_TYPE === ( $data['post_type'] ?? '' ) ) {
            Write_Lock::native_lock();
        }
        return $data;
    }

    /**
     * Reject stale taxonomy relationships after obtaining the native lock.
     *
     * @param int $object_id Object id.
     * @param int $term_taxonomy_id Term taxonomy id.
     * @param string $taxonomy Taxonomy.
     */
    public static function term_relationship( int $object_id, int $term_taxonomy_id, string $taxonomy ): void {
        if ( in_array( $taxonomy, self::taxonomies(), true ) ) {
            Write_Lock::native_lock();
            global $wpdb;
            if ( Request_Context::enabled() && ! $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $term_taxonomy_id ) ) ) {
                wp_die( esc_html__( 'The directory taxonomy changed. Refresh the page before assigning this term.', 'directorist' ), '', [ 'response' => 409 ] );
            }
        }
    }

    /**
     * Coordinate listing metadata writes while preserving native short circuits.
     *
     * @param mixed $check Native metadata short-circuit value, passed through unchanged.
     * @param int $id Id.
     * @return mixed Original metadata short-circuit value.
     */
    public static function listing_metadata( $check, int $id ) {
        if ( ATBDP_POST_TYPE === get_post_type( $id ) ) {
            Write_Lock::native_lock();
        }
        return $check;
    }

    /**
     * Coordinate directory configuration and association metadata.
     *
     * @param mixed $check Native metadata short-circuit value, passed through unchanged.
     * @param int $id Id.
     * @param string $key Key.
     * @return mixed Original metadata short-circuit value.
     */
    public static function term_metadata( $check, int $id, string $key ) {
        $term = get_term( $id );
        if ( $term && ! is_wp_error( $term ) && ( ATBDP_DIRECTORY_TYPE === $term->taxonomy || 0 === strpos( $key, '_directory_type' ) ) ) {
            Write_Lock::native_lock();
        }
        return $check;
    }

    /**
     * Coordinate native taxonomy edits and deletions.
     *
     * @param int $id Id.
     * @param string $taxonomy Taxonomy.
     */
    public static function term_write( int $id, string $taxonomy ): void {
        if ( in_array( $taxonomy, self::taxonomies(), true ) ) {
            Write_Lock::native_lock();
        }
    }

    /**
     * Coordinate native taxonomy creation without changing the proposed name.
     *
     * @param string|\WP_Error $term Native proposed term or existing validation error.
     * @param string $taxonomy Taxonomy.
     * @return string|\WP_Error Original proposed term or error.
     */
    public static function term_insert( $term, string $taxonomy ) {
        self::term_write( 0, $taxonomy );
        return $term;
    }

    /** Return native taxonomies participating in MCP dependency checks. */
    private static function taxonomies(): array {
        return [ ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ];
    }
}
