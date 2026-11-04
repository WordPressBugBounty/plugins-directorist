<?php
/** Preserve safe error details only while serving Directorist's HTTP transport.
 * @package Directorist\MCP
 */
namespace Directorist\MCP\Transport;

use Directorist\MCP\Runtime\Request_Context;

defined( 'ABSPATH' ) || exit;

class Tool_Errors {
    private static bool $active = false;

    private static ?array $payload = null;

    public static function begin(): void {
        self::$active  = true;
        self::$payload = null;
    }

    public static function finish(): void {
        self::$active  = false;
        self::$payload = null;
    }

    /** Observe permission errors without changing the ability/PHP contract. */
    public static function capture( $result ) {
        if ( self::$active && is_wp_error( $result ) ) {
            $data = (array) $result->get_error_data();
            // Adapter/Abilities errors may contain internal data; only our safe
            // failures supply recovery instructions and operation identities.
            $safe          = 0 === strpos( (string) $result->get_error_code(), 'directorist_mcp_' );
            $code          = $safe ? ( $data['code'] ?? $result->get_error_code() ) : $result->get_error_code();
            self::$payload = [
                'code'       => is_string( $code ) ? $code : 'operation_failed',
                'message'    => $safe ? $result->get_error_message() : __( 'The tool input or permission check failed.', 'directorist' ),
                'committed'  => false,
                'retryable'  => false,
                'request_id' => Request_Context::request_id(),
                'attempt_id' => Request_Context::request_id(),
            ];
            if ( $safe ) {
                foreach ( [ 'committed', 'retryable', 'operation_id', 'request_id', 'next_step' ] as $key ) {
                    if ( isset( $data[ $key ] ) && ( is_string( $data[ $key ] ) || is_bool( $data[ $key ] ) ) ) {
                        self::$payload[ $key ] = $data[ $key ];
                    }
                }
            }
        }
        return $result;
    }

    /** Supported adapter result hook, scoped to this server and its tools. */
    public static function result( $result, $args, $name, $tool, $server ) {
        if ( 'directorist' === $server->get_server_id() && 0 === strpos( $name, 'directorist-' ) ) {
            return self::capture( $result );
        }
        return $result;
    }

    /** Enrich the adapter's error envelope after it has set isError. */
    public static function response( \WP_REST_Response $response ): \WP_REST_Response {
        // Adapter 0.6.x returns arrays; 0.7.x may return schema Record objects.
        $data = self::$payload ? json_decode( wp_json_encode( $response->get_data() ), true ) : null;
        if ( self::$payload && is_array( $data ) && true === ( $data['result']['isError'] ?? false ) ) {
            $data['result']['structuredContent'] = self::$payload;
            $data['result']['content']           = [ [ 'type' => 'text', 'text' => wp_json_encode( self::$payload ) ] ];
            $response->set_data( $data );
        }
        return $response;
    }
}
