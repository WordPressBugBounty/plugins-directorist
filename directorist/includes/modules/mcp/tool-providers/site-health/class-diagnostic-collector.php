<?php
/**
 * Diagnostic Collector for site health.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Site_Health;

use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Diagnostic Collector. */
class Diagnostic_Collector {
    const POLICY_VERSION = Section_Catalog::POLICY_VERSION;

    /**
     * Collect.
     *
     * @param string $id Id.
     */
    public static function collect( string $id ): array {
        global $wpdb;
        if ( 'wordpress' === $id ) {
            return [ 'version' => get_bloginfo( 'version' ), 'site_locale' => get_locale(), 'user_locale' => get_user_locale(), 'timezone' => wp_timezone_string(), 'permalink_mode' => get_option( 'permalink_structure' ) ? 'pretty' : 'plain', 'environment_type' => wp_get_environment_type(), 'https_configured' => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ), 'multisite' => is_multisite() ];
        }
        if ( 'runtime' === $id ) {
            return [ 'php_version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'observed_runtime' => PHP_SAPI === 'cli' ? 'cli' : 'request_worker', 'bitness' => PHP_INT_SIZE * 8, 'php_memory_limit' => ini_get( 'memory_limit' ), 'wp_memory_limit' => WP_MEMORY_LIMIT, 'wp_max_memory_limit' => WP_MAX_MEMORY_LIMIT, 'execution_limit_seconds' => (int) ini_get( 'max_execution_time' ), 'input_limit_seconds' => (int) ini_get( 'max_input_time' ), 'max_input_variables' => (int) ini_get( 'max_input_vars' ), 'upload_limit' => ini_get( 'upload_max_filesize' ), 'post_limit' => ini_get( 'post_max_size' ) ];
        }
        if ( 'server' === $id ) {
            $family       = function_exists( 'php_uname' ) ? php_uname( 's' ) : '';
            $architecture = function_exists( 'php_uname' ) ? php_uname( 'm' ) : '';
            $software     = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
            preg_match( '/^(Apache|nginx|LiteSpeed|Microsoft-IIS)(?:\/([0-9.]+))?/i', $software, $match );
            return [ 'os_family' => in_array( $family, [ 'Linux', 'Darwin', 'Windows NT', 'FreeBSD', 'OpenBSD' ], true ) ? $family : null, 'architecture' => in_array( $architecture, [ 'x86_64', 'arm64', 'aarch64', 'i386', 'i686' ], true ) ? $architecture : null, 'web_server_family' => isset( $match[1] ) ? $match[1] . ( isset( $match[2] ) ? ' ' . $match[2] : '' ) : null, 'curl_version' => function_exists( 'curl_version' ) ? curl_version()['version'] : null, 'openssl_version' => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : null ];
        }
        if ( 'database' === $id ) {
            $limits  = Read_Support::query( "SHOW VARIABLES WHERE Variable_name IN ('max_allowed_packet','max_connections')" );
            $limits  = array_column( $limits, 'Value', 'Variable_name' );
            $version = Read_Support::query( 'SELECT VERSION() version' );
            return [ 'engine_version' => $version[0]['version'], 'driver' => function_exists( 'mysqli_get_client_info' ) ? 'mysqli' : null, 'client_version' => function_exists( 'mysqli_get_client_info' ) ? mysqli_get_client_info() : null, 'max_packet_bytes' => isset( $limits['max_allowed_packet'] ) ? (int) $limits['max_allowed_packet'] : null, 'max_connections' => isset( $limits['max_connections'] ) ? (int) $limits['max_connections'] : null ];
        }
        if ( in_array( $id, [ 'plugins', 'plugins-inactive', 'themes', 'themes-inactive' ], true ) ) {
            return self::inventory( $id );
        }
        if ( 'media' === $id ) {
            $gd         = function_exists( 'gd_info' ) ? gd_info() : [];
            $imagick    = class_exists( '\Imagick' ) ? \Imagick::getVersion() : [];
            $formats    = [];
            $gd_formats = self::gd_formats( $gd );
            foreach ( [ 'JPEG', 'PNG', 'GIF', 'WEBP', 'AVIF' ] as $format ) {
                if ( in_array( strtolower( $format ), $gd_formats, true ) || ( class_exists( '\Imagick' ) && \Imagick::queryFormats( $format ) ) ) {
                    $formats[] = strtolower( $format );
                }
            }
            return [ 'gd_available' => function_exists( 'gd_info' ), 'gd_version' => $gd['GD Version'] ?? null, 'imagick_available' => class_exists( '\Imagick' ), 'imagick_version' => $imagick['versionString'] ?? null, 'supported_formats' => $formats, 'upload_limit_bytes' => (int) wp_max_upload_size() ];
        }
        if ( 'constants' === $id ) {
            return [ 'debug_enabled' => (bool) WP_DEBUG, 'debug_display_enabled' => (bool) WP_DEBUG_DISPLAY, 'debug_log_enabled' => (bool) WP_DEBUG_LOG, 'cache_enabled' => defined( 'WP_CACHE' ) ? (bool) WP_CACHE : false, 'cron_disabled' => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false, 'memory_limit' => WP_MEMORY_LIMIT ];
        }
        if ( 'filesystem' === $id ) {
            $uploads = wp_get_upload_dir();
            return [ 'wordpress_writable' => self::writable( ABSPATH ), 'content_writable' => self::writable( WP_CONTENT_DIR ), 'uploads_writable' => ! empty( $uploads['basedir'] ) ? self::writable( $uploads['basedir'] ) : null, 'plugins_writable' => self::writable( WP_PLUGIN_DIR ), 'themes_writable' => self::writable( get_theme_root() ) ];
        }
        if ( 'directorist' === $id ) {
            $integrations = [];
            foreach ( [ 'dashboard', 'site_health_info' ] as $domain ) {
                foreach ( Tool_Registry::instance()->read_adapters( $domain, [ 'scope' => 'site' ] ) as $definition ) {
                    $integrations[] = [ 'owner' => $definition['owner'], 'contract_version' => $definition['contract_version'], 'compatibility' => 'registered_contract_only' ];
                }
            }
            Validation::check( count( $integrations ) <= 100, __( 'Too many registered diagnostic contributions for this section.', 'directorist' ), 'unsupported_operation' );
            return [ 'version' => ATBDP_VERSION, 'directory_mode' => directorist_is_multi_directory_enabled() ? 'multiple' : 'single', 'order_storage_available' => Order_Policy::ready(), 'registered_integrations' => $integrations ];
        }
        if ( 'mcp' === $id ) {
            return [ 'enabled' => Request_Context::enabled(), 'ready' => Request_Context::dependencies(), 'abilities_available' => function_exists( 'wp_register_ability' ), 'adapter_version' => defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ? \WP\MCP\Core\McpAdapter::VERSION : null, 'read_adapter_version' => Tool_Registry::READ_ADAPTER_VERSION, 'transport' => 'http', 'authentication' => 'WordPress application authentication over HTTPS', 'auth_available' => function_exists( 'wp_is_application_passwords_available_for_user' ) && wp_is_application_passwords_available_for_user( wp_get_current_user() ), 'compatibility' => Request_Context::dependencies() ? 'supported' : 'unsupported', 'compatibility_rule' => 'WordPress 6.9 or newer; MCP Adapter 0.6.1 or newer; Abilities API present' ];
        }
        return [];
    }

