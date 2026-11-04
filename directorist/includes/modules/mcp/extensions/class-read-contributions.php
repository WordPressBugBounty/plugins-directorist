<?php
/**
 * Read Contributions for read contributions.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Tool_Providers\Dashboard\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Read Contributions. */
class Read_Contributions {
    /** Identity and site scope come from the host, never contributor inputs. */

    /**
     * Validate inputs.
     *
     * @param array $schema Declared JSON schema.
     * @param mixed $domain Domain.
     * @param mixed $depth Depth.
     */
    public static function validate_inputs( array $schema, $domain, $depth = 0 ): void {
        foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $child ) {
            Validation::check( ! preg_match( '/^(?:user|author|customer|blog|site|network)(?:_id|_ids)?$/i', $key ) && ! in_array( $key, [ 'legacy_id', 'listing_ids', 'order_ids' ], true ), __( 'Identity and cross-site selectors are not supported by diagnostic tools.', 'directorist' ) );
            Validation::check( ! in_array( $key, [ 'listing_id', 'order_id' ], true ) || ( 'dashboard' === $domain && 0 === $depth && 'integer' === $child['type'] ), __( 'Object selectors require a top-level dashboard object permission check.', 'directorist' ) );
            Validation::check( 'scope' !== $key || ( 'dashboard' === $domain && 0 === $depth && 'string' === $child['type'] && ! empty( $child['enum'] ) && ! array_diff( $child['enum'], [ 'site', 'own' ] ) ), __( 'Diagnostic scope cannot be overridden.', 'directorist' ) );
            self::validate_inputs( $child, $domain, $depth + 1 );
        }
        if ( isset( $schema['items'] ) ) {
            self::validate_inputs( $schema['items'], $domain, $depth + 1 );
        }
    }

    /**
     * Object permission.
     *
     * @param array $args Validated tool input.
     */
    public static function object_permission( array $args ): void {
        $scope = Read_Callbacks::permission( '', $args );
        if ( isset( $args['listing_id'] ) ) {
            Access_Control::listing_domain();
            $post = Access_Control::listing( $args['listing_id'] );
            Validation::check( 'site' === $scope || (int) $post->post_author === get_current_user_id(), __( 'Listing not found.', 'directorist' ), 'not_found' );
            Validation::check( true === apply_filters( 'directorist_rest_check_permissions', true, 'read', $post->ID, ATBDP_POST_TYPE ), __( 'Listing not found.', 'directorist' ), 'not_found' );
        }
        if ( isset( $args['order_id'] ) ) {
            $order = Order_Policy::get( $args['order_id'] );
            Validation::check( 'site' === $scope || (int) $order->user_id === get_current_user_id(), __( 'Order not found.', 'directorist' ), 'not_found' );
        }
    }

    /**
     * Validate schema.
     *
     * @param array $schema Declared JSON schema.
     * @param mixed $depth Depth.
     */
    public static function validate_schema( array $schema, $depth = 0 ): void {
        Validation::check( strlen( wp_json_encode( $schema ) ) <= 65536, __( 'Read contribution schemas exceed the metadata limit.', 'directorist' ) );
        Validation::check( $depth <= 3, __( 'Read contributions permit at most three nested levels.', 'directorist' ) );
        Validation::allowed( $schema, [ 'type', 'properties', 'required', 'additionalProperties', 'items', 'maxItems', 'minItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'enum', 'pattern' ] );
        Validation::check( ! isset( $schema['pattern'] ) || ( is_string( $schema['pattern'] ) && strlen( $schema['pattern'] ) <= 256 && null !== Read_Support::safe_value( $schema['pattern'] ) ), __( 'Unsafe or oversized diagnostic schema pattern.', 'directorist' ) );
        foreach ( $schema['enum'] ?? [] as $value ) {
            Validation::check( null !== Read_Support::safe_value( $value ), __( 'Unsafe diagnostic schema value.', 'directorist' ) );
        }
        foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $child ) {
            Validation::check( preg_match( '/^[a-zA-Z][a-zA-Z0-9_.-]{0,99}$/', $key ) && ! preg_match( '/private|debug|token|path|socket|username|license|prefix/i', $key ), __( 'Private diagnostic properties are excluded.', 'directorist' ) );
            self::validate_schema( $child, $depth + 1 );
        }
        if ( isset( $schema['items'] ) ) {
            self::validate_schema( $schema['items'], $depth + 1 );
        }
        Validation::check( 'string' !== $schema['type'] || ( $schema['maxLength'] ?? 2049 ) <= 2048, __( 'Diagnostic strings must be bounded to 2048 characters.', 'directorist' ) );
    }

    /**
     * Descriptors.
     *
     * @param mixed $domain Domain.
     * @param array $args Validated tool input.
     * @param mixed $kind Kind.
     */
    public static function descriptors( $domain, array $args, $kind = null ): array {
        return array_filter(
            Tool_Registry::instance()->read_adapters( $domain, $args ), static function ( $d ) use ( $kind ) { return null === $kind || $kind === $d['kind'];
            }
        );
    }

    /**
     * Metadata.
     *
     * @param array $d Registered descriptor.
     */
    public static function metadata( array $d ): array {
        $ready = false;
        try {
            $ready = true === Callback_Invoker::extension_callback( $d['availability'] );
        } catch ( \Throwable $error ) {
            $ready = false;
        }
        return [ 'id' => $d['id'], 'owner' => $d['owner'], 'label' => $d['label'], 'kind' => $d['kind'], 'units' => $d['units'], 'range_semantics' => $d['range_semantics'], 'filters' => $d['filters'], 'contract_version' => $d['contract_version'], 'source_version' => $d['source_version'], 'schema' => $d['output_schema'], 'state' => $ready ? 'available' : 'unavailable' ];
    }

    /**
     * Read.
     *
     * @param array $d Registered descriptor.
     * @param array $args Validated tool input.
     */
    public static function read( array $d, array $args ): array {
        try {
            $current = self::descriptors( $d['domain'], $args );
            Validation::check( isset( $current[ $d['id'] ] ), __( 'This contribution is no longer permitted.', 'directorist' ), 'forbidden' );
            foreach ( [ 'directory_id', 'period', 'start_date', 'end_date', 'currency', 'compare_previous', 'series_kind' ] as $filter ) {
                Validation::check( ! isset( $args[ $filter ] ) || in_array( $filter, $d['filters'], true ), __( 'This source does not support the selected filter.', 'directorist' ), 'unsupported_operation' );
            }
            Validation::check( true === Callback_Invoker::extension_callback( $d['availability'] ), __( 'The optional source is unavailable.', 'directorist' ), 'extension_unavailable' );
            $value = Callback_Invoker::extension_callback( $d['read'], $args, get_current_user_id() );
            $value = Callback_Invoker::extension_callback( $d['project'], $value, $args, get_current_user_id() );
            Validation::unwrap( rest_validate_value_from_schema( $value ?: (object) [], $d['output_schema'] ) );
            $value = self::safe_projection( $value, $d['output_schema'], $d );
            Validation::check( null !== $value, __( 'Unsafe diagnostic output.', 'directorist' ) );
            Validation::unwrap( rest_validate_value_from_schema( $value ?: (object) [], $d['output_schema'] ) );
            return Read_Support::section( $value, $d['id'] . ':' . $d['source_version'] );
        } catch ( \Throwable $error ) {
            return Read_Support::section( null, $d['id'], 'unavailable', 'source_unavailable_or_invalid' );
        }
    }

    /**
     * Safe projection.
     *
     * @param mixed $value Value.
     * @param array $schema Declared JSON schema.
     * @param array $d Registered descriptor.
     * @param mixed $key Key.
     */
    private static function safe_projection( $value, array $schema, array $d, $key = '' ) {
        if ( 'object' === $schema['type'] ) {
            $out = [];
            foreach ( (array) $value as $field => $child ) {
                $out[ $field ] = self::safe_projection( $child, $schema['properties'][ $field ], $d, $field );
            }
            return $out;
        }
        if ( 'array' === $schema['type'] ) {
            return array_map(
                static function ( $child ) use ( $schema, $d ) { return self::safe_projection( $child, $schema['items'], $d );
                }, $value
            );
        }
        if ( 'dashboard' === $d['domain'] && 'guidance' === $d['kind'] && 'url' === $key && is_string( $value ) ) {
            $host     = wp_parse_url( $value, PHP_URL_HOST );
            $external = $host !== wp_parse_url( home_url(), PHP_URL_HOST );
            if ( $external && ! in_array( $host, $d['link_hosts'] ?? [], true ) ) {
                return null;
            }
            return Read_Support::safe_url( $value, $external );
        }
        return Read_Support::safe_value( $value );
    }
}
