<?php
/**
 * Context Callbacks for tools.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Context;

use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Runtime\Tool_Discovery;

defined( 'ABSPATH' ) || exit;

/** Context Callbacks. */
class Context_Callbacks {
    /** Context. */
    public static function context() {
        $tools  = [];
        $groups = [];
        foreach ( Core_Tool_Provider::definitions() as $name => $definition ) {
            if ( Tool_Discovery::available( $name, $definition ) ) {
                $tools[]  = 'directorist-' . $name;
                $groups[] = $definition['group'];
            }
        }
        return [
            'user'      => [ 'id' => (int) get_current_user_id(), 'name' => (string) wp_get_current_user()->display_name ],
            'site'      => [ 'name' => get_bloginfo( 'name' ), 'url' => home_url(), 'timezone' => wp_timezone_string(), 'directorist_version' => ATBDP_VERSION ],
            'enabled'   => true,
            'tools'     => $tools,
            'groups'    => array_values( array_unique( $groups ) ),
            'limits'    => [ 'page_default' => 20, 'page_maximum' => 100, 'bulk_maximum' => 50, 'confirmation_seconds' => 300, 'idempotency_seconds' => DAY_IN_SECONDS ],
            'next_step' => __( 'Discover the relevant schema and exact IDs before making changes. Preview consequential actions; execute with an idempotency key.', 'directorist' )
        ];
    }

    /**
     * Handle get-context through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_context( array $args = [] ): array {
        return self::context();
    }
}