    /**
     * Gd formats.
     *
     * @param array $gd Gd.
     */
    public static function gd_formats( array $gd ): array {
        $formats = [];
        foreach ( [ 'jpeg' => 'JPEG Support', 'png' => 'PNG Support', 'gif' => 'GIF Read Support', 'webp' => 'WebP Support', 'avif' => 'AVIF Support' ] as $format => $key ) {
            if ( ! empty( $gd[ $key ] ) || ( 'gif' === $format && ! empty( $gd['GIF Create Support'] ) ) ) {
                $formats[] = $format;
            }
        }
        return $formats;
    }

    /**
     * Writable.
     *
     * @param string $path Path.
     */
    public static function writable( string $path ): ?bool {
        // Restricted/missing paths are unknown; never emit PHP warnings containing a host path.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Read-only observation may be denied by open_basedir.
        if ( ! @is_dir( $path ) ) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Do not expose a restricted source path in warnings.
        return @is_writable( $path );
    }

    /**
     * Compatibility.
     *
     * @param mixed $wordpress Wordpress.
     * @param mixed $php Php.
     */
    public static function compatibility( $wordpress, $php ): array {
        $requirements = [ [ $wordpress, get_bloginfo( 'version' ) ], [ $php, PHP_VERSION ] ];
        $declared     = false;
        foreach ( $requirements as $requirement ) {
            if ( '' === $requirement[0] ) {
                continue;
            }
            if ( ! is_string( $requirement[0] ) || ! preg_match( '/^[0-9]+(?:\.[0-9]+){0,3}$/', $requirement[0] ) ) {
                return [ 'compatibility' => 'unknown', 'compatibility_rule' => 'unrecognized_manifest_minimums_v1' ];
            }
            $declared = true;
            if ( version_compare( $requirement[1], $requirement[0], '<' ) ) {
                return [ 'compatibility' => 'unsupported', 'compatibility_rule' => 'installed_manifest_wp_php_minimums_v1' ];
            }
        }
        return [ 'compatibility' => $declared ? 'supported' : 'unknown', 'compatibility_rule' => $declared ? 'installed_manifest_wp_php_minimums_only_v1' : 'no_declared_compatibility_rule_v1' ];
    }

