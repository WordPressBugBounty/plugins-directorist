<?php
/**
 * Integration discovery tool definition and callbacks.
 *
 * @package Directorist\MCP
 */
namespace Directorist\MCP\Extensions;

use Directorist\MCP\Contracts\Tool_Provider as Provider_Contract;
use Directorist\MCP\Registration\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/** Owns core integration discovery, separate from third-party tool registration. */
class Tool_Provider implements Provider_Contract {
    /** Return the unchanged integration discovery descriptor. */
    public static function definitions(): array {
        $definitions                        = [];
        $definitions['list-mcp-extensions'] = [
            'group'       => 'context',
            'write'       => false,
            'description' => __( 'Discover permitted active MCP integrations. Private diagnostics require administration access.', 'directorist' ),
            'schema'      => [ 'type' => 'object', 'properties' => [ 'page' => [ 'type' => 'integer', 'minimum' => 1 ], 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => [ 'type' => 'string' ], 'group' => [ 'type' => 'string' ], 'diagnostics' => [ 'type' => 'boolean' ], 'operation_id' => [ 'type' => 'string', 'maxLength' => 36 ] ], 'required' => [], 'additionalProperties' => false ],
        ];
        return $definitions;
    }

    /** Bind integration discovery to the shared registry's metadata reader. */
    public static function callbacks(): array {
        return [ 'list-mcp-extensions' => static function ( array $args ): array {
            return Tool_Registry::instance()->integrations( $args );
        }
        ];
    }

    /** Integration diagnostics apply their administration gate in the reader. */
    public static function authorize( string $tool, array $args, string $phase ): void {
    }

    /** Connected accounts may discover integrations filtered to their access. */
    public static function discover( string $tool ): bool {
        return true;
    }
}
