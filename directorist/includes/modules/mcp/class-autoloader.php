<?php
/**
 * Namespace-aware MCP autoloader, including public API compatibility.
 *
 * @package Directorist\MCP
 */
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Loads only the Directorist MCP namespace. */
class Autoloader {
    private static $registered = false;

    /** Install the module loader exactly once. */
    public static function register(): void {
        if ( ! self::$registered ) {
            self::$registered = true;
            spl_autoload_register( [ self::class, 'load' ] );
            // Catch clauses do not autoload exception aliases. Register this alias
            // before any core failure can reach an integration's legacy catch.
            if ( ! class_exists( __NAMESPACE__ . '\\Failure', false ) ) {
                class_alias( Support\Failure::class, __NAMESPACE__ . '\\Failure' );
            }

        }
    }

    /**
     * Resolve subnamespaces to module folders and WordPress class filenames.
     *
     * @param string $class Class.
     */
    public static function load( string $class ): void {
        $prefix = 'Directorist\\MCP\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        if ( false === strpos( $relative, '\\' ) ) {
            $alias = Compatibility\Legacy_Class_Map::resolve( $relative );
            if ( $alias ) {
                class_alias( $alias, $class );
                return;
            }
            $file = __DIR__ . '/compatibility/class-' . str_replace( '_', '-', strtolower( $relative ) ) . '.php';
        } else {
            $parts    = explode( '\\', $relative );
            $name     = array_pop( $parts );
            $folder   = implode( '/', array_map( static function ( $part ) { return str_replace( '_', '-', strtolower( $part ) ); }, $parts ) );
            $filename = ( 'Contracts' === $parts[0] ? 'interface-' : 'class-' ) . str_replace( '_', '-', strtolower( $name ) ) . '.php';
            $file     = __DIR__ . '/' . $folder . '/' . $filename;
        }
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
