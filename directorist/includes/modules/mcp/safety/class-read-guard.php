<?php
/**
 * Read Guard for read guard.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Read Guard. */
class Read_Guard {
    private static $depth = 0;

    /** Assert writable. */
    public static function assert_writable(): void {
        Validation::check( 0 === self::$depth, __( 'A read or preview cannot mutate product data.', 'directorist' ), 'read_only_violation' );
    }

    /**
     * Run.
     *
     * @param callable $callback Operation to invoke within the guarded context.
     * @param mixed $allow_external_writes Allow external writes.
     * @param mixed $deny_http Deny http.
     * @return mixed Guarded callback result, preserved without coercion.
     */
    public static function run( callable $callback, $allow_external_writes = false, $deny_http = false ) {
        ++self::$depth;
        $query = static function ( $sql ) {
            if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) {
                Validation::fail( 'read_only_violation', __( 'A read or preview attempted a database write.', 'directorist' ) );
            }
            return $sql;
        };
        $http  = static function ( $preempt, $args ) use ( $deny_http ) {
            Validation::check( ! $deny_http && in_array( strtoupper( $args['method'] ?? 'GET' ), [ 'GET', 'HEAD' ], true ), __( 'This read does not permit the requested external operation.', 'directorist' ), 'read_only_violation' );
            return $preempt;
        };
        add_filter( 'query', $query, PHP_INT_MAX );
        if ( ! $allow_external_writes ) {
            add_filter( 'pre_http_request', $http, PHP_INT_MAX, 2 );
        }
        try {
            return $callback();
        } finally {
            --self::$depth;
            remove_filter( 'query', $query, PHP_INT_MAX );
            remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
        }
    }
}
