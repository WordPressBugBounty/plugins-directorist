<?php
/**
 * Context tool schemas and bindings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Context;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Runtime\Access_Control;

defined( 'ABSPATH' ) || exit;

/** Registers context tools without executing them. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Return unchanged public tool definitions. */
    public static function definitions(): array {
        $string_schema     = [ 'type' => 'string' ];
        $id_schema         = [ 'type' => 'integer', 'minimum' => 1 ];
        $id_list_schema    = [ 'type' => 'array', 'items' => $id_schema, 'maxItems' => 100 ];
        $enum_schema       = static function ( array $values ): array {
            return [ 'type' => 'string', 'enum' => $values ];
        };
        $pagination_schema = [ 'page' => $id_schema, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $string_schema ];
        $directory_schema  = [ 'directory_id' => $id_schema ];
        $taxonomy_schema   = [ 'taxonomy' => $enum_schema( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] ) ];
        $mutation_controls = [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $string_schema, 'idempotency_key' => $string_schema, 'expected_version' => $string_schema ];
        $rows              = [
            [ 'get-context', 'context', [], [], false, __( 'Start here. Current identity, permissions, tool groups and limits.', 'directorist' ) ],
            [ 'get-reference-data', 'context', [ 'kind' => $enum_schema( [ 'directories', 'assignment_terms', 'media', 'pages', 'order_references' ] ), 'ids' => $id_list_schema ] + $directory_schema + $taxonomy_schema + $pagination_schema, [ 'kind' ], false, __( 'Eligible directory, term, media or page choices. Never unrestricted configuration.', 'directorist' ) ],
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
            'get-context'        => [ Context_Callbacks::class, 'get_context' ],
            'get-reference-data' => [ Reference_Callbacks::class, 'get_reference_data' ],
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
        if ( 'before' === $phase && 'get-reference-data' === $tool && 'order_references' !== ( $args['kind'] ?? '' ) ) {
            Access_Control::listing_domain(); }
    }

    /**
     * Publish only the tools visible to the current account.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool {
        if ( 'get-reference-data' === $tool ) {
            Access_Control::listing_domain(); }
        return true;
    }
}
