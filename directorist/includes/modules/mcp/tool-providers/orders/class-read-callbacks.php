<?php
/**
 * Read Callbacks for orders.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Orders;

use Directorist\MCP\Registration\Tool_Registry;
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
        Order_Policy::permission( $tool, $args );
        if ( 'get-order-operation' === $tool ) {
            return Financial_Operations::read( $args );
        }
        if ( 'get-order-schema' === $tool ) {
            $order = isset( $args['order_id'] ) || isset( $args['legacy_id'] ) ? Order_Policy::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null ) : null;
            $staff = current_user_can( 'manage_options' );
            $items = $staff ? array_keys( Tool_Registry::instance()->fields( 'order_items' ) ) : [];
            if ( $staff && Order_Policy::featured_ready() ) {
                array_unshift( $items, 'featured_listing' );
            }
            $providers         = $staff ? Tool_Registry::instance()->fields( 'refund_providers', $order ) : [];
            $schema            = [ 'storage' => 'native-tables-v1', 'scope' => $staff ? [ 'management', 'own' ] : [ 'own' ], 'item_types' => $items, 'currency' => directorist_currency(), 'precision' => Money::precision( directorist_currency() ), 'native_write_precision_maximum' => 2, 'editable_fields' => $staff ? [ 'recalculate', 'item' ] : [], 'actions' => $order ? Order_Policy::actions( $order ) : [], 'offline_methods' => $staff && Order_Policy::offline_ready() ? [ 'bank_transfer' ] : [], 'refund_modes' => $staff ? ( $providers ? [ 'record_only', 'gateway' ] : [ 'record_only' ] ) : [], 'refund_providers' => array_keys( $providers ), 'limits' => [ 'page' => 100, 'bulk' => 50 ] ];
            $schema['filters'] = [ 'ids', 'status', 'listing_id', 'item_type', 'currency', 'created_after', 'created_before', 'search', 'order' ];
            if ( $staff ) {
                $schema['filters'][] = 'customer_id';
            }
            $schema['item_schemas'] = [];
            if ( in_array( 'featured_listing', $items, true ) ) {
                $schema['item_schemas']['featured_listing'] = [ 'type' => 'object', 'properties' => [ 'listing_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'listing_id' ], 'additionalProperties' => false ];
            }
            foreach ( $staff ? Tool_Registry::instance()->fields( 'order_items', $order ) : [] as $key => $definition ) {
                $schema['item_schemas'][ $key ] = $definition['schema'];
            }
            $schema['schema_version'] = Canonical_Data::hash( [ $schema, Tool_Registry::instance()->fingerprint(), get_directorist_option( 'featured_listing_price' ) ] );
            return $schema;
        }
        if ( 'list-orders' === $tool ) {
            return self::search( $args );
        }
        $order = Order_Policy::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        if ( 'get-order' === $tool ) {
            return Order_Policy::data( $order );
        }
        return self::history( $tool, $order, $args );
    }

    /**
     * Search.
     *
     * @param array $args Validated tool input.
     */
    public static function search( array $args ): array {
        global $wpdb;
        list( $page, $size ) = Pagination::paging( $args );
        if ( isset( $args['status'] ) ) {
            Validation::check( in_array( $args['status'], \Directorist\Enums\Order\Status::all(), true ), __( 'Unknown native order status.', 'directorist' ) );
        }
        $where  = [ '1=1' ];
        $values = [];
        if ( ! current_user_can( 'manage_options' ) || 'own' === ( $args['scope'] ?? '' ) ) {
            $where[]  = 'user_id = %d';
            $values[] = get_current_user_id();
        }
        foreach ( [ 'customer_id' => 'user_id', 'listing_id' => 'listing_id', 'status' => 'status', 'currency' => 'currency', 'item_type' => 'ref_type' ] as $key => $column ) {
            if ( isset( $args[ $key ] ) ) {
                $where[]  = $column . ' = %s';
                $values[] = $args[ $key ];
            }
        }
        foreach ( [ 'created_after' => '>=', 'created_before' => '<=' ] as $key => $operator ) {
            if ( isset( $args[ $key ] ) ) {
                Validation::check( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $args[ $key ] ), __( 'Use an ISO date or local date/time.', 'directorist' ) );
                $where[]  = 'created_at ' . $operator . ' %s';
                $values[] = $args[ $key ];
            }
        }
        if ( isset( $args['ids'] ) ) {
            $ids = Validation::ids( $args['ids'] );
            if ( ! $ids ) {
                return Pagination::collection( [], 0, $page, $size );
            }
            $where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
            $values  = array_merge( $values, $ids );
        }
        if ( ! empty( $args['search'] ) ) {
            $where[]  = '(CAST(id AS CHAR) LIKE %s OR status LIKE %s)';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = end( $values );
        }
        $table = Order_Policy::tables()[0];
        $sql   = ' WHERE ' . implode( ' AND ', $where );
        // phpcs:disable WordPress.DB.PreparedSQL -- Internal fixed identifiers/operators; all user values are prepared.
        $total     = $wpdb->get_var( $values ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table}" . $sql, $values ) : "SELECT COUNT(*) FROM {$table}" . $sql );
        $direction = 'asc' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
        $rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}" . $sql . " ORDER BY id {$direction} LIMIT %d OFFSET %d", array_merge( $values, [ $size, ( $page - 1 ) * $size ] ) ) );
        // phpcs:enable WordPress.DB.PreparedSQL
        return Pagination::collection( array_map( [ Order_Policy::class, 'data' ], $rows ), $total, $page, $size );
    }

    /**
     * History.
     *
     * @param string $tool Unprefixed tool action name.
     * @param \stdClass $order Order.
     * @param array $args Validated tool input.
     */
    public static function history( string $tool, \stdClass $order, array $args ): array {
        global $wpdb;
        list( $page, $size ) = Pagination::paging( $args );
        $refund              = 'list-order-refunds' === $tool;
        $table               = Order_Policy::tables()[ $refund ? 2 : 1 ];
        $where               = ' WHERE order_id = %d';
        $values              = [ (int) $order->id ];
        foreach ( [ 'status' => '=', 'created_after' => '>=', 'created_before' => '<=' ] as $key => $op ) {
            if ( isset( $args[ $key ] ) ) {
                $where   .= ' AND ' . ( 'status' === $key ? 'status' : 'created_at' ) . ' ' . $op . ' %s';
                $values[] = $args[ $key ];
            }
        }
        if ( ! empty( $args['search'] ) ) {
            $where   .= ' AND CAST(id AS CHAR) LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }
        // phpcs:disable WordPress.DB.PreparedSQL -- Internal fixed identifiers/operators; user values are prepared.
        $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}" . $where, $values ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}" . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $values, [ $size, ( $page - 1 ) * $size ] ) ) );
        // phpcs:enable WordPress.DB.PreparedSQL
        $items = [];
        foreach ( $rows as $row ) {
            $items[] = [ 'id' => (int) $row->id, 'status' => $row->status, 'amount' => Money::stored( $row->amount, $order->currency ), 'currency' => $order->currency, 'created_at' => $row->created_at ?? null ] + ( $refund ? [] : [ 'method' => $row->method ] );
        }
        $result = Pagination::collection( $items, $total, $page, $size );
        if ( $refund ) {
            $result['balance']         = Order_Policy::balance( $order );
            $result['settlement_note'] = __( 'A refund record alone does not prove gateway settlement.', 'directorist' );
        }
        return $result;
    }

    /**
     * References.
     *
     * @param array $args Validated tool input.
     */
    public static function references( array $args ): array {
        Access_Control::admin();
        list( $page, $size ) = Pagination::paging( $args );
        $query               = [ 'number' => $size, 'offset' => ( $page - 1 ) * $size, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => true ];
        if ( ! empty( $args['search'] ) ) {
            $query['search'] = '*' . $args['search'] . '*';
        }
        if ( isset( $args['ids'] ) ) {
            $ids = Validation::ids( $args['ids'] );
            if ( ! $ids ) {
                return Pagination::collection( [], 0, $page, $size );
            }
            $query['include'] = $ids;
        }
        $users = new \WP_User_Query( $query );
        return Pagination::collection(
            array_map(
                static function ( $user ) { return [ 'customer_id' => (int) $user->ID, 'label' => (string) $user->display_name ];
                }, $users->get_results()
            ), $users->get_total(), $page, $size
        );
    }

    /**
     * Handle get-order-schema through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_order_schema( array $args = [] ): array {
        return self::read( 'get-order-schema', $args );
    }

    /**
     * Handle list-orders through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_orders( array $args = [] ): array {
        return self::read( 'list-orders', $args );
    }

    /**
     * Handle get-order through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_order( array $args = [] ): array {
        return self::read( 'get-order', $args );
    }

    /**
     * Handle list-order-payments through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_order_payments( array $args = [] ): array {
        return self::read( 'list-order-payments', $args );
    }

    /**
     * Handle list-order-refunds through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_order_refunds( array $args = [] ): array {
        return self::read( 'list-order-refunds', $args );
    }

    /**
     * Handle get-order-operation through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_order_operation( array $args = [] ): array {
        return self::read( 'get-order-operation', $args );
    }
}