    /**
     * Plugin inventory.
     *
     * @param array $sources Sources.
     * @param array $active Active.
     * @param mixed $updates Updates.
     * @param string|null $observed Observed.
     * @param bool $inactive Inactive.
     */
    public static function plugin_inventory( array $sources, array $active, $updates, ?string $observed, bool $inactive ): array {
        $items = [];
        foreach ( $sources as $category => $plugins ) {
            foreach ( $plugins as $key => $data ) {
                $is_mu   = 'must_use' === $category;
                $enabled = $is_mu || in_array( $key, $active, true );
                if ( $inactive === $enabled ) {
                    continue;
                }
                $items[] = [ 'id' => substr( hash( 'sha256', ( $is_mu ? 'must_use:' : '' ) . $key ), 0, 24 ), 'name' => Read_Support::safe_value( $data['Name'] ?? '' ), 'version' => Read_Support::safe_value( $data['Version'] ?? '' ), 'category' => $is_mu ? 'must_use' : ( $enabled ? 'active' : 'inactive' ), 'update_state' => ! $is_mu && $observed ? ( isset( $updates->response[ $key ] ) ? 'update_available' : ( isset( $updates->no_update[ $key ] ) ? 'observed_no_update' : 'unknown' ) ) : 'unknown', 'update_observed_at' => $is_mu ? null : $observed, 'update_age_seconds' => $is_mu || ! $observed ? null : max( 0, time() - strtotime( $observed ) ) ] + self::compatibility( $data['RequiresWP'] ?? '', $data['RequiresPHP'] ?? '' );
            }
        }
        return $items;
    }

    /**
     * Inventory.
     *
     * @param string $section Section.
     */
    public static function inventory( string $section ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin   = 0 === strpos( $section, 'plugins' );
        $inactive = false !== strpos( $section, 'inactive' );
        $updates  = get_site_transient( $plugin ? 'update_plugins' : 'update_themes' );
        $observed = is_object( $updates ) && isset( $updates->last_checked ) && is_numeric( $updates->last_checked ) && $updates->last_checked > 0 && $updates->last_checked <= time() ? gmdate( 'c', (int) $updates->last_checked ) : null;
        $items    = [];
        if ( $plugin ) {
            $active  = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
            $sources = [ 'plugin' => get_plugins(), 'must_use' => get_mu_plugins() ];
            return self::plugin_inventory( $sources, $active, $updates, $observed, $inactive );
        } else {
            $theme  = wp_get_theme();
            $active = [ $theme->get_stylesheet(), $theme->get_template() ];
            foreach ( wp_get_themes() as $key => $data ) {
                $enabled = in_array( $key, $active, true );
                if ( $inactive === $enabled ) {
                    continue;
                }
                $items[] = [ 'id' => substr( hash( 'sha256', $key ), 0, 24 ), 'name' => Read_Support::safe_value( $data->get( 'Name' ) ), 'version' => Read_Support::safe_value( $data->get( 'Version' ) ), 'category' => $key === $theme->get_stylesheet() ? 'active' : ( $enabled ? 'parent' : 'inactive' ), 'update_state' => $observed ? ( isset( $updates->response[ $key ] ) ? 'update_available' : ( isset( $updates->no_update[ $key ] ) ? 'observed_no_update' : 'unknown' ) ) : 'unknown', 'update_observed_at' => $observed, 'update_age_seconds' => $observed ? max( 0, time() - strtotime( $observed ) ) : null ] + self::compatibility( $data->get( 'RequiresWP' ), $data->get( 'RequiresPHP' ) );
            }
        }
        return $items;
    }
}
