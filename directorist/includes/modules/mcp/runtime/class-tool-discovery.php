<?php
/**
 * Filter registered tools by the current account and active extension policies.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Runtime;

use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Support\Failure;

defined( 'ABSPATH' ) || exit;

/** Filter registered tools by the current account and active extension policies. */
class Tool_Discovery {
    /**
     * Available.
     *
     * @param string $name Registered action or object name.
     * @param array $definition Registered descriptor.
     */
    public static function available( string $name, array $definition ): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        if ( ! empty( $definition['write'] ) && ! \Directorist\MCP\Safety\Write_Readiness::status( \Directorist\MCP\Safety\Write_Readiness::participants( $definition ) )['ready'] ) {
            return false;
        }
        if ( ! Tool_Registry::instance()->available( $name ) ) {
            return false;
        }
        if ( isset( $definition['owner'] ) ) {
            return true;
        }
        try {
            return Tool_Registry::instance()->discover_core( $name );
        } catch ( Failure $error ) {
            return false;
        }
    }
}
