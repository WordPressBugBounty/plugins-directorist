<?php
/**
 * Write Callbacks for directories.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Directories;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Validation;
use Directorist\Multi_Directory\Multi_Directory_Manager as Manager;

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
        Access_Control::admin();
        $create    = 'create-directory' === $tool;
        $duplicate = 'duplicate-directory' === $tool;
        $term      = $create ? null : Validation::directory( $args['directory_id'] );
        $id        = $term ? (int) $term->term_id : 0;
        $version   = $id ? Directory_Configuration::version( $id ) : Canonical_Data::hash( array_keys( Directory_Configuration::definitions() ) );
        if ( $id && empty( $args['dry_run'] ) ) {
            Validation::version( $args['expected_version'] ?? null, $version );
        }
        if ( $create || $duplicate ) {
            Validation::check( directorist_is_multi_directory_enabled(), __( 'Enable multi-directory mode before creating another directory.', 'directorist' ), 'unsupported_operation' );
            $name = Validation::clean_text( $args['name'] );
            self::unique_name( $name );
            $slug = sanitize_title( $args['slug'] ?? $name );
            Validation::check( $slug && ! get_term_by( 'slug', $slug, ATBDP_DIRECTORY_TYPE ), __( 'Use a unique directory slug.', 'directorist' ) );
            $values = $duplicate ? Directory_Configuration::configuration( $id ) : array_map( [ Directory_Configuration::class, 'default_value' ], Directory_Configuration::definitions() );
            unset( $values['import_export'] );
            $changes = $create ? Directory_Configuration::general( Validation::object( $args['changes'] ?? [] ) ) : [];
            $values  = array_replace( $values, $changes );
            return [
                'state'   => [ $version, $values ],
                'confirm' => true,
                'preview' => [ 'name' => $name, 'slug' => $slug, 'configuration_only' => true, 'version' => $version ],
                'execute' => static function () use ( $name, $slug, $values, $id, $duplicate ) {
                    $result = Directory_Configuration::write_values(
                        $values, $duplicate ? $id : 0, static function ( $native_values ) use ( $name ) {
                            return Manager::add_directory( [ 'directory_name' => $name, 'fields_value' => $native_values ] );
                        }
                    );
                    Validation::check( ! empty( $result['status']['success'] ), __( 'Directory creation failed.', 'directorist' ), 'operation_failed' );
                    $new_id = (int) $result['term_id'];
                    Validation::unwrap( wp_update_term( $new_id, ATBDP_DIRECTORY_TYPE, [ 'slug' => $slug ] ) );
                    return Result::result( [ 'directory_id' => $new_id, 'version' => Directory_Configuration::version( $new_id ) ] );
                }
            ];
        }
        if ( 'delete-directory' === $tool ) {
            $deps = Directory_Configuration::dependencies( $id );
            Validation::check( $id !== (int) directorist_get_default_directory() && (int) wp_count_terms( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false ] ) > 1 && ! array_filter( $deps ), __( 'Only an empty, non-default directory without dependencies can be deleted.', 'directorist' ), 'dependency_blocked' );
            return [
                'state'   => $version,
                'confirm' => true,
                'preview' => [ 'directory_id' => $id, 'delete' => true, 'version' => $version ],
                'execute' => static function () use ( $id ) {
                    Validation::unwrap( wp_delete_term( $id, ATBDP_DIRECTORY_TYPE ) );
                    return Result::result( [ 'directory_id' => $id, 'deleted' => true ] );
                }
            ];
        }
        if ( 'set-default-directory' === $tool ) {
            return [
                'state'   => $version,
                'confirm' => true,
                'preview' => [ 'old_default' => directorist_get_default_directory(), 'new_default' => $id, 'version' => $version ],
                'execute' => static function () use ( $id ) {
                    $directories = get_terms( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'fields' => 'ids' ] );
                    foreach ( $directories as $directory ) {
                        update_term_meta( $directory, '_default', (int) $directory === $id );
                    }
                    update_directorist_option( 'atbdp_default_derectory', $id );
                    do_action( 'directorist_options_updated' );
                    return Result::result( [ 'directory_id' => $id, 'version' => Directory_Configuration::version( $id ) ] );
                }
            ];
        }
        $values = Directory_Configuration::configuration( $id );
        $name   = $term->name;
        $slug   = $term->slug;
        if ( 'update-directory' === $tool ) {
            $changes = Validation::object( $args['changes'] );
            if ( isset( $changes['name'] ) ) {
                $name = Validation::clean_text( $changes['name'] );
                unset( $changes['name'] );
            }
            if ( isset( $changes['slug'] ) ) {
                $slug = sanitize_title( $changes['slug'] );
                unset( $changes['slug'] );
            }
            Validation::check( $name && $slug, __( 'Name and slug cannot be empty.', 'directorist' ) );
            self::unique_name( $name, $term->name );
            $existing = get_term_by( 'slug', $slug, ATBDP_DIRECTORY_TYPE );
            Validation::check( ! $existing || $existing->term_id === $id, __( 'That directory slug is already in use.', 'directorist' ) );
            $changes = Directory_Configuration::general( $changes );
        } else {
            $area = $args['area'];
            Validation::check( isset( Directory_Configuration::AREAS[ $area ] ) && 'general' !== $area, __( 'Use update-directory for general settings.', 'directorist' ) );
            $result = $values;
            foreach ( $args['operations'] as $operation ) {
                Builder_Operations::operation( $result, $area, Validation::object( $operation ) );
            }
            Builder_Validator::validate_configuration( $values, $result );
            $changes = [];
            foreach ( Directory_Configuration::AREAS[ $area ] as $key ) {
                if ( $result[ $key ] !== $values[ $key ] ) {
                    $changes[ $key ] = $result[ $key ];
                }
            }
        }
        return [
            'state'   => $version,
            'confirm' => true,
            'preview' => [ 'directory_id' => $id, 'name' => $name, 'slug' => $slug, 'changes' => $changes, 'version' => $version ],
            'execute' => static function () use ( $id, $name, $slug, $changes ) {
                do_action( 'directorist_before_directory_type_updated' );
                $result = Directory_Configuration::write_values(
                    $changes, $id, static function ( $native_values ) use ( $id, $name ) {
                        return Manager::update_directory( [ 'term_id' => $id, 'directory_name' => $name, 'fields_value' => $native_values ] );
                    }
                );
                Validation::check( ! empty( $result['status']['success'] ), __( 'The builder update failed.', 'directorist' ), 'operation_failed' );
                Validation::unwrap( wp_update_term( $id, ATBDP_DIRECTORY_TYPE, [ 'slug' => $slug ] ) );
                return Result::result( [ 'directory_id' => $id, 'version' => Directory_Configuration::version( $id ) ] );
            }
        ];
    }

    /**
     * Match the native directory writer's name validation before reserving a write.
     *
     * @param string $name Proposed name.
     * @param string $previous Existing name for updates.
     */
    private static function unique_name( string $name, string $previous = '' ): void {
        $native_name = esc_attr( $name );
        Validation::check( '' !== trim( $native_name ), __( 'Name cannot be empty.', 'directorist' ) );
        Validation::check( $previous === $native_name || ! term_exists( $native_name, ATBDP_DIRECTORY_TYPE ), __( 'That directory name is already in use.', 'directorist' ), 'conflict' );
    }

    /**
     * Handle create-directory through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function create_directory( array $args = [] ): array {
        return self::prepare( 'create-directory', $args );
    }

    /**
     * Handle duplicate-directory through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function duplicate_directory( array $args = [] ): array {
        return self::prepare( 'duplicate-directory', $args );
    }

    /**
     * Handle update-directory through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function update_directory( array $args = [] ): array {
        return self::prepare( 'update-directory', $args );
    }

    /**
     * Handle set-default-directory through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function set_default_directory( array $args = [] ): array {
        return self::prepare( 'set-default-directory', $args );
    }

    /**
     * Handle delete-directory through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function delete_directory( array $args = [] ): array {
        return self::prepare( 'delete-directory', $args );
    }

    /**
     * Handle update-builder through the feature mutation contract.
     *
     * @param array $args Validated tool input.
     */
    public static function update_builder( array $args = [] ): array {
        return self::prepare( 'update-builder', $args );
    }
}
