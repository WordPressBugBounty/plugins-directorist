<?php
/**
 * Order Policy for orders.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Orders;

use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Order Policy. */
class Order_Policy {
    /** Tables. */
    public static function tables(): array {
        global $wpdb;
        return [ $wpdb->prefix . 'directorist_orders', $wpdb->prefix . 'directorist_payments', $wpdb->prefix . 'directorist_refunds' ];
    }

    /** Ready. */
    public static function ready(): bool {
        global $wpdb;
        foreach ( self::tables() as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                return false;
            }
        }
        return function_exists( 'directorist_order_repository' );
    }

    /** Admission. */
    public static function admission(): bool {
        return is_user_logged_in() && current_user_can( 'read' ) && self::ready();
    }

    /**
     * Permission.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function permission( string $tool, array $args ): bool {
        Validation::check( self::ready(), __( 'The native order storage is unavailable. No migration is performed by MCP.', 'directorist' ), 'dependency_unavailable' );
        $self = [ 'get-order-schema', 'list-orders', 'get-order', 'list-order-payments', 'list-order-refunds', 'cancel-order', 'get-order-operation' ];
        if ( ! in_array( $tool, $self, true ) || 'management' === ( $args['scope'] ?? '' ) || ( isset( $args['customer_id'] ) && $args['customer_id'] !== get_current_user_id() ) ) {
            Access_Control::admin();
        } else {
            Validation::check( self::admission(), __( 'Native customer access is required.', 'directorist' ), 'forbidden' );
        }
        if ( isset( $args['order_id'] ) || isset( $args['legacy_id'] ) ) {
            self::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        }
        return true;
    }

    /**
     * Get.
     *
     * @param mixed $id Id.
     * @param mixed $legacy Legacy.
     */
    public static function get( $id, $legacy = null ): \stdClass {
        Validation::check( self::ready(), __( 'Order storage is unavailable.', 'directorist' ), 'dependency_unavailable' );
        Validation::check( ( null !== $id ) xor ( null !== $legacy ), __( 'Specify exactly one order_id or legacy_id.', 'directorist' ) );
        $repository = directorist_order_repository();
        if ( null !== $legacy ) {
            Validation::id( $legacy );
            $rows = $repository->get_query_builder()->where( 'legacy_id', $legacy )->limit( 2 )->get();
            Validation::check( count( $rows ) === 1, __( 'Order not found.', 'directorist' ), 'not_found' );
            $order = $rows[0];
        } else {
            $order = $repository->get_by_id( Validation::id( $id ) );
        }
        Validation::check( $order && ( current_user_can( 'manage_options' ) || (int) $order->user_id === get_current_user_id() ), __( 'Order not found.', 'directorist' ), 'not_found' );
        return $order;
    }

    /**
     * Rows.
     *
     * @param int $index Index.
     * @param int $order_id Order id.
     */
    public static function rows( int $index, int $order_id ): array {
        global $wpdb;
        $table = self::tables()[ $index ];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is selected from the internal fixed table map.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC LIMIT 1001", $order_id ) );
        Validation::check( count( $rows ) <= 1000, __( 'Use the native administration tools to reconcile this unusually large ledger.', 'directorist' ), 'unsupported_operation' );
        return $rows;
    }

    /**
     * Version.
     *
     * @param \stdClass $order Order.
     */
    public static function version( \stdClass $order ): string {
        return Canonical_Data::hash( [ $order, self::rows( 1, $order->id ), self::rows( 2, $order->id ), get_directorist_option( 'featured_listing_price' ), get_directorist_option( 'featured_listing_time' ), directorist_currency(), self::balance( $order ), Tool_Registry::instance()->fingerprint() ] );
    }

    /**
     * Total.
     *
     * @param \stdClass $order Order.
     */
    public static function total( \stdClass $order ): string {
        return Money::stored( directorist_order_total_amount( $order ), $order->currency );
    }

    /**
     * Balance.
     *
     * @param \stdClass $order Order.
     * @param string $except Except.
     */
    public static function balance( \stdClass $order, string $except = '' ): array {
        $currency = $order->currency;
        $paid     = 0;
        $pending  = 0;
        foreach ( self::rows( 2, $order->id ) as $refund ) {
            $minor = Money::minor( Money::stored( $refund->amount, $currency ), $currency );
            if ( 'paid' === $refund->status ) {
                $paid += $minor;
            } elseif ( in_array( $refund->status, [ 'pending', 'processing' ], true ) ) {
                $pending += $minor;
            }
        }
        $pending += Financial_Operations::reserved( $order->id, $currency, $except );
        $total    = Money::minor( self::total( $order ), $currency );
        return [ 'settled' => Money::decimal( $paid, $currency ), 'reserved' => Money::decimal( $pending, $currency ), 'refundable' => Money::decimal( max( 0, $total - $paid - $pending ), $currency ), 'currency' => $currency ];
    }

    /**
     * Editable.
     *
     * @param \stdClass $order Order.
     */
    public static function editable( \stdClass $order ): bool {
        if ( ! in_array( $order->status, [ 'pending', 'unpaid', 'failed' ], true ) ) {
            return false;
        }
        foreach ( self::rows( 1, $order->id ) as $payment ) {
            if ( in_array( $payment->status, [ 'paid', 'refunded' ], true ) ) {
                return false;
            }
        }
        $balance = self::balance( $order );
        return 0 === Money::minor( $balance['settled'], $order->currency ) && 0 === Money::minor( $balance['reserved'], $order->currency );
    }

    /**
     * Actions.
     *
     * @param \stdClass $order Order.
     */
    public static function actions( \stdClass $order ): array {
        $actions = [];
        if ( ( 'pending' === $order->status && self::editable( $order ) ) || ( current_user_can( 'manage_options' ) && self::editable( $order ) ) ) {
            $actions[] = 'cancel';
        }
        if ( current_user_can( 'manage_options' ) && self::editable( $order ) ) {
            $actions = array_merge( $actions, [ 'pending', 'unpaid', 'failed', 'expired' ] );
            if ( directorist_is_monetization_enabled() ) {
                $actions[] = 'update';
            }
            if ( self::offline_ready() ) {
                $actions[] = 'record_offline_payment';
            }
        }
        if ( current_user_can( 'manage_options' ) && in_array( $order->status, [ 'paid', 'refunded' ], true ) && Money::minor( self::balance( $order )['refundable'], $order->currency ) > 0 ) {
            $actions[] = 'refund';
        }
        return $actions;
    }

    /**
     * Data.
     *
     * @param \stdClass $order Order.
     */
    public static function data( \stdClass $order ): array {
        $discount = Money::stored( directorist_compute_fixed_or_percent_amount( $order->coupon_discount_type, $order->coupon_discount, $order->sub_total ), $order->currency );
        $net      = Money::minor( Money::stored( $order->sub_total, $order->currency ), $order->currency ) - Money::minor( $discount, $order->currency );
        $tax      = Money::stored( directorist_compute_fixed_or_percent_amount( $order->tax_type, $order->tax_rate, (float) Money::decimal( max( 0, $net ), $order->currency ) ), $order->currency );
        $data     = [
            'order_id'        => (int) $order->id,
            'storage'         => 'native-tables-v1',
            'status'          => $order->status,
            'item_type'       => $order->ref_type,
            'currency'        => $order->currency,
            'discount'        => $discount,
            'tax'             => $tax,
            'expires_at'      => $order->expires_at,
            'subtotal'        => Money::stored( $order->sub_total, $order->currency ),
            'total'           => self::total( $order ),
            'created_at'      => $order->created_at,
            'updated_at'      => $order->updated_at,
            'allowed_actions' => self::actions( $order ),
            'version'         => self::version( $order ),
        ];
        if ( current_user_can( 'manage_options' ) ) {
            $data['customer_id'] = (int) $order->user_id;
            $data['legacy_id']   = isset( $order->legacy_id ) ? (int) $order->legacy_id : null;
        }
        if ( ! empty( $order->listing_id ) ) {
            try {
                $listing         = Access_Control::listing( (int) $order->listing_id );
                $data['listing'] = [ 'id' => $listing->ID, 'title' => $listing->post_title ];
            } catch ( Failure $error ) {
                $data['listing'] = [ 'restricted' => true ];
            }
        }
        return $data;
    }

    /** Featured ready. */
    public static function featured_ready(): bool {
        return directorist_is_monetization_enabled() && function_exists( 'directorist_is_featured_listing_enabled' ) && directorist_is_featured_listing_enabled( [ 'type' => 'featured_listing_checkout' ] ) && in_array( 'featured_listing', directorist_get_checkout_types(), true );
    }

    /** Offline ready. */
    public static function offline_ready(): bool {
        return directorist_is_monetization_enabled() && in_array( 'bank_transfer', (array) get_directorist_option( 'active_gateways', [ 'bank_transfer' ] ), true ) && function_exists( 'directorist_get_payment_processors' ) && isset( directorist_get_payment_processors()['bank_transfer'] );
    }
}
