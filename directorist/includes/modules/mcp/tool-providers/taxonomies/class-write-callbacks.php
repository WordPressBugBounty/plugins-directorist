<?php
/**
 * Write Callbacks for taxonomies.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Taxonomies;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Canonical_Data;
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
        $taxonomy = Validation::taxonomy( $args['taxonomy'] );
        $create   = 'create-term' === $tool;
        $delete   = 'delete-term' === $tool;
        Access_Control::term_cap( $taxonomy, $delete ? 'delete' : 'edit' );
        $term    = $create ? null : Term_Validator::term( $taxonomy, $args['term_id'] );
        $version = $term ? Term_Validator::version( $term ) : Canonical_Data::hash( [ $taxonomy, directorist_get_default_directory() ] );
        if ( ! $create && empty( $args['dry_run'] ) ) {
            Validation::version( $args['expected_version'] ?? null, $version );
        }
        if ( $delete ) {
            $deps = Term_Validator::dependencies( $term );
            Validation::check( ! $deps['relationships'] && ! $deps['children'] && ! $deps['protected'], __( 'This term still has listing, child or protected dependencies. Resolve them before deleting it.', 'directorist' ), 'dependency_blocked' );
            return [
                'state'   => $version,
                'confirm' => true,
                'preview' => [ 'term_id' => (int) $term->term_id, 'delete' => true, 'version' => $version ],
                'execute' => static function () use ( $term ) {
                    Validation::unwrap( wp_delete_term( $term->term_id, $term->taxonomy ) );
                    Validation::check( ! term_exists( $term->term_id, $term->taxonomy ), __( 'The deletion could not be verified.', 'directorist' ), 'operation_failed' );
                    return Result::result( [ 'term_id' => (int) $term->term_id, 'deleted' => true ] );
                }
            ];
        }
        if ( $create ) {
            $changes = $args;
            foreach ( [ 'taxonomy', 'dry_run', 'confirm_token', 'idempotency_key', 'expected_version' ] as $key ) {
                unset( $changes[ $key ] );
            }
        } else {
            $changes = Validation::object( $args['changes'], 'changes' );
        }
        $allowed = [ 'name', 'slug', 'description', 'directories' ];
        if ( ATBDP_TAGS !== $taxonomy ) {
            $allowed = array_merge( $allowed, [ 'parent', 'image' ] );
        }
        if ( ATBDP_CATEGORY === $taxonomy ) {
            $allowed[] = 'icon';
        }
        Validation::allowed( $changes, $allowed );
        Validation::check( (bool) $changes, __( 'No term changes were supplied.', 'directorist' ) );
        foreach ( [ 'name', 'slug', 'description', 'icon' ] as $key ) {
            if ( isset( $changes[ $key ] ) ) {
                $changes[ $key ] = Validation::clean_text( $changes[ $key ], 'description' === $key );
            }
        }
        if ( $create || isset( $changes['name'] ) ) {
            Validation::check( '' !== trim( $changes['name'] ?? '' ), __( 'A term name is required.', 'directorist' ) );
        }
        if ( isset( $changes['slug'] ) ) {
            $changes['slug'] = sanitize_title( $changes['slug'] );
            $existing        = get_term_by( 'slug', $changes['slug'], $taxonomy );
            Validation::check( $changes['slug'] && ( ! $existing || ( $term && $existing->term_id === $term->term_id ) ), __( 'That term slug is already in use or invalid.', 'directorist' ) );
        }
        if ( isset( $changes['image'] ) && 0 !== $changes['image'] ) {
            Access_Control::attachment( $changes['image'], true );
        }
        $directories = $term ? Term_Validator::scope( $term->term_id ) : [ (int) directorist_get_default_directory() ];
        if ( isset( $changes['directories'] ) ) {
            Validation::check( directorist_is_multi_directory_enabled() || $create, __( 'Directory associations cannot be edited in single-directory mode.', 'directorist' ), 'unsupported_operation' );
            if ( is_object( $changes['directories'] ) || ( is_array( $changes['directories'] ) && ( isset( $changes['directories']['add'] ) || isset( $changes['directories']['remove'] ) || isset( $changes['directories']['replace'] ) ) ) ) {
                $operation = Validation::object( $changes['directories'] );
                Validation::allowed( $operation, [ 'add', 'remove', 'replace' ] );
                Validation::check( ! isset( $operation['replace'] ) || 1 === count( $operation ), __( 'Do not mix replace with add/remove.', 'directorist' ) );
                $directories = isset( $operation['replace'] ) ? Validation::ids( $operation['replace'] ) : array_values( array_diff( array_unique( array_merge( $directories, Validation::ids( $operation['add'] ?? [] ) ) ), Validation::ids( $operation['remove'] ?? [] ) ) );
            } else {
                $directories = Validation::ids( $changes['directories'] );
            }
            Validation::check( (bool) $directories, __( 'A term must retain an eligible directory.', 'directorist' ) );
            foreach ( $directories as $directory ) {
                Validation::directory( $directory );
            }
            $changes['directories'] = $directories;
            if ( $term && array_diff( Term_Validator::scope( $term->term_id ), $directories ) ) {
                $object_ids = get_objects_in_term( $term->term_id, $taxonomy );
                foreach ( Validation::unwrap( $object_ids ) as $object_id ) {
                    $assigned = wp_get_object_terms( $object_id, ATBDP_DIRECTORY_TYPE, [ 'fields' => 'ids' ] );
                    Validation::check( ! array_diff( Validation::unwrap( $assigned ), $directories ), __( 'Listings still use this term in a directory being removed.', 'directorist' ), 'dependency_blocked' );
                }
            }
        }
        $parent = $changes['parent'] ?? ( $term ? (int) $term->parent : 0 );
        Validation::check( is_int( $parent ) && $parent >= 0, __( 'Parent must be a nonnegative integer.', 'directorist' ) );
        if ( $create ) {
            Validation::check( ! term_exists( $changes['name'], $taxonomy, $parent ), __( 'This term name already exists in that scope.', 'directorist' ) );
        }
        if ( $parent ) {
            Term_Validator::term( $taxonomy, $parent );
            Validation::check( ! $term || ( $parent !== $term->term_id && ! in_array( $term->term_id, get_ancestors( $parent, $taxonomy, 'taxonomy' ), true ) ), __( 'This parent would create a hierarchy cycle.', 'directorist' ) );
            Validation::check( ! array_diff( $directories, Term_Validator::scope( $parent ) ), __( 'The parent must support every child directory.', 'directorist' ) );
        }
        if ( $term ) {
            foreach ( Validation::unwrap( get_terms( [ 'taxonomy' => $taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids' ] ) ) as $child ) {
                Validation::check( ! array_diff( Term_Validator::scope( $child ), $directories ), __( 'This change would invalidate a child directory association.', 'directorist' ) );
            }
        }
        $confirm = ! $create && ( isset( $changes['slug'] ) || isset( $changes['parent'] ) || isset( $changes['directories'] ) );
        return [
            'state'   => [ $version, $directories, $parent ],
            'confirm' => $confirm,
            'preview' => [ 'term_id' => $term ? $term->term_id : null, 'changes' => $changes, 'version' => $version ],
            'execute' => static function () use ( $create, $term, $taxonomy, $changes ) {
                $data   = array_intersect_key( $changes, array_flip( [ 'name', 'slug', 'description', 'parent' ] ) );
                $result = $create ? wp_insert_term( $data['name'], $taxonomy, $data ) : wp_update_term( $term->term_id, $taxonomy, $data );
                $result = Validation::unwrap( $result );
                $id     = (int) $result['term_id'];
                if ( isset( $changes['directories'] ) || $create ) {
                    directorist_update_term_directory( $id, $changes['directories'] ?? [ (int) directorist_get_default_directory() ] );
                }
                if ( isset( $changes['image'] ) ) {
                    update_term_meta( $id, 'image', $changes['image'] );
                }
                if ( isset( $changes['icon'] ) ) {
                    update_term_meta( $id, 'category_icon', $changes['icon'] );
                }
                clean_term_cache( $id, $taxonomy );
                return Result::result( [ 'term_id' => $id, 'data' => Read_Callbacks::data( get_term( $id, $taxonomy ) ) ] );
            }
        ];
    }

    /**
     * Handle create-term through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function create_term( array $args = [] ): array {
        return self::prepare( 'create-term', $args );
    }

    /**
     * Handle update-term through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function update_term( array $args = [] ): array {
        return self::prepare( 'update-term', $args );
    }

    /**
     * Handle delete-term through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function delete_term( array $args = [] ): array {
        return self::prepare( 'delete-term', $args );
    }
}
