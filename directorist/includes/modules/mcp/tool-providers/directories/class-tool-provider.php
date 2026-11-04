<?php
/**
 * Directories tool schemas and bindings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Directories;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Runtime\Access_Control;

defined( 'ABSPATH' ) || exit;

/** Registers directories tools without executing them. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Return unchanged public tool definitions. */
    public static function definitions(): array {
        $string_schema       = [ 'type' => 'string' ];
        $id_schema           = [ 'type' => 'integer', 'minimum' => 1 ];
        $object_schema       = [ 'type' => 'object', 'additionalProperties' => true ];
        $enum_schema         = static function ( array $values ): array {
            return [ 'type' => 'string', 'enum' => $values ];
        };
        $pagination_schema   = [ 'page' => $id_schema, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $string_schema ];
        $directory_schema    = [ 'directory_id' => $id_schema ];
        $builder_area_schema = [ 'area' => $enum_schema( [ 'general', 'submission_form', 'search_form', 'single_listing', 'listing_cards' ] ) ];
        $mutation_controls   = [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $string_schema, 'idempotency_key' => $string_schema, 'expected_version' => $string_schema ];
        $rows                = [
            [ 'list-directories', 'directories', $pagination_schema + [ 'is_default' => [ 'type' => 'boolean' ] ], [], false, __( 'List directory management summaries. Administrator only.', 'directorist' ) ],
            [ 'get-directory', 'directories', $directory_schema + $builder_area_schema, [ 'directory_id' ], false, __( 'Inspect a directory and its configuration/version.', 'directorist' ) ],
            [ 'get-builder-schema', 'directories', $directory_schema + $builder_area_schema, [ 'directory_id', 'area' ], false, __( 'Discover builder widgets, placements, options and constraints before editing.', 'directorist' ) ],
            [ 'create-directory', 'directories', [ 'name' => $string_schema, 'slug' => $string_schema, 'changes' => $object_schema ], [ 'name' ], true, __( 'Preview and create a directory with native defaults. Multi-directory mode required.', 'directorist' ) ],
            [ 'duplicate-directory', 'directories', $directory_schema + [ 'name' => $string_schema, 'slug' => $string_schema ], [ 'directory_id', 'name' ], true, __( 'Duplicate configuration only; no listings or term associations.', 'directorist' ) ],
            [ 'update-directory', 'directories', $directory_schema + [ 'changes' => $object_schema ], [ 'directory_id', 'changes' ], true, __( 'Preview and update supported directory identity/general policies.', 'directorist' ) ],
            [ 'set-default-directory', 'directories', $directory_schema, [ 'directory_id' ], true, __( 'Preview and select the single default directory.', 'directorist' ) ],
            [ 'delete-directory', 'directories', $directory_schema, [ 'directory_id' ], true, __( 'Delete only an empty, non-default directory without dependencies. Preview required.', 'directorist' ) ],
            [ 'update-builder', 'directories', $directory_schema + $builder_area_schema + [ 'operations' => [ 'type' => 'array', 'items' => $object_schema, 'minItems' => 1, 'maxItems' => 50 ] ], [ 'directory_id', 'area', 'operations' ], true, __( 'Preview typed builder operations. Supply a unique stable ID for every field or section addition before previewing. Existing listing values are retained.', 'directorist' ) ],
        ];
        $definitions         = [];
        foreach ( $rows as $row ) {
            list( $name, $group, $properties, $required, $write, $description ) = $row;
            if ( $write ) {
                $properties += $mutation_controls;
            }
            $definitions[ $name ] = [
                'group'       => $group,
                'write'       => $write,
                'description' => $description,
                'schema'      => [ 'type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false ]
            ];
        }
        return $definitions;
    }

    /** Return internal callbacks; these are not part of the persisted fingerprint. */
    public static function callbacks(): array {
        return [
            'list-directories'      => [ Read_Callbacks::class, 'list_directories' ],
            'get-directory'         => [ Read_Callbacks::class, 'get_directory' ],
            'get-builder-schema'    => [ Read_Callbacks::class, 'get_builder_schema' ],
            'create-directory'      => [ Write_Callbacks::class, 'create_directory' ],
            'duplicate-directory'   => [ Write_Callbacks::class, 'duplicate_directory' ],
            'update-directory'      => [ Write_Callbacks::class, 'update_directory' ],
            'set-default-directory' => [ Write_Callbacks::class, 'set_default_directory' ],
            'delete-directory'      => [ Write_Callbacks::class, 'delete_directory' ],
            'update-builder'        => [ Write_Callbacks::class, 'update_builder' ],
        ];
    }

    /**
     * Enforce this feature's existing gates in their original policy order.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param string $phase Authorization phase around restrictive extension policies.
     */
    public static function authorize( string $tool, array $args, string $phase ): void {
        if ( 'after' === $phase ) {
            Access_Control::admin(); }
    }

    /**
     * Publish only the tools visible to the current account.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool {
        return current_user_can( 'manage_options' );
    }
}
