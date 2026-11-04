<?php
/**
 * Write Callbacks for listings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Listings;

use Directorist\MCP\Tool_Providers\Taxonomies\Term_Validator;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Write Callbacks. */
class Write_Callbacks {
    /**
     * Prepare.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function prepare( string $tool, array $args ): array {
        $create    = 'create-listing' === $tool;
        $post      = $create ? null : Access_Control::listing( $args['listing_id'], in_array( $tool, [ 'trash-listing', 'restore-listing' ], true ) ? 'delete' : 'edit' );
        $directory = $create ? Validation::directory( $args['directory_id'] )->term_id : Listing_Schema::directory( $post->ID );
        $version   = $post ? Listing_Schema::version( $post ) : Listing_Schema::schema( [ 'directory_id' => $directory ] )['schema_version'];
        if ( $post && empty( $args['dry_run'] ) ) {
            Validation::version( $args['expected_version'] ?? null, $version );
        }
        if ( isset( $args['schema_version'] ) ) {
            Validation::version( $args['schema_version'], Listing_Schema::schema( [ 'directory_id' => $directory ] )['schema_version'] );
        }
        $id = $post ? (int) $post->ID : 0;
        if ( in_array( $tool, [ 'trash-listing', 'restore-listing', 'change-listing-status' ], true ) ) {
            return self::lifecycle( $tool, $args, $post, $version );
        }
        if ( $create ) {
            $type = get_post_type_object( ATBDP_POST_TYPE );
            Validation::check( current_user_can( $type->cap->create_posts ) && \ATBDP_Add_Listing::current_user_can_create(), __( 'You cannot submit listings.', 'directorist' ), 'forbidden' );
        } else {
            Validation::check( 'trash' !== $post->post_status, __( 'Restore this listing before editing it.', 'directorist' ) );
        }
        $definitions = Listing_Schema::definitions( $directory );
        $changes     = Validation::object( $create ? $args['fields'] : $args['changes'], 'fields' );
        Validation::allowed( $changes, array_keys( $definitions ) );
        $values = $post ? Listing_Schema::field_values( $post, $definitions ) : [];
        foreach ( $changes as $key => $value ) {
            $definition = $definitions[ $key ];
            Validation::check( $definition['writable'], __( 'This field is read-only.', 'directorist' ), 'unsupported_operation' );
            if ( in_array( $key, [ 'categories', 'locations', 'tags' ], true ) && ( is_object( $value ) || ( is_array( $value ) && ( isset( $value['add'] ) || isset( $value['remove'] ) || isset( $value['replace'] ) ) ) ) ) {
                $ops = Validation::object( $value );
                Validation::allowed( $ops, [ 'add', 'remove', 'replace' ] );
                Validation::check( ! isset( $ops['replace'] ) || 1 === count( $ops ), __( 'Do not combine replace with add/remove.', 'directorist' ) );
                $value = isset( $ops['replace'] ) ? Validation::ids( $ops['replace'] ) : array_values( array_diff( array_unique( array_merge( $values[ $key ] ?? [], Validation::ids( $ops['add'] ?? [] ) ) ), Validation::ids( $ops['remove'] ?? [] ) ) );
            }
            $schema = $definition['schema'];
            if ( '' === $value && ! $definition['required'] ) {
                unset( $schema['format'] );
            }
            Validation::unwrap( rest_validate_value_from_schema( $value, $schema, $key ) );
            if ( 'file' === ( $definition['props']['type'] ?? '' ) || 'file' === $definition['widget'] ) {
                $value = Listing_Schema::file_reference( $value, $definition['props'] );
            }
            $values[ $key ] = json_decode( wp_json_encode( $value ), true );
        }
        $data = [ 'directory_type' => $directory, 'directory_id' => $directory ];
        if ( $id ) {
            $data['listing_id'] = $id;
        }
        foreach ( $definitions as $key => $definition ) {
            $value = $values[ $key ] ?? null;
            if ( null === $value ) {
                continue;
            }
            $widget = $definition['widget'];
            if ( in_array( $widget, [ 'category', 'location', 'tag' ], true ) ) {
                $taxonomy = [ 'category' => ATBDP_CATEGORY, 'location' => ATBDP_LOCATION, 'tag' => ATBDP_TAGS ][ $widget ];
                if ( isset( $changes[ $key ] ) ) {
                    Access_Control::term_cap( $taxonomy, 'assign' );
                }
                $ids = Validation::ids( $value );
                foreach ( $ids as $term_id ) {
                    Term_Validator::term( $taxonomy, $term_id );
                    Validation::check( in_array( $directory, Term_Validator::scope( $term_id ), true ), __( 'A selected term belongs to a different directory.', 'directorist' ) );
                }
                $data['tax_input'][ $taxonomy ] = $ids;
            } elseif ( 'image_upload' === $widget ) {
                if ( isset( $changes[ $key ] ) ) {
                    foreach ( Validation::ids( $value ) as $attachment ) {
                        Access_Control::attachment( $attachment, true );
                    }
                }
                $data[ $definition['native_key'] ] = $value;
            } elseif ( 'map' === $widget ) {
                $value = Validation::object( $value );
                Validation::allowed( $value, [ 'hide_map', 'manual_lat', 'manual_lng' ] );
                foreach ( [ 'manual_lat' => 90, 'manual_lng' => 180 ] as $coord => $limit ) {
                    if ( isset( $value[ $coord ] ) && '' !== $value[ $coord ] ) {
                        Validation::check( is_numeric( $value[ $coord ] ) && abs( $value[ $coord ] ) <= $limit, __( 'Invalid map coordinate.', 'directorist' ) );
                    }
                }
                $data = array_merge( $data, $value );
            } elseif ( 'pricing' === $widget ) {
                $value = Validation::object( $value );
                Validation::allowed( $value, [ 'price_type', 'price', 'price_range' ] );
                $data['atbd_listing_pricing'] = $value['price_type'] ?? '';
                $data['price']                = $value['price'] ?? 0;
                $data['price_range']          = $value['price_range'] ?? '';
            } elseif ( 'social_info' === $widget ) {
                $data['social'] = $value;
            } else {
                $data[ $definition['native_key'] ] = $value;
            }
        }
        $consents = Validation::object( $args['consents'] ?? [] );
        Validation::allowed( $consents, [ 'privacy_policy', 'terms_conditions' ] );
        foreach ( [ 'privacy_policy' => 'privacy_policy', 'terms_conditions' => 't_c_check' ] as $key => $native ) {
            if ( isset( $consents[ $key ] ) ) {
                Validation::check( is_bool( $consents[ $key ] ), __( 'Consent values must be explicit booleans.', 'directorist' ) );
                $data[ $native ] = $consents[ $key ];
            } elseif ( $post && get_post_meta( $post->ID, 'privacy_policy' === $key ? '_privacy_policy' : '_t_c_check', true ) ) {
                $data[ $native ] = true;
            }
        }
        Listing_Policy::policy( $post, $directory, $create ? 'create' : 'edit', $data );
        Submission_Adapter::validate_only( $data, $directory );
        $status = $create ? ( $args['status'] ?? directorist_get_listing_create_status( $directory ) ) : directorist_get_listing_edit_status( $directory, $id );
        if ( $create ) {
            if ( ! isset( $args['status'] ) && ! in_array( $status, Listing_Policy::initial_statuses( $directory ), true ) ) {
                $status = 'pending';
            }
            Validation::check( in_array( $status, Listing_Policy::initial_statuses( $directory ), true ), __( 'This initial publication status is not allowed.', 'directorist' ), 'forbidden' );
        }
        return [
            'state'   => [ $version, $directory, $status ],
            'confirm' => $create ? 'publish' === $status : $status !== $post->post_status,
            'preview' => [ 'listing_id' => $id ?: null, 'directory_id' => $directory, 'fields' => $changes, 'effective_status' => $status, 'version' => $version ],
            'execute' => static function () use ( $data, $create, $status, $id, $values, $definitions, $changes ) {
                $status_filter = static function () use ( $status ) {
                    return $status;
                };
                if ( $create ) {
                    add_filter( 'directorist_listing_create_status', $status_filter, PHP_INT_MAX );
                }
                $old_images = $id ? atbdp_get_listing_attachment_ids( $id ) : [];
                // Native submission historically deletes deselected files; MCP changes references only.
                $protect = static function ( $delete, $attachment ) use ( $old_images ) {
                    return in_array( (int) $attachment->ID, array_map( 'intval', $old_images ), true ) ? false : $delete;
                };
                add_filter( 'pre_delete_attachment', $protect, PHP_INT_MAX, 2 );
                try {
                    $result = Validation::unwrap( Submission_Adapter::apply( $data ) );
                } finally {
                    remove_filter( 'pre_delete_attachment', $protect, PHP_INT_MAX );
                    if ( $create ) {
                        remove_filter( 'directorist_listing_create_status', $status_filter, PHP_INT_MAX );
                    }
                }
                $new_id = (int) ( $result['id'] ?? 0 );
                Validation::check( $new_id && ATBDP_POST_TYPE === get_post_type( $new_id ), __( 'The listing save could not be verified.', 'directorist' ), 'operation_failed' );
                if ( isset( $definitions['images'], $values['images'] ) && array_key_exists( 'images', $changes ) ) {
                    $images = $values['images'];
                    if ( $images ) {
                        set_post_thumbnail( $new_id, $images[0] );
                        update_post_meta( $new_id, '_listing_prv_img', $images[0] );
                        update_post_meta( $new_id, '_listing_img', array_slice( $images, 1 ) );
                    } else {
                        delete_post_thumbnail( $new_id );
                        delete_post_meta( $new_id, '_listing_prv_img' );
                        delete_post_meta( $new_id, '_listing_img' );
                    }
                }
                return Result::result( [ 'listing_id' => $new_id, 'status' => get_post_status( $new_id ), 'version' => Listing_Schema::version( get_post( $new_id ) ) ] );
            }
        ];
    }

    /**
     * Lifecycle.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param \WP_Post $post Post.
     * @param string $version Version.
     */
    public static function lifecycle( string $tool, array $args, \WP_Post $post, string $version ): array {
        $id = (int) $post->ID;
        if ( 'change-listing-status' === $tool ) {
            $status = $args['status'];
            Validation::check( in_array( $status, Listing_Policy::transitions( $post ), true ), __( 'This status transition is not permitted.', 'directorist' ), 'forbidden' );
        } elseif ( 'trash-listing' === $tool ) {
            Validation::check( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0, __( 'Trash is disabled; permanent deletion is not supported.', 'directorist' ), 'unsupported_operation' );
            Validation::check( 'trash' !== $post->post_status, __( 'This listing is already in Trash.', 'directorist' ) );
            $status = 'trash';
        } else {
            Access_Control::listing( $id, 'edit' );
            Validation::check( 'trash' === $post->post_status, __( 'Only trashed listings can be restored.', 'directorist' ) );
            $status = 'draft';
        }
        if ( 'trash' !== $status ) {
            Listing_Policy::policy( $post, Listing_Schema::directory( $id ), $status );
        }
        return [
            'state'   => $version,
            'confirm' => true,
            'preview' => [ 'listing_id' => $id, 'before' => $post->post_status, 'after' => $status, 'version' => $version ],
            'execute' => static function () use ( $tool, $id, $status ) {
                if ( 'trash-listing' === $tool ) {
                    $result = wp_trash_post( $id );
                } elseif ( 'restore-listing' === $tool ) {
                    $result = wp_untrash_post( $id );
                } else {
                    do_action( 'directorist_before_update_listing_status', $id, $status );
                    $result = wp_update_post( [ 'ID' => $id, 'post_status' => $status ], true );
                }
                Validation::unwrap( $result );
                Validation::check( (bool) $result, __( 'The listing lifecycle update failed.', 'directorist' ), 'operation_failed' );
                Validation::check( $status === get_post_status( $id ), __( 'The native workflow changed the reviewed listing status. Preview again using the native publication requirements.', 'directorist' ), 'state_changed' );
                return Result::result( [ 'listing_id' => $id, 'status' => get_post_status( $id ), 'version' => Listing_Schema::version( get_post( $id ) ) ] );
            }
        ];
    }

    /**
     * Handle create-listing through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function create_listing( array $args = [] ): array {
        return self::prepare( 'create-listing', $args );
    }

    /**
     * Handle update-listing through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function update_listing( array $args = [] ): array {
        return self::prepare( 'update-listing', $args );
    }

    /**
     * Handle change-listing-status through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function change_listing_status( array $args = [] ): array {
        return self::prepare( 'change-listing-status', $args );
    }

    /**
     * Handle trash-listing through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function trash_listing( array $args = [] ): array {
        return self::prepare( 'trash-listing', $args );
    }

    /**
     * Handle restore-listing through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function restore_listing( array $args = [] ): array {
        return self::prepare( 'restore-listing', $args );
    }
}
