<?php
/**
 * Section Catalog for site health.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Site_Health;

use Directorist\MCP\Extensions\Read_Contributions;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Section Catalog. */
class Section_Catalog {
    const POLICY_VERSION = '1.1';

    /** Available. */
    public static function available(): bool {
        return is_user_logged_in() && current_user_can( 'manage_options' ) && current_user_can( 'view_site_health_checks' );
    }

    /** Permission. */
    public static function permission(): void {
        Validation::check( self::available(), __( 'Directorist administration and native Site Health access are required.', 'directorist' ), 'forbidden' );
    }

    /** Core fields. */
    public static function core_fields(): array {
        return [
            'wordpress'        => [ 'version' => 'string', 'site_locale' => 'string', 'user_locale' => 'string', 'timezone' => 'string', 'permalink_mode' => 'string', 'environment_type' => 'string', 'https_configured' => 'boolean', 'multisite' => 'boolean' ],
            'runtime'          => [ 'php_version' => 'string', 'sapi' => 'string', 'observed_runtime' => 'string', 'bitness' => 'integer', 'php_memory_limit' => 'string', 'wp_memory_limit' => 'string', 'wp_max_memory_limit' => 'string', 'execution_limit_seconds' => 'integer', 'input_limit_seconds' => 'integer', 'max_input_variables' => 'integer', 'upload_limit' => 'string', 'post_limit' => 'string' ],
            'server'           => [ 'os_family' => 'string', 'architecture' => 'string', 'web_server_family' => 'string', 'curl_version' => 'string', 'openssl_version' => 'string' ],
            'database'         => [ 'engine_version' => 'string', 'driver' => 'string', 'client_version' => 'string', 'max_packet_bytes' => 'integer', 'max_connections' => 'integer' ],
            'themes'           => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'themes-inactive'  => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'plugins'          => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'plugins-inactive' => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'media'            => [ 'gd_available' => 'boolean', 'gd_version' => 'string', 'imagick_available' => 'boolean', 'imagick_version' => 'string', 'supported_formats' => 'array', 'upload_limit_bytes' => 'integer' ],
            'constants'        => [ 'debug_enabled' => 'boolean', 'debug_display_enabled' => 'boolean', 'debug_log_enabled' => 'boolean', 'cache_enabled' => 'boolean', 'cron_disabled' => 'boolean', 'memory_limit' => 'string' ],
            'filesystem'       => [ 'wordpress_writable' => 'boolean', 'content_writable' => 'boolean', 'uploads_writable' => 'boolean', 'plugins_writable' => 'boolean', 'themes_writable' => 'boolean' ],
            'storage'          => [],
            'directorist'      => [ 'version' => 'string', 'directory_mode' => 'string', 'order_storage_available' => 'boolean', 'registered_integrations' => 'array' ],
            'mcp'              => [ 'enabled' => 'boolean', 'ready' => 'boolean', 'abilities_available' => 'boolean', 'adapter_version' => 'string', 'read_adapter_version' => 'integer', 'transport' => 'string', 'authentication' => 'string', 'auth_available' => 'boolean', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
        ];
    }

    /**
     * Field schema.
     *
     * @param string $id Id.
     * @param string $key Key.
     * @param string $type Type.
     */
    public static function field_schema( string $id, string $key, string $type ): array {
        if ( 'array' === $type ) {
            $item = 'registered_integrations' === $key ? [ 'type' => 'object', 'properties' => [ 'owner' => Read_Support::string_schema(), 'contract_version' => Read_Support::string_schema(), 'compatibility' => Read_Support::string_schema() ], 'additionalProperties' => false ] : Read_Support::string_schema();
            return [ 'type' => 'array', 'items' => $item, 'maxItems' => 100 ];
        }
        return 'string' === $type ? [ 'type' => 'string', 'maxLength' => 2048 ] : [ 'type' => $type ];
    }

    /** Descriptors. */
    public static function descriptors(): array {
        $sections = self::core_fields();
        if ( is_multisite() && ! is_super_admin() ) {
            foreach ( [ 'runtime', 'server', 'database', 'themes-inactive', 'plugins', 'plugins-inactive', 'media', 'constants', 'filesystem', 'storage' ] as $id ) {
                unset( $sections[ $id ] );
            }
        }
        $out = [];
        foreach ( $sections as $id => $fields ) {
            $out[ $id ] = [ 'id' => $id, 'label' => ucwords( str_replace( '-', ' ', $id ) ), 'kind' => in_array( $id, [ 'themes', 'themes-inactive', 'plugins', 'plugins-inactive' ], true ) ? 'collection' : 'fields', 'fields' => $fields, 'state' => 'storage' === $id ? 'unavailable' : 'available', 'reason' => 'storage' === $id ? 'no_supported_preexisting_size_snapshot' : null, 'source_scope' => 'site', 'source_version' => 'local-safe-collector-2' ];
        }
        $adapters = Read_Contributions::descriptors( 'site_health_info', [ 'scope' => 'site' ] );
        if ( is_multisite() && is_super_admin() ) {
            $adapters += Read_Contributions::descriptors( 'site_health_info', [ 'scope' => 'network' ] );
        }
        foreach ( $adapters as $key => $definition ) {
            if ( in_array( 'network', $definition['scopes'], true ) && is_multisite() && ! is_super_admin() ) {
                continue;
            }
            $fields = [];
            foreach ( (array) $definition['output_schema']['properties'] as $field => $schema ) {
                $fields[ $field ] = $schema['type'];
            }
            $availability = Read_Contributions::metadata( $definition )['state'];
            $out[ $key ]  = [ 'id' => $key, 'label' => $definition['label'], 'kind' => 'fields', 'fields' => $fields, 'state' => $availability, 'reason' => 'unavailable' === $availability ? 'source_unavailable' : null, 'source_scope' => in_array( 'site', $definition['scopes'], true ) ? 'site' : 'network', 'source_version' => $definition['source_version'], 'adapter' => $definition ];
        }
        ksort( $out );
        return $out;
    }
}
