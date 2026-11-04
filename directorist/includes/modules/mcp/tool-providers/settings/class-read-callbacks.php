<?php
/**
 * Read Callbacks for settings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Settings;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Read Callbacks. */
class Read_Callbacks {
    /**
     * Read.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function read( string $tool, array $args ): array {
        Access_Control::admin();
        $registry = Settings_Schema::registry();
        $selected = Settings_Schema::select( $registry, $args );
        if ( 'get-settings' === $tool && ! isset( $args['keys'] ) ) {
            $selected = array_filter( $selected, static function ( $definition ) { return $definition['readable']; } );
        }
        $items = [];
        foreach ( $selected as $key => $definition ) {
            if ( 'get-settings' === $tool ) {
                Validation::check( $definition['readable'], __( 'Sensitive settings are available only in WordPress administration.', 'directorist' ), 'sensitive_field' );
                $value   = Settings_Schema::value( $definition );
                $items[] = [ 'key' => $key, 'value' => $value, 'default' => $definition['default'], 'has_override' => array_key_exists( $key, (array) get_option( 'atbdp_option', [] ) ), 'version' => Canonical_Data::hash( [ $key, $value ] ) ];
            } else {
                unset( $definition['field'], $definition['read'], $definition['write'], $definition['validate'], $definition['permission'], $definition['invalidate'], $definition['tables'] );
                if ( ! $definition['readable'] ) {
                    unset( $definition['default'] );
                }
                $definition = array_intersect_key( $definition, array_flip( [ 'key', 'label', 'group', 'owner', 'default', 'schema', 'readable', 'writable', 'resettable', 'classification', 'dependencies', 'storage_adapter', 'capability', 'contract_version' ] ) );
                $items[]    = $definition;
            }
        }
        $result = Pagination::page( $items, $args );
        $state  = [];
        foreach ( $selected as $key => $definition ) {
            if ( $definition['readable'] ) {
                $state[ $key ] = Settings_Schema::value( $definition );
            }
        }
        $result['version'] = Canonical_Data::hash( $state );
        return $result;
    }

    /**
     * Handle discover-settings through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function discover_settings( array $args = [] ): array {
        return self::read( 'discover-settings', $args );
    }

    /**
     * Handle get-settings through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_settings( array $args = [] ): array {
        return self::read( 'get-settings', $args );
    }
}
