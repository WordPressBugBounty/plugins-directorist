<?php
/**
 * Callback Invoker for support.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Callback Invoker. */
class Callback_Invoker {
    /**
     * Extension callback.
     *
     * @param callable $callback Operation to invoke within the guarded context.
     * @param mixed ...$args Validated tool input.
     * @return mixed Extension callback result, preserved for contract validation.
     */
    public static function extension_callback( callable $callback, ...$args ) {
        try {
            $result = call_user_func_array( $callback, $args );
            Validation::check( ! is_wp_error( $result ), __( 'The extension rejected this operation. Check its supported fields and native workflow.', 'directorist' ), 'extension_failed' );
            return $result;
        } catch ( Failure $error ) {
            throw $error;
        } catch ( \Throwable $error ) {
            Validation::fail( 'extension_failed', __( 'The extension could not complete this operation. Rediscover supported tools or contact the site administrator.', 'directorist' ) );
        }
    }
}
