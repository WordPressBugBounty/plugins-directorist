<?php
/**
 * Listing Schema for listings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Listings;

use Directorist\Fields\Fields;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Listing Schema. */
class Listing_Schema {
    /**
     * Directory.
     *
     * @param int $id Id.
     */
    public static function directory( int $id ): int {
        $ids = wp_get_object_terms( $id, ATBDP_DIRECTORY_TYPE, [ 'fields' => 'ids' ] );
        return (int) ( is_array( $ids ) && $ids ? $ids[0] : directorist_get_default_directory() );
    }

    /**
     * Definitions.
     *
     * @param mixed $directory Directory.
     */
    public static function definitions( $directory ): array {
        Validation::directory( $directory );
        $definitions = [];
        $aliases     = [ 'title' => 'title', 'description' => 'description', 'excerpt' => 'excerpt', 'category' => 'categories', 'location' => 'locations', 'tag' => 'tags', 'image_upload' => 'images', 'social_info' => 'social' ];
        foreach ( directorist_get_listing_form_fields( $directory ) as $props ) {
            $field = Fields::create( $props );
            if ( $field->is_admin_only() || ! $field->get_key() ) {
                continue;
            }
            $widget    = $props['widget_name'] ?? '';
            $supported = Fields::exists( Fields::translate_key_to_field( $widget ) ) && 0 === strpos( get_class( $field ), 'Directorist\\Fields\\' );
            $key       = $aliases[ $widget ] ?? $field->get_key();
            $schema    = [ 'type' => 'string' ];
            if ( in_array( $widget, [ 'category', 'location', 'tag', 'image_upload' ], true ) ) {
                $schema = [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'maxItems' => 100 ];
            } elseif ( in_array( $field->type, [ 'checkbox', 'social_info' ], true ) ) {
                $schema = [ 'type' => 'array' ];
            } elseif ( in_array( $widget, [ 'map', 'pricing' ], true ) ) {
                $schema = [ 'type' => 'object' ];
            } elseif ( 'number' === $field->type ) {
                $schema = [ 'type' => 'number' ];
            } elseif ( 'switch' === $field->type ) {
                $schema = [ 'type' => 'boolean' ];
            } elseif ( 'file' === $field->type ) {
                $schema = [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Existing authorized attachment ID; 0 removes only the reference. Reads return the stored file URL.' ];
            } elseif ( 'email' === $field->type ) {
                $schema['format'] = 'email';
            } elseif ( 'url' === $field->type ) {
                $schema['format'] = 'uri';
            }
            if ( in_array( $field->type, [ 'select', 'radio' ], true ) ) {
                $options = $props['options'] ?? [];
                $choices = [];
                foreach ( $options as $option ) {
                    if ( is_array( $option ) && isset( $option['option_value'] ) ) {
                        $choices[] = $option['option_value'];
                    } elseif ( is_array( $option ) && isset( $option['value'] ) ) {
                        $choices[] = $option['value'];
                    }
                }
                if ( $choices ) {
                    $schema['enum'] = $choices;
                }
            }
            $definitions[ $key ] = [
                'key'               => $key,
                'native_key'        => $field->get_key(),
                'widget'            => $widget,
                'label'             => $props['label'] ?? $key,
                'required'          => $field->is_required(),
                'schema'            => $schema,
                'writable'          => $supported,
                'conditional_logic' => $props['conditional_logic'] ?? [],
                'props'             => $props
            ];
        }
        return $definitions;
    }

    /**
     * Schema.
     *
     * @param array $args Validated tool input.
     */
    public static function schema( array $args ): array {
        $directory = Validation::directory( $args['directory_id'] )->term_id;
        $type      = get_post_type_object( ATBDP_POST_TYPE );
        if ( isset( $args['listing_id'] ) ) {
            Access_Control::listing( $args['listing_id'], 'edit' );
            Validation::check( self::directory( $args['listing_id'] ) === $directory, __( 'The listing belongs to a different directory.', 'directorist' ) );
        } else {
            Validation::check( current_user_can( $type->cap->create_posts ), __( 'You cannot create listings.', 'directorist' ), 'forbidden' );
        }
        $fields = self::definitions( $directory );
        foreach ( $fields as &$field ) {
            unset( $field['props'] );
        }
        return [
            'directory_id'      => $directory,
            'fields'            => array_values( $fields ),
            'schema_version'    => Canonical_Data::hash( $fields ),
            'required_consents' => [ 'privacy_policy' => directorist_should_check_privacy_policy( $directory ), 'terms_conditions' => directorist_should_check_terms_and_condition( $directory ) ],
            'initial_statuses'  => Listing_Policy::initial_statuses( $directory ),
            'limits'            => [ 'bulk' => 50 ],
            'media'             => [ 'existing_attachments_only' => true ],
            'notes'             => __( 'Use assignment reference lookups for taxonomy IDs. Paid entitlements and ownership are read-only.', 'directorist' )
        ];
    }

    /**
     * Version.
     *
     * @param \WP_Post $post Post.
     */
    public static function version( \WP_Post $post ): string {
        return Canonical_Data::hash(
            [ $post->to_array(), get_post_meta( $post->ID ), wp_get_object_terms( $post->ID, [ ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], [ 'fields' => 'ids' ] ),
                get_term_meta( self::directory( $post->ID ), 'submission_form_fields', true )
            ]
        );
    }

    /**
     * Field values.
     *
     * @param \WP_Post $post Post.
     * @param array $definitions Definitions.
     */
    public static function field_values( \WP_Post $post, array $definitions ): array {
        $values = [];
        foreach ( $definitions as $key => $definition ) {
            switch ( $definition['widget'] ) {
                case 'title': $value = $post->post_title;
                break;
                case 'description': $value = $post->post_content;
                break;
                case 'excerpt': $value = $post->post_excerpt;
                break;
                case 'category': case 'location': case 'tag':
                            $map   = [ 'category' => ATBDP_CATEGORY, 'location' => ATBDP_LOCATION, 'tag' => ATBDP_TAGS ];
                            $value = array_map( 'intval', wp_get_object_terms( $post->ID, $map[ $definition['widget'] ], [ 'fields' => 'ids' ] ) );
                break;
                case 'image_upload': $value = array_values( array_filter( array_map( 'intval', atbdp_get_listing_attachment_ids( $post->ID ) ) ) );
                break;
                case 'pricing': $value = [ 'price_type' => get_post_meta( $post->ID, '_atbd_listing_pricing', true ), 'price' => (float) get_post_meta( $post->ID, '_price', true ), 'price_range' => get_post_meta( $post->ID, '_price_range', true ) ];
                break;
                case 'map': $value = [ 'hide_map' => (bool) get_post_meta( $post->ID, '_hide_map', true ), 'manual_lat' => get_post_meta( $post->ID, '_manual_lat', true ), 'manual_lng' => get_post_meta( $post->ID, '_manual_lng', true ) ];
                break;
                case 'social_info': $value = get_post_meta( $post->ID, '_social', true );
                    if ( is_string( $value ) ) {
                        $value = json_decode( $value, true ) ?: [];
                    }
                break;
                default: $value = get_post_meta( $post->ID, '_' . $definition['native_key'], true );
            }
            $values[ $key ] = $value;
        }
        return $values;
    }

    /**
     * Data.
     *
     * @param \WP_Post $post Post.
     * @param string $scope Scope.
     * @param bool $summary Summary.
     */
    public static function data( \WP_Post $post, string $scope = 'management', bool $summary = false ): array {
        $data = [
            'id'            => (int) $post->ID,
            'title'         => $post->post_title,
            'status'        => $post->post_status,
            'directory_id'  => self::directory( $post->ID ),
            'url'           => get_permalink( $post ),
            'date_created'  => $post->post_date_gmt,
            'date_modified' => $post->post_modified_gmt
        ];
        if ( 'public' !== $scope ) {
            $data['author_id'] = (int) $post->post_author;
            if ( ! $summary ) {
                $data['fields']           = self::field_values( $post, self::definitions( $data['directory_id'] ) );
                $data['version']          = self::version( $post );
                $data['allowed_statuses'] = Listing_Policy::transitions( $post );
                $data['featured']         = (bool) get_post_meta( $post->ID, '_featured', true );
                $data['expiry_date']      = get_post_meta( $post->ID, '_expiry_date', true );
            }
        }
        return $data;
    }

    /**
     * File reference.
     *
     * @param mixed $id Id.
     * @param array $props Props.
     */
    public static function file_reference( $id, array $props ): string {
        if ( 0 === $id ) {
            return '';
        }
        Access_Control::attachment( $id );
        $field = Fields::create( $props );
        $path  = get_attached_file( $id );
        Validation::check( $path && is_file( $path ) && is_readable( $path ), __( 'The attachment file is unavailable.', 'directorist' ) );
        $types  = $field->get_file_types();
        $groups = directorist_get_supported_file_types_groups();
        $types  = in_array( $types, [ '', 'all', 'all_types' ], true ) ? directorist_get_supported_file_types() : ( $groups[ $types ] ?? (array) $types );
        $check  = wp_check_filetype_and_ext( $path, wp_basename( $path ), get_allowed_mime_types() );
        Validation::check( ! empty( $check['ext'] ) && in_array( $check['ext'], $types, true ), __( 'The attachment type is not allowed for this field.', 'directorist' ) );
        Validation::check( ! $field->get_file_size() || filesize( $path ) <= $field->get_file_size(), __( 'The attachment exceeds this field size limit.', 'directorist' ) );
        $url = wp_get_attachment_url( $id );
        Validation::check( (bool) $url, __( 'The attachment URL is unavailable.', 'directorist' ) );
        return esc_url_raw( $url );
    }
}
