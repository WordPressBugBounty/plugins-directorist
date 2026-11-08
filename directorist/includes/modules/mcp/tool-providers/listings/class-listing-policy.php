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
        if ( 'publish' === directorist_resolve_listing_status( 'publish', $directory ) ) {
            $statuses[] = 'publish';
        }
        return $statuses;
    }

    /**
     * Bind previews to the persisted publication policy and current authority.
     *
     * @param int           $directory New listing directory.
     * @param \WP_Post|null $post Existing listing.
     * @param string        $status Effective status.
     * @return array
     */
    public static function publication_state( int $directory, ?\WP_Post $post, string $status ): array {
        $directory = $post ? directorist_get_listing_directory( $post->ID ) : $directory;
        return [
            'directory_id' => $directory,
            'new_status'   => directorist_get_directory_meta( $directory, 'new_listing_status' ),
            'edit_status'  => directorist_get_directory_meta( $directory, 'edit_listing_status' ),
            'can_moderate' => $post && directorist_user_can_moderate_listing( $post->ID ),
            'status'       => $status,
        ];
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
        if ( ! $type || ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( $type->cap->publish_posts ) || ! in_array( $post->post_status, [ 'publish', 'private', 'pending', 'draft', 'rejected', 'expired' ], true ) ) {
            return [];
        }
        $result = [];
        foreach ( [ 'publish', 'private' ] as $status ) {
            // Adding publication sources must not expand private-status eligibility.
            if ( 'private' === $status && in_array( $post->post_status, [ 'rejected', 'expired' ], true ) ) {
                continue;
            }
            try {
                self::policy( $post, directorist_get_listing_directory( $post->ID ), $status );
                $result[] = directorist_resolve_listing_status( $status, 0, $post->ID, true );
            } catch ( Failure $error ) {
                /* Not advertised. */
            }
        }
        return array_values( array_unique( $result ) );
    }
}
