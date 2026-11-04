<?php
/** Directorist error projection around the supported adapter HTTP transport.
 * @package Directorist\MCP
 */
namespace Directorist\MCP\Transport;

defined( 'ABSPATH' ) || exit;

class Http_Transport extends \WP\MCP\Transport\HttpTransport {
    public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
        Tool_Errors::begin();
        try {
            return Tool_Errors::response( parent::handle_request( $request ) );
        } finally {
            Tool_Errors::finish();
        }
    }
}
