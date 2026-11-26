<?php
/** Resolve core command context and extension-provided activity attribution. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

class Actor {
    private static $overrides = [];

    private static $requests = [];

    public static function register_hooks() {
        add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'enter_request' ], 0, 3 );
        add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'leave_request' ], PHP_INT_MAX, 3 );
    }

    public static function enter_request( $response, $handler, $request ) {
        /**
         * Supply activity attribution for an extension's resolved REST handler.
         *
         * Extensions should identify their own handler and verified payment flow;
         * actor values supplied by a request must not be trusted.
         *
         * @param string           $actor   Extension actor; defaults to system.
         * @param array            $handler Resolved REST endpoint handler.
         * @param \WP_REST_Request $request Current REST request.
         */
        $actor = self::normalize( apply_filters( 'directorist_order_activity_request_actor', 'system', $handler, $request ) );
        if ( 'system' === $actor && preg_match( '#^/directorist/v[12]/admin/(?:orders|payments)(?:/|$)#', $request->get_route() ) ) {
            $actor = 'admin';
        }
        self::$requests[] = [ 'request' => $request, 'handler' => $handler, 'actor' => $actor ];
        return $response;
    }

    public static function leave_request( $response, $handler, $request ) {
        for ( $i = count( self::$requests ) - 1; $i >= 0; --$i ) {
            if ( self::$requests[ $i ]['request'] === $request ) {
                array_splice( self::$requests, $i, 1 );
                break;
            }
        }
        return $response;
    }

    public static function run( $actor, callable $callback ) {
        self::$overrides[] = $actor;
        try {
            return $callback();
        } finally {
            array_pop( self::$overrides );
        }
    }

    public static function resolve() {
        global $wp_current_filter;
        $request = self::$requests ? end( self::$requests ) : [];
        $actor   = self::$overrides ? end( self::$overrides ) : ( $request['actor'] ?? 'system' );
        $context = [
            'request' => $request['request'] ?? null,
            'handler' => $request['handler'] ?? null,
            'hooks'   => (array) $wp_current_filter,
        ];
        /**
         * Supply activity attribution for an extension's current execution context.
         *
         * Return system to retain the core command/request context. Extensions can
         * return admin or payment_gateway for their own verified execution paths.
         * No provider classes, routes or hook names are interpreted by core.
         *
         * @param string $actor   Extension actor; defaults to system on every call.
         * @param array  $context Current request, resolved handler and active hook stack.
         */
        $extension_actor = apply_filters( 'directorist_order_activity_actor', 'system', $context );
        return self::normalize( 'system' === $extension_actor ? $actor : $extension_actor );
    }

    private static function normalize( $actor ): string {
        return in_array( $actor, [ 'admin', 'system', 'payment_gateway' ], true ) ? $actor : 'system';
    }
}
