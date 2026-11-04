<?php
/**
 * Taxonomies tool schemas and bindings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Taxonomies;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Safety\Bulk_Executor;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Registers taxonomies tools without executing them. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Return unchanged public tool definitions. */
    public static function definitions(): array {
        $string_schema      = [ 'type' => 'string' ];
        $id_schema          = [ 'type' => 'integer', 'minimum' => 1 ];
        $object_schema      = [ 'type' => 'object', 'additionalProperties' => true ];
        $id_list_schema     = [ 'type' => 'array', 'items' => $id_schema, 'maxItems' => 100 ];
        $enum_schema        = static function ( array $values ): array {
            return [ 'type' => 'string', 'enum' => $values ];
        };
        $pagination_schema  = [ 'page' => $id_schema, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $string_schema ];
        $directory_schema   = [ 'directory_id' => $id_schema ];
        $taxonomy_schema    = [ 'taxonomy' => $enum_schema( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] ) ];
        $term_schema        = $taxonomy_schema + [ 'term_id' => $id_schema ];
        $term_fields_schema = [ 'name' => $string_schema, 'slug' => $string_schema, 'description' => $string_schema, 'parent' => [ 'type' => 'integer', 'minimum' => 0 ], 'image' => $id_schema, 'icon' => $string_schema, 'directories' => $id_list_schema ];
        $mutation_controls  = [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $string_schema, 'idempotency_key' => $string_schema, 'expected_version' => $string_schema ];
        $rows               = [
            [ 'list-taxonomies', 'taxonomies', [], [], false, __( 'Discover the three listing taxonomies and current management/assignment permissions.', 'directorist' ) ],
            [ 'list-terms', 'taxonomies', $taxonomy_schema + $directory_schema + $pagination_schema + [ 'parent' => [ 'type' => 'integer', 'minimum' => 0 ], 'ids' => $id_list_schema ], [ 'taxonomy' ], false, __( 'Read taxonomy management data. Owners use assignment reference lookups instead.', 'directorist' ) ],
            [ 'get-term', 'taxonomies', $term_schema, [ 'taxonomy', 'term_id' ], false, __( 'Read a managed term with directory associations and current version.', 'directorist' ) ],
            [ 'create-term', 'taxonomies', $taxonomy_schema + $term_fields_schema, [ 'taxonomy', 'name' ], true, __( 'Create a supported listing term. Assignment permission alone is insufficient.', 'directorist' ) ],
            [ 'update-term', 'taxonomies', $term_schema + [ 'changes' => $object_schema ], [ 'taxonomy', 'term_id', 'changes' ], true, __( 'Update supported term properties; hierarchy, slug and association changes require preview.', 'directorist' ) ],
            [ 'delete-term', 'taxonomies', $term_schema, [ 'taxonomy', 'term_id' ], true, __( 'Preview and delete only an unused childless term. No cascading deletion or reassignment.', 'directorist' ) ],
            [ 'bulk-terms', 'taxonomies', $taxonomy_schema + [ 'action' => $enum_schema( [ 'create', 'update', 'delete' ] ), 'items' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $object_schema ] ], [ 'taxonomy', 'action', 'items' ], true, __( 'Preview up to 50 explicit term actions in one taxonomy with individual outcomes.', 'directorist' ) ],
        ];
        $definitions        = [];
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
            'list-taxonomies' => [ Read_Callbacks::class, 'list_taxonomies' ],
            'list-terms'      => [ Read_Callbacks::class, 'list_terms' ],
            'get-term'        => [ Read_Callbacks::class, 'get_term' ],
            'create-term'     => [ Write_Callbacks::class, 'create_term' ],
            'update-term'     => [ Write_Callbacks::class, 'update_term' ],
            'delete-term'     => [ Write_Callbacks::class, 'delete_term' ],
            'bulk-terms'      => static function ( array $args ): array { return Bulk_Executor::bulk( 'bulk-terms', $args ); },
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
        if ( 'before' === $phase && 'list-taxonomies' === $tool ) {
            $can = false;
            foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $taxonomy_schema ) {
                $tax = get_taxonomy( $taxonomy_schema );
                $can = $can || ( $tax && ( current_user_can( $tax->cap->manage_terms ) || current_user_can( $tax->cap->assign_terms ) ) );
            }
            Validation::check( $can, __( 'Taxonomy access is required.', 'directorist' ), 'forbidden' );
        }
        if ( 'after' === $phase && 'list-taxonomies' !== $tool && isset( $args['taxonomy'] ) ) {
            $operation = 'read';
            if ( in_array( $tool, [ 'create-term', 'update-term' ], true ) ) {
                $operation = 'edit'; } elseif ( 'delete-term' === $tool ) {
                $operation = 'delete'; } elseif ( 'bulk-terms' === $tool ) {
                    $operation = 'delete' === ( $args['action'] ?? '' ) ? 'delete' : 'edit'; }
                Access_Control::term_cap( $args['taxonomy'], $operation );
        }
    }

    /**
     * Publish only the tools visible to the current account.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool {
        if ( 'list-taxonomies' === $tool ) {
            Access_Control::listing_domain(); return true; }
        foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $taxonomy_schema ) {
            $object = get_taxonomy( $taxonomy_schema );
            $cap    = false !== strpos( $tool, 'delete' ) ? 'delete_terms' : ( in_array( $tool, [ 'list-terms', 'get-term' ], true ) ? 'manage_terms' : 'edit_terms' );
            if ( $object && current_user_can( $object->cap->$cap ) ) {
                return true; }
        }
        return false;
    }
}
