<?php
/**
 * Compatibility facade for the original Write_Guard API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Safety\Operation_Store;
use Directorist\MCP\Safety\Transaction;
use Directorist\MCP\Safety\Write_Guard as Safety_Write_Guard;
use Directorist\MCP\Safety\Write_Lock;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Write_Guard {
    /** Forward is locked to its implementation. */
    public static function is_locked() {
        return Write_Lock::is_locked();
    }

    /** Forward is mutating to its implementation. */
    public static function is_mutating() {
        return Safety_Write_Guard::is_mutating();
    }

    /** Forward lock to its implementation. */
    public static function lock() {
        return Write_Lock::lock();
    }

    /** Forward unlock to its implementation. */
    public static function unlock() {
        return Write_Lock::unlock();
    }

    /** Forward native lock to its implementation. */
    public static function native_lock() {
        return Write_Lock::native_lock();
    }

    /** Forward fresh to its implementation. */
    public static function fresh() {
        return Safety_Write_Guard::fresh();
    }

    /**
     * Forward run to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param callable $prepare Prepare.
     */
    public static function run( $tool, array $args, callable $prepare ) {
        return Safety_Write_Guard::run( $tool, $args, $prepare );
    }

    /**
     * Forward checkpoint to its implementation.
     *
     * @param array $results Results.
     */
    public static function checkpoint( array $results ) {
        return Safety_Write_Guard::checkpoint( $results );
    }

    /**
     * Forward atomic to its implementation.
     *
     * @param callable $callback Operation to invoke within the guarded context.
     * @param array $tables Tables.
     * @param mixed $invalidate Invalidate.
     */
    public static function atomic( callable $callback, array $tables = [], $invalidate = null ) {
        return Transaction::atomic( $callback, $tables, $invalidate );
    }

    /**
     * Forward check tables to its implementation.
     *
     * @param array $tables Tables.
     */
    public static function check_tables( array $tables ) {
        return Transaction::check_tables( $tables );
    }

    /**
     * Forward cleanup to its implementation.
     *
     * @param mixed $cursor Cursor.
     */
    public static function cleanup( $cursor = 0 ) {
        return Operation_Store::cleanup( $cursor );
    }
}
