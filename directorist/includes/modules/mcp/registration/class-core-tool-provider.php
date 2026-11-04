<?php
/**
 * Explicit composition of core feature tool providers.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Registration;

use Directorist\MCP\Extensions\Tool_Provider as Extensions_Tool_Provider;
use Directorist\MCP\Tool_Providers\Context\Tool_Provider;
use Directorist\MCP\Tool_Providers\Dashboard\Tool_Provider as Dashboard_Tool_Provider;
use Directorist\MCP\Tool_Providers\Directories\Tool_Provider as Directories_Tool_Provider;
use Directorist\MCP\Tool_Providers\Listings\Tool_Provider as Listings_Tool_Provider;
use Directorist\MCP\Tool_Providers\Media\Tool_Provider as Media_Tool_Provider;
use Directorist\MCP\Tool_Providers\Orders\Tool_Provider as Orders_Tool_Provider;
use Directorist\MCP\Tool_Providers\Settings\Tool_Provider as Settings_Tool_Provider;
use Directorist\MCP\Tool_Providers\Site_Health\Tool_Provider as Site_Health_Tool_Provider;
use Directorist\MCP\Tool_Providers\Taxonomies\Tool_Provider as Taxonomies_Tool_Provider;

defined( 'ABSPATH' ) || exit;

/** Shared inventory composition for schemas and internal callbacks. */
class Core_Tool_Provider {
    /** Return providers in historical catalog order. */
    public static function providers(): array {
        return [
            Tool_Provider::class,
            Settings_Tool_Provider::class,
            Directories_Tool_Provider::class,
            Listings_Tool_Provider::class,
            Taxonomies_Tool_Provider::class,
            Orders_Tool_Provider::class,
            Dashboard_Tool_Provider::class,
            Site_Health_Tool_Provider::class,
            Media_Tool_Provider::class,
            Extensions_Tool_Provider::class,
        ];
    }

    /** Resolve the registered public inventory (legacy Catalog API). */
    public static function definitions(): array {
        return Tool_Registry::instance()->definitions();
    }

    /** Compose unchanged core schemas; runtime callbacks are stored separately. */
    public static function core_definitions(): array {
        $definitions = [];
        foreach ( self::providers() as $provider ) {
            $definitions = array_merge( $definitions, $provider::definitions() );
        }

        foreach ( $definitions as $name => &$definition ) {
            if ( $definition['write'] && in_array( $definition['group'], [ 'listings', 'taxonomies', 'orders' ], true ) && 0 !== strpos( $name, 'bulk-' ) ) {
                $definition['schema']['properties']['extension_fields'] = [ 'type' => 'object', 'additionalProperties' => true ];
            }
        }
        return $definitions;
    }

    /** Compose executable strategies independently of public descriptors. */
    public static function callbacks(): array {
        $callbacks = [];
        foreach ( self::providers() as $provider ) {
            $callbacks = array_merge( $callbacks, $provider::callbacks() );
        }
        return $callbacks;
    }
}
