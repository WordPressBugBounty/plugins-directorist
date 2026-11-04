<?php
/**
 * Operation Events for support.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Support;

use Directorist\MCP\Runtime\Request_Context;

defined( 'ABSPATH' ) || exit;

/** Operation Events. */
class Operation_Events {
    /**
     * Event.
     *
     * @param string $tool Unprefixed tool action name.
     * @param string $outcome Outcome.
     * @param array $args Validated tool input.
     * @param string $code Code.
     */
    public static function event( string $tool, string $outcome, array $args = [], string $code = '' ): void {
        $ids = [];
        foreach ( [ 'listing_id', 'directory_id', 'term_id', 'order_id', 'payment_id' ] as $key ) {
            if ( isset( $args[ $key ] ) && is_int( $args[ $key ] ) ) {
                $ids[ $key ] = $args[ $key ];
            }
        }
        do_action(
            'directorist_mcp_operation_event', [
                'timestamp'  => gmdate( 'c' ),
                'site_id'    => get_current_blog_id(),
                'user_id'    => get_current_user_id(),
                'tool'       => $tool,
                'targets'    => $ids,
                'outcome'    => $outcome,
                'error_code' => $code,
                'request_id' => Request_Context::request_id()
            ]
        );
    }
}
