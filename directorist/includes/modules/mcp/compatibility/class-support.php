<?php
/**
 * Compatibility facade for the original Support API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Extensions\Callback_Invoker;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Operation_Events;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Support {
    /**
     * Forward fail to its implementation.
     *
     * @param mixed $code Code.
     * @param mixed $message Message.
     * @param array $details Details.
     */
    public static function fail( $code, $message, array $details = [] ) {
        return Validation::fail( $code, $message, $details );
    }

    /**
     * Forward check to its implementation.
     *
     * @param mixed $condition Condition.
     * @param mixed $message Message.
     * @param mixed $code Code.
     */
    public static function check( $condition, $message, $code = 'validation_failed' ) {
        return Validation::check( $condition, $message, $code );
    }

    /**
     * Forward unwrap to its implementation.
     *
     * @param mixed $result Result.
     */
    public static function unwrap( $result ) {
        return Validation::unwrap( $result );
    }

    /**
     * Forward extension callback to its implementation.
     *
     * @param mixed $callback Operation to invoke within the guarded context.
     * @param mixed ...$args Validated tool input.
     */
    public static function extension_callback( $callback, ...$args ) {
        return Callback_Invoker::extension_callback( $callback, ...$args );
    }

    /**
     * Forward hash to its implementation.
     *
     * @param mixed $value Value.
     */
    public static function hash( $value ) {
        return Canonical_Data::hash( $value );
    }

    /**
     * Forward canonical to its implementation.
     *
     * @param mixed $value Value.
     */
    public static function canonical( $value ) {
        return Canonical_Data::canonical( $value );
    }

    /**
     * Forward object to its implementation.
     *
     * @param mixed $value Value.
     * @param mixed $label Label.
     */
    public static function object( $value, $label = 'object' ) {
        return Validation::object( $value, $label );
    }

    /**
     * Forward allowed to its implementation.
     *
     * @param array $value Value.
     * @param array $keys Keys.
     */
    public static function allowed( array $value, array $keys ) {
        return Validation::allowed( $value, $keys );
    }

    /**
     * Forward id to its implementation.
     *
     * @param mixed $value Value.
     */
    public static function id( $value ) {
        return Validation::id( $value );
    }

    /**
     * Forward ids to its implementation.
     *
     * @param mixed $value Value.
     */
    public static function ids( $value ) {
        return Validation::ids( $value );
    }

    /**
     * Forward paging to its implementation.
     *
     * @param array $args Validated tool input.
     */
    public static function paging( array $args ) {
        return Pagination::paging( $args );
    }

    /**
     * Forward page to its implementation.
     *
     * @param array $items Items.
     * @param array $args Validated tool input.
     */
    public static function page( array $items, array $args ) {
        return Pagination::page( $items, $args );
    }

    /**
     * Forward collection to its implementation.
     *
     * @param array $items Items.
     * @param mixed $total Total.
     * @param mixed $page Page.
     * @param mixed $size Size.
     */
    public static function collection( array $items, $total, $page, $size ) {
        return Pagination::collection( $items, $total, $page, $size );
    }

    /**
     * Forward version to its implementation.
     *
     * @param mixed $expected Version reviewed by the caller.
     * @param mixed $current Current native object version.
     */
    public static function version( $expected, $current ) {
        return Validation::version( $expected, $current );
    }

    /**
     * Forward taxonomy to its implementation.
     *
     * @param mixed $name Registered action or object name.
     */
    public static function taxonomy( $name ) {
        return Validation::taxonomy( $name );
    }

    /**
     * Forward directory to its implementation.
     *
     * @param mixed $id Id.
     */
    public static function directory( $id ) {
        return Validation::directory( $id );
    }

    /**
     * Forward clean text to its implementation.
     *
     * @param mixed $value Value.
     * @param mixed $html Html.
     */
    public static function clean_text( $value, $html = false ) {
        return Validation::clean_text( $value, $html );
    }

    /**
     * Forward result to its implementation.
     *
     * @param array $data Data.
     */
    public static function result( array $data = [] ) {
        return Result::result( $data );
    }

    /**
     * Forward event to its implementation.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param mixed $outcome Outcome.
     * @param array $args Validated tool input.
     * @param mixed $code Code.
     */
    public static function event( $tool, $outcome, array $args = [], $code = '' ) {
        return Operation_Events::event( $tool, $outcome, $args, $code );
    }
}
