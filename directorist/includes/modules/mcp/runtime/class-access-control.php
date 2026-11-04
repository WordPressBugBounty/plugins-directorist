<?php
/**
 * Enforce connection, native capability and object-ownership boundaries.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Runtime;

use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Safety\Read_Guard;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Enforce connection, native capability and object-ownership boundaries. */
class Access_Control {
    /**
     * Transport.
     *
     * @param \WP_REST_Request|null $request Transport request, when supplied by the adapter.
     * @return bool|\WP_Error Authentication decision or existing MCP error.
     */
    public static function transport( $request = null ) {
        try {
            if ( $request instanceof \WP_REST_Request ) {
                \Directorist\MCP\Transport\Origin_Policy::validate( $request );
            }
            self::connected();
            return true;
        } catch ( Failure $error ) {
            return $error->as_error();
        }
    }

    /** Connected. */
    public static function connected(): void {
        Validation::check( Request_Context::enabled(), __( 'Directorist MCP is disabled.', 'directorist' ), 'mcp_disabled' );
        Validation::check( Request_Context::dependencies(), __( 'A compatible WordPress Abilities API and MCP Adapter are required.', 'directorist' ), 'dependency_unavailable' );
        Validation::check( is_user_logged_in(), __( 'Authentication is required.', 'directorist' ), 'unauthenticated' );
        $post = get_post_type_object( ATBDP_POST_TYPE );
        $can  = current_user_can( 'manage_options' ) || ( $post && current_user_can( $post->cap->edit_posts ) );
        foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $taxonomy ) {
            $tax = get_taxonomy( $taxonomy );
            $can = $can || ( $tax && ( current_user_can( $tax->cap->manage_terms ) || current_user_can( $tax->cap->edit_terms ) || current_user_can( $tax->cap->assign_terms ) ) );
        }
        Validation::check( $can || Order_Policy::admission() || Tool_Registry::instance()->admission(), __( 'Your account does not have Directorist access.', 'directorist' ), 'forbidden' );
    }

    /** Admin. */
    public static function admin(): void {
        Validation::check( current_user_can( 'manage_options' ), __( 'You cannot manage Directorist configuration.', 'directorist' ), 'forbidden' );
    }

    /** Listing domain. */
    public static function listing_domain(): void {
        $type = get_post_type_object( ATBDP_POST_TYPE );
        Validation::check( current_user_can( 'manage_options' ) || ( $type && current_user_can( $type->cap->edit_posts ) ), __( 'Listing access is required.', 'directorist' ), 'forbidden' );
    }

    /**
     * Permission.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function permission( $tool, array $args = [] ): bool {
        $definitions = Core_Tool_Provider::definitions();
        $d           = $definitions[ $tool ] ?? [];
        if ( empty( $d['write'] ) && ( in_array( $d['group'] ?? '', [ 'dashboard', 'site-health-info' ], true ) || in_array( $d['domain'] ?? '', [ 'dashboard', 'site_health_info' ], true ) ) ) {
            return Read_Guard::run(
                static function () use ( $tool, $args ) { return self::authorize( $tool, $args );
                }, false, true
            );
        }
        return self::authorize( $tool, $args );
    }

    /**
     * Authorize.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    private static function authorize( string $tool, array $args ): bool {
        self::connected();
        $definitions = Core_Tool_Provider::definitions();
        Validation::check( isset( $definitions[ $tool ] ), __( 'Unsupported tool.', 'directorist' ), 'unsupported_operation' );
        $definition = $definitions[ $tool ];
        if ( ! empty( $definition['write'] ) ) {
            \Directorist\MCP\Safety\Write_Readiness::require_supported( \Directorist\MCP\Safety\Write_Readiness::participants( $definition ) );
        }
        if ( isset( $definition['owner'] ) ) {
            return Tool_Registry::instance()->permission( $tool, $args );
        }
        Tool_Registry::instance()->authorize_core( $tool, $args, 'before' );
        Tool_Registry::instance()->permission( $tool, $args );
        Tool_Registry::instance()->authorize_core( $tool, $args, 'after' );
        return true;
    }

    /**
     * Listing.
     *
     * @param mixed $id Untrusted listing ID; must pass strict positive-integer validation.
     * @param string $action Native object capability action.
     * @param string $scope Requested management or public projection.
     */
    public static function listing( $id, $action = 'read', $scope = 'management' ): \WP_Post {
        $post = get_post( Validation::id( $id ) );
        $can  = $post && ATBDP_POST_TYPE === $post->post_type && current_user_can( $action . '_post', $id );
        if ( 'public' === $scope && 'read' === $action ) {
            $can = $post && ATBDP_POST_TYPE === $post->post_type && self::public_listing( $post );
        } elseif ( 'read' === $action && $post && (int) $post->post_author !== get_current_user_id() ) {
            $type = get_post_type_object( ATBDP_POST_TYPE );
            $can  = $can && current_user_can( $type->cap->edit_others_posts );
        }
        Validation::check( $can, __( 'Listing not found.', 'directorist' ), 'not_found' );
        Validation::check( true === apply_filters( 'directorist_rest_check_permissions', true, $action, (int) $post->ID, ATBDP_POST_TYPE ), __( 'Listing access is restricted.', 'directorist' ), 'forbidden' );
        return $post;
    }

    /**
     * Public listing.
     *
     * @param \WP_Post $post Native listing being checked for public visibility.
     */
    public static function public_listing( $post ): bool {
        // Only the deliberately minimal public projection is exposed; never private custom fields.
        $visible = 'publish' === $post->post_status && ! $post->post_password && ! get_directorist_option( 'disable_single_listing', false );
        return (bool) apply_filters( 'directorist_mcp_public_listing_visible', $visible, $post, get_current_user_id() );
    }

    /**
     * Term cap.
     *
     * @param mixed $taxonomy Taxonomy.
     * @param mixed $action Action.
     */
    public static function term_cap( $taxonomy, $action ): void {
        $taxonomy = Validation::taxonomy( $taxonomy );
        $tax      = get_taxonomy( $taxonomy );
        $map      = [ 'read' => 'manage_terms', 'edit' => 'edit_terms', 'delete' => 'delete_terms', 'assign' => 'assign_terms' ];
        Validation::check(
            isset( $map[ $action ] ) && $tax && current_user_can(
                $tax->cap->{$map[ $action ]
                }
            ), __( 'You cannot perform this taxonomy operation.', 'directorist' ), 'forbidden'
        );
        $context = [ 'read' => 'read', 'edit' => 'edit', 'delete' => 'delete', 'assign' => 'assign' ][ $action ];
        Validation::check( apply_filters( 'directorist_rest_check_permissions', true, $context, 0, $taxonomy ), __( 'Taxonomy access is restricted.', 'directorist' ), 'forbidden' );
    }

    /**
     * Attachment.
     *
     * @param mixed $id Id.
     * @param mixed $image Image.
     */
    public static function attachment( $id, $image = false ): int {
        $file = get_post( Validation::id( $id ) );
        Validation::check( $file && 'attachment' === $file->post_type && current_user_can( 'edit_post', $id ) && ( ! $image || wp_attachment_is_image( $id ) ), __( 'The attachment is unavailable or not permitted.', 'directorist' ) );
        return $id;
    }
}
