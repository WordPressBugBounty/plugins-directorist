<?php
/**
 * Settings tool schemas and bindings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Settings;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Runtime\Access_Control;

defined( 'ABSPATH' ) || exit;

/** Registers settings tools without executing them. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Return unchanged public tool definitions. */
    public static function definitions(): array {
        $string_schema      = [ 'type' => 'string' ];
        $id_schema          = [ 'type' => 'integer', 'minimum' => 1 ];
        $object_schema      = [ 'type' => 'object', 'additionalProperties' => true ];
        $string_list_schema = [ 'type' => 'array', 'items' => $string_schema, 'maxItems' => 100 ];
        $pagination_schema  = [ 'page' => $id_schema, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $string_schema ];
        $mutation_controls  = [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $string_schema, 'idempotency_key' => $string_schema, 'expected_version' => $string_schema ];
        $rows               = [
            [ 'discover-settings', 'settings', [ 'group' => $string_schema, 'keys' => $string_list_schema ] + $pagination_schema, [], false, __( 'Discover supported non-secret settings, defaults and constraints.', 'directorist' ) ],
            [ 'get-settings', 'settings', [ 'group' => $string_schema, 'keys' => $string_list_schema ] + $pagination_schema, [], false, __( 'Read selected supported settings. Requires configuration access.', 'directorist' ) ],
            [ 'update-settings', 'settings', [ 'changes' => $object_schema ], [ 'changes' ], true, __( 'Preview and update explicit supported settings as one change set.', 'directorist' ) ],
            [ 'reset-settings', 'settings', [ 'keys' => $string_list_schema ], [ 'keys' ], true, __( 'Preview and reset explicit supported settings to registered defaults.', 'directorist' ) ],
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
            'discover-settings' => [ Read_Callbacks::class, 'discover_settings' ],
            'get-settings'      => [ Read_Callbacks::class, 'get_settings' ],
            'update-settings'   => [ Write_Callbacks::class, 'update_settings' ],
            'reset-settings'    => [ Write_Callbacks::class, 'reset_settings' ],
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
