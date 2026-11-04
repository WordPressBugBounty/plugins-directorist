<?php
/**
 * Listing Policy for listings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Listings;

use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Listing Policy. */
class Listing_Policy {
    /**
     * Initial statuses.
     *
     * @param int $directory Directory.
     */
    public static function initial_statuses( int $directory ): array {
        $statuses = [ 'draft', 'pending' ];
        $type     = get_post_type_object( ATBDP_POST_TYPE );
        if ( 'publish' === directorist_get_listing_create_status( $directory ) && current_user_can( $type->cap->publish_posts ) ) {
            $statuses[] = 'publish';
        }
        return $statuses;
    }

    /**
     * Policy.
     *
     * @param \WP_Post|null $post Post.
     * @param int $directory Directory.
     * @param string $operation Operation.
     * @param array $data Data.
     */
    public static function policy( ?\WP_Post $post, int $directory, string $operation, array $data = [] ): void {
        $allowed = true;
        if ( function_exists( 'is_fee_manager_active' ) && is_fee_manager_active() ) {
            $allowed = false;
        }
        if ( $post && 'publish' === $operation ) {
            $expiry = get_post_meta( $post->ID, '_expiry_date', true );
            if ( $expiry && ! get_post_meta( $post->ID, '_never_expire', true ) && strtotime( $expiry ) < time() ) {
                $allowed = false;
            }
            if ( get_post_meta( $post->ID, '_featured', true ) && function_exists( 'directorist_is_monetization_enabled' ) && directorist_is_monetization_enabled() ) {
                $allowed = $allowed && in_array( \Directorist\Helper::get_listing_payment_status( $post->ID ), [ 'completed', 'paid' ], true );
            }
        }
        $allowed = apply_filters( 'directorist_mcp_listing_policy', $allowed, $post, $directory, $operation, $data );
        Validation::unwrap( $allowed );
        Validation::check( true === $allowed, __( 'A listing or entitlement policy blocks this operation. Use the native workflow or a registered policy integration.', 'directorist' ), 'dependency_blocked' );
    }

    /**
     * Transitions.
     *
     * @param \WP_Post $post Post.
     */
    public static function transitions( \WP_Post $post ): array {
        $type = get_post_type_object( ATBDP_POST_TYPE );
        if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( $type->cap->publish_posts ) || ! in_array( $post->post_status, [ 'publish', 'private', 'pending', 'draft' ], true ) ) {
            return [];
        }
        $result = [];
        foreach ( [ 'publish', 'private' ] as $status ) {
            try {
                self::policy( $post, Listing_Schema::directory( $post->ID ), $status );
                $result[] = $status;
            } catch ( Failure $error ) {
                /* Not advertised. */
            }
        }
        return $result;
    }
}
