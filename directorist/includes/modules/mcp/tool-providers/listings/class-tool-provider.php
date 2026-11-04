<?php
/**
 * Listings tool schemas and bindings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Listings;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Safety\Bulk_Executor;

defined( 'ABSPATH' ) || exit;

/** Registers listings tools without executing them. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Return unchanged public tool definitions. */
    public static function definitions(): array {
        $string_schema     = [ 'type' => 'string' ];
        $id_schema         = [ 'type' => 'integer', 'minimum' => 1 ];
        $object_schema     = [ 'type' => 'object', 'additionalProperties' => true ];
        $id_list_schema    = [ 'type' => 'array', 'items' => $id_schema, 'maxItems' => 100 ];
        $enum_schema       = static function ( array $values ): array {
            return [ 'type' => 'string', 'enum' => $values ];
        };
        $pagination_schema = [ 'page' => $id_schema, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $string_schema ];
        $directory_schema  = [ 'directory_id' => $id_schema ];
        $listing_schema    = [ 'listing_id' => $id_schema ];
        $mutation_controls = [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $string_schema, 'idempotency_key' => $string_schema, 'expected_version' => $string_schema ];
        $rows              = [
            [ 'list-listings', 'listings', $pagination_schema + $directory_schema + [ 'scope' => $enum_schema( [ 'management', 'public' ] ), 'ids' => $id_list_schema, 'author' => $id_schema, 'status' => $string_schema, 'categories' => $id_list_schema, 'locations' => $id_list_schema, 'tags' => $id_list_schema, 'featured' => [ 'type' => 'boolean' ], 'created_after' => $string_schema, 'created_before' => $string_schema, 'modified_after' => $string_schema, 'modified_before' => $string_schema, 'orderby' => $enum_schema( [ 'id', 'title', 'date', 'modified' ] ), 'order' => $enum_schema( [ 'asc', 'desc' ] ) ], [], false, __( 'Find authorized listings with explicit filters. Large scopes with object policies can return short or empty pages and null totals. Continue while has_more is true.', 'directorist' ) ],
            [ 'get-listing', 'listings', $listing_schema + [ 'scope' => $enum_schema( [ 'management', 'public' ] ) ], [ 'listing_id' ], false, __( 'Read authorized listing fields, actual status, allowed actions and version.', 'directorist' ) ],
            [ 'get-listing-schema', 'listings', $directory_schema + $listing_schema, [ 'directory_id' ], false, __( 'Discover directory-specific fields, required consents and submission constraints.', 'directorist' ) ],
            [ 'create-listing', 'listings', $directory_schema + [ 'fields' => $object_schema, 'consents' => $object_schema, 'status' => $string_schema, 'schema_version' => $string_schema ], [ 'directory_id', 'fields' ], true, __( 'Create for the current user through native submission validation. Public publication requires preview.', 'directorist' ) ],
            [ 'update-listing', 'listings', $listing_schema + [ 'changes' => $object_schema, 'consents' => $object_schema ], [ 'listing_id', 'changes' ], true, __( 'Edit explicit supported fields with native edit policy. No owner/entitlement overrides.', 'directorist' ) ],
            [ 'change-listing-status', 'listings', $listing_schema + [ 'status' => $string_schema ], [ 'listing_id', 'status' ], true, __( 'Preview an eligible native publication/privacy transition.', 'directorist' ) ],
            [ 'trash-listing', 'listings', $listing_schema, [ 'listing_id' ], true, __( 'Preview and trash a listing. Permanent deletion is never supported.', 'directorist' ) ],
            [ 'restore-listing', 'listings', $listing_schema, [ 'listing_id' ], true, __( 'Preview and restore an eligible trashed listing under current policies.', 'directorist' ) ],
            [ 'bulk-listings', 'listings', [ 'action' => $enum_schema( [ 'create', 'update', 'change_status', 'assign_terms', 'trash', 'restore' ] ), 'items' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $object_schema ] ], [ 'action', 'items' ], true, __( 'Preview up to 50 explicit listing actions; each result is independent.', 'directorist' ) ],
        ];
        $definitions       = [];
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
            'list-listings'         => [ Read_Callbacks::class, 'list_listings' ],
            'get-listing'           => [ Read_Callbacks::class, 'get_listing' ],
            'get-listing-schema'    => [ Read_Callbacks::class, 'get_listing_schema' ],
            'create-listing'        => [ Write_Callbacks::class, 'create_listing' ],
            'update-listing'        => [ Write_Callbacks::class, 'update_listing' ],
            'change-listing-status' => [ Write_Callbacks::class, 'change_listing_status' ],
            'trash-listing'         => [ Write_Callbacks::class, 'trash_listing' ],
            'restore-listing'       => [ Write_Callbacks::class, 'restore_listing' ],
            'bulk-listings'         => static function ( array $args ): array { return Bulk_Executor::bulk( 'bulk-listings', $args ); },
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
        if ( 'before' === $phase ) {
            Access_Control::listing_domain(); }
        if ( 'after' === $phase && isset( $args['listing_id'] ) ) {
            $action = in_array( $tool, [ 'get-listing', 'get-listing-schema' ], true ) ? 'read' : 'edit';
            if ( in_array( $tool, [ 'trash-listing', 'restore-listing' ], true ) ) {
                $action = 'delete'; }
            Access_Control::listing( $args['listing_id'], $action, $args['scope'] ?? 'management' );
        }
    }

    /**
     * Publish only the tools visible to the current account.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool {
        $type = get_post_type_object( ATBDP_POST_TYPE );
        return $type && current_user_can( $type->cap->edit_posts );
    }
}
