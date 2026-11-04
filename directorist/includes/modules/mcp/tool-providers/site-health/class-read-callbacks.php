<?php
/**
 * Read Callbacks for site health.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Site_Health;

use Directorist\MCP\Extensions\Read_Contributions;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Read Callbacks. */
class Read_Callbacks {
    const POLICY_VERSION = Section_Catalog::POLICY_VERSION;

    /**
     * Read.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function read( string $tool, array $args ): array {
        Section_Catalog::permission();
        $sections = Section_Catalog::descriptors();
        $base     = Read_Support::envelope( hash_hmac( 'sha256', (string) get_current_blog_id(), wp_salt( 'nonce' ) ) );
        $base    += [ 'source_versions' => [ 'wordpress' => Read_Support::safe_value( get_bloginfo( 'version' ) ), 'directorist' => ATBDP_VERSION, 'collector' => 'local-safe-2' ], 'redaction_policy_version' => self::POLICY_VERSION ];
        if ( isset( $args['section_id'] ) ) {
            Validation::check( isset( $sections[ $args['section_id'] ] ), __( 'This section is unsupported or forbidden.', 'directorist' ), 'forbidden' );
        }
        if ( 'discover-site-health-info' === $tool ) {
            $items = [];
            if ( isset( $args['section_id'] ) ) {
                $definition = $sections[ $args['section_id'] ];
                ksort( $definition['fields'] );
                foreach ( $definition['fields'] as $key => $type ) {
                    $schema  = $definition['adapter']['output_schema']['properties'][ $key ] ?? Section_Catalog::field_schema( $definition['id'], $key, $type );
                    $items[] = [ 'key' => $key, 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'type' => $type, 'schema' => $schema, 'units' => self::units( $key ), 'sensitivity' => 'public', 'nullable' => true ];
                }
            } else {
                foreach ( $sections as $definition ) {
                    unset( $definition['fields'], $definition['adapter'] );
                    $items[] = $definition;
                }
            }
            return $base + Pagination::page( $items, $args ) + [ 'limits' => [ 'page_default' => 20, 'page_maximum' => 100, 'report_sections' => 10, 'report_items' => 1000, 'report_bytes' => 262144, 'scalar_characters' => 2048 ], 'report_formats' => [ 'json', 'text' ] ];
        }
        if ( 'get-site-health-info' === $tool ) {
            $definition = $sections[ $args['section_id'] ];
            // Validate explicit selection before any collector runs.
            $keys = isset( $args['field_keys'] ) ? Read_Support::selected( $args, 'field_keys', [], array_keys( $definition['fields'] ), 100 ) : array_keys( $definition['fields'] );
            return Read_Support::bounded( $base + [ 'section' => self::section( $definition, $args, $keys ) ] );
        }
        $selected = Read_Support::selected( $args, 'section_ids', array_values( array_intersect( [ 'wordpress', 'runtime', 'directorist', 'mcp' ], array_keys( $sections ) ) ), array_keys( $sections ), 10 );
        $report   = [];
        $count    = 0;
        foreach ( $selected as $key ) {
            $definition = $sections[ $key ];
            $section    = self::section( $definition, [ 'page' => 1, 'per_page' => 100 ], array_keys( $definition['fields'] ), true );
            $count     += $section['total'];
            Validation::check( $count <= 1000, __( 'Report exceeds 1000 fields/items. Select fewer sections.', 'directorist' ) );
            $report[] = $section;
        }
        return Report_Formatter::format( $base, $report, $selected, $args );
    }

    /**
     * Section.
     *
     * @param array $definition Registered descriptor.
     * @param array $args Validated tool input.
     * @param array $keys Keys.
     * @param bool $report Report.
     */
    public static function section( array $definition, array $args, array $keys, bool $report = false ): array {
        $id     = $definition['id'];
        $state  = 'available';
        $reason = null;
        if ( isset( $definition['adapter'] ) ) {
            $source = Read_Contributions::read( $definition['adapter'], [ 'scope' => $definition['source_scope'] ] );
            $values = $source['data'] ?? [];
            $state  = $source['state'];
            $reason = $source['reason'];
        } else {
            try {
                $values = Diagnostic_Collector::collect( $id );
            } catch ( \Throwable $error ) {
                if ( in_array( $id, [ 'wordpress', 'runtime' ], true ) ) {
                    throw new Failure( 'dependency_unavailable', __( 'A required diagnostic collector is unavailable.', 'directorist' ) );
                }
                $values = [];
                $state  = 'unavailable';
                $reason = 'source_unavailable';
            }
        }
        $items = [];
        if ( 'storage' === $id ) {
            $state  = 'unavailable';
            $reason = 'no_supported_preexisting_size_snapshot';
        }
        if ( 'collection' === $definition['kind'] ) {
            // Sort the complete safe row before optional field projection removes its identity.
            usort(
                $values, static function ( $a, $b ) { return strcmp( $a['id'] ?? '', $b['id'] ?? '' );
                }
            );
            foreach ( $values as $value ) {
                $items[] = array_intersect_key( $value, array_flip( $keys ) );
            }
            if ( ! $items && 'available' === $state ) {
                $state = 'empty';
            }
        } else {
            sort( $keys );
            foreach ( $keys as $key ) {
                $raw   = $values[ $key ] ?? null;
                $value = Read_Support::safe_value( $raw );
                if ( null !== $value && is_wp_error( rest_validate_value_from_schema( $value, $definition['adapter']['output_schema']['properties'][ $key ] ?? Section_Catalog::field_schema( $id, $key, $definition['fields'][ $key ] ) ) ) ) {
                    $value = null;
                }
                $items[] = [ 'key' => $key, 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'units' => self::units( $key ), 'type' => $definition['fields'][ $key ], 'value' => $value, 'state' => null === $value ? ( null === $raw ? 'unavailable' : 'redacted' ) : ( [] === $value || '' === $value ? 'empty' : 'available' ) ];
            }
        }
        if ( $report ) {
            Validation::check( count( $items ) <= 1000, __( 'This inventory is too large for a support report. Read it in pages.', 'directorist' ) );
            $collection = Pagination::collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
        } else {
            $collection = Pagination::page( $items, $args );
        }
        return [ 'id' => $id, 'source_scope' => $definition['source_scope'], 'state' => $state, 'reason' => $reason, 'as_of' => gmdate( 'c' ), 'cached' => false, 'age_seconds' => 0, 'source_version' => $definition['source_version'] ] + $collection;
    }

    /**
     * Units.
     *
     * @param string $key Key.
     */
    public static function units( string $key ): ?string {
        return false !== strpos( $key, '_seconds' ) ? 'seconds' : ( false !== strpos( $key, '_bytes' ) ? 'bytes' : null );
    }

    /**
     * Handle discover-site-health-info through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function discover_site_health_info( array $args = [] ): array {
        return self::read( 'discover-site-health-info', $args );
    }

    /**
     * Handle get-site-health-info through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_site_health_info( array $args = [] ): array {
        return self::read( 'get-site-health-info', $args );
    }

    /**
     * Handle get-site-health-report through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_site_health_report( array $args = [] ): array {
        return self::read( 'get-site-health-report', $args );
    }
}
