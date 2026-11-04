<?php
/**
 * Domain Fields for domain fields.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Safety\Transaction;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Domain Fields. */
class Domain_Fields {
    /**
     * Context.
     *
     * @param string $domain Registered native data domain.
     * @param array $args Validated tool input.
     * @return array|\WP_Post|\WP_Term|\stdClass|\WP_Error|null Native object or creation arguments supplied to registered field adapters.
     */
    private static function context( $domain, array $args ) {
        if ( 'listings' === $domain && isset( $args['listing_id'] ) ) {
            return Access_Control::listing( $args['listing_id'] );
        }
        if ( 'orders' === $domain && ( isset( $args['order_id'] ) || isset( $args['legacy_id'] ) ) ) {
            return Order_Policy::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        }
        if ( 'taxonomies' === $domain && isset( $args['term_id'] ) ) {
            Access_Control::term_cap( $args['taxonomy'], 'read' );
            return get_term( $args['term_id'], $args['taxonomy'] );
        }
        return $args;
    }

    /**
     * Read.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param mixed $domain Domain.
     * @param array $result Result.
     */
    public static function read( $tool, array $args, $domain, array $result ): array {
        if ( ! in_array( $tool, [ 'get-listing', 'get-listing-schema', 'get-term', 'list-taxonomies', 'get-order', 'get-order-schema' ], true ) || 'public' === ( $args['scope'] ?? '' ) ) {
            return $result;
        }
        $context = self::context( $domain, $args );
        $schema  = [];
        $values  = [];
        foreach ( Tool_Registry::instance()->fields( $domain, $context ) as $key => $d ) {
            $schema[ $key ] = [ 'owner' => $d['owner'], 'key' => $key, 'schema' => $d['schema'], 'default' => $d['default'], 'dependencies' => $d['dependencies'] ?? [], 'contract_version' => $d['contract_version'], 'readable' => true, 'writable' => ! empty( $d['writable'] ) && true === Callback_Invoker::extension_callback( $d['permission'], $context, 'write' ) ];
            if ( in_array( $tool, [ 'get-listing', 'get-term', 'get-order' ], true ) ) {
                $value = Validation::unwrap( Callback_Invoker::extension_callback( $d['read'], $context ) );
                Validation::unwrap( rest_validate_value_from_schema( $value, $d['schema'] ) );
                $values[ $key ] = $value;
            }
        }
        if ( $schema ) {
            $result['extension_schema'] = $schema;
        }
        if ( $values ) {
            $result['extension_fields'] = $values;
        }
        return $result;
    }

    /**
     * Prepare.
     *
     * @param mixed $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param mixed $domain Domain.
     * @param callable $native Native.
     */
    public static function prepare( $tool, array $args, $domain, callable $native ): array {
        $changes = Validation::object( $args['extension_fields'] ?? [] );
        unset( $args['extension_fields'] );
        $plan = $native( $args );
        if ( ! $changes ) {
            return $plan;
        }
        Validation::check( in_array( $tool, [ 'create-listing', 'update-listing', 'create-term', 'update-term', 'create-order', 'update-order' ], true ) && empty( $plan['orchestrated'] ), __( 'Extension fields cannot be combined with lifecycle or financial actions.', 'directorist' ), 'unsupported_operation' );
        $context = self::context( $domain, $args );
        $fields  = Tool_Registry::instance()->fields( $domain, $context );
        Validation::allowed( $changes, array_keys( $fields ) );
        $values   = [];
        $before   = [];
        $tables   = $plan['tables'] ?? [];
        $adapters = [];
        foreach ( $changes as $key => $value ) {
            $d = $fields[ $key ];
            Validation::check( ! empty( $d['writable'] ) && true === Callback_Invoker::extension_callback( $d['permission'], $context, 'write' ) && empty( $d['external_effects'] ), __( 'This extension field is not writable.', 'directorist' ), 'forbidden' );
            Validation::unwrap( rest_validate_value_from_schema( $value, $d['schema'] ) );
            $values[ $key ] = Validation::unwrap( Callback_Invoker::extension_callback( $d['validate'], $value, $context ) );
            Validation::unwrap( rest_validate_value_from_schema( $values[ $key ], $d['schema'] ) );
            $before[ $key ] = Validation::unwrap( Callback_Invoker::extension_callback( $d['read'], $context ) );
            // Only native WordPress metadata/option participants can join a core logical unit.
            Validation::check( 'directorist' === $d['storage_adapter'], __( 'Independent extension stores require a separate registered tool.', 'directorist' ), 'unsupported_operation' );
            $tables     = array_merge( $tables, $d['tables'] );
            $adapters[] = $d;
        }
        Transaction::check_tables( $tables );
        $execute                             = $plan['execute'];
        $plan['state']                       = [ $plan['state'], $before, Tool_Registry::instance()->fingerprint() ];
        $plan['preview']['extension_fields'] = [ 'before' => $before, 'after' => $values ];
        $plan['confirm']                     = true;
        $plan['tables']                      = array_values( array_unique( $tables ) );
        $plan['invalidate']                  = static function () use ( $adapters ) {
            foreach ( $adapters as $d ) {
                Callback_Invoker::extension_callback( $d['invalidate'] );
            }
        };
        $plan['execute']                     = static function () use ( $execute, $values, $fields, $args, $domain ) {
            $result  = $execute();
            $current = $args;
            foreach ( [ 'listing_id', 'term_id', 'order_id' ] as $id ) {
                if ( isset( $result[ $id ] ) ) {
                    $current[ $id ] = $result[ $id ];
                }
            }
            $context = self::context( $domain, $current );
            foreach ( $values as $key => $value ) {
                $d = $fields[ $key ];
                Validation::check( Tool_Registry::instance()->admitted( $d['owner'] ) && true === Callback_Invoker::extension_callback( $d['permission'], $context, 'write' ), __( 'Extension field access changed.', 'directorist' ), 'forbidden' );
                Validation::unwrap( Callback_Invoker::extension_callback( $d['write'], $context, $value ) );
            }
            return $result;
        };
        return $plan;
    }

    /** Settings. */
    public static function settings(): array {
        $out = [];
        foreach ( Tool_Registry::instance()->fields( 'settings' ) as $key => $d ) {
            $d['writable']   = ! empty( $d['writable'] ) && true === Callback_Invoker::extension_callback( $d['permission'], null, 'write' );
            $d['resettable'] = $d['writable'] && ! empty( $d['resettable'] );
            $out[ $key ]     = $d + [ 'write' => static function () { return new \WP_Error( 'read_only', 'Read-only field.' );
            },
                'storage_adapter'             => 'directorist'
            ] + [ 'capability' => 'manage_options', 'resettable' => false ];
        }
        return $out;
    }
}
