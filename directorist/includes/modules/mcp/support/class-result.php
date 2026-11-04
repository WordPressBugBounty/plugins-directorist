<?php
/**
 * Result for support.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Support;

defined( 'ABSPATH' ) || exit;

/** Result. */
class Result {
    /**
     * Result.
     *
     * @param array $data Data.
     */
    public static function result( array $data = [] ): array {
        return array_merge( [ 'success' => true, 'committed' => true, 'idempotent_replay' => false ], $data );
    }
}
