<?php
/**
 * Financial Data for dashboard data.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Dashboard;

use Directorist\MCP\Tool_Providers\Orders\Money;
use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Financial Data. */
class Financial_Data {
    const LISTING_BUDGET = Listing_Data::LISTING_BUDGET;

    /**
     * Financial.
     *
     * @param array $args Validated tool input.
     * @param array $range Range.
     * @param bool $series Series.
     */
    public static function financial( array $args, array $range, bool $series = false ): array {
        global $wpdb;
        Validation::check( Order_Policy::admission(), __( 'Order access is unavailable.', 'directorist' ), 'forbidden' );
        Validation::check( ! isset( $args['directory_id'] ), __( 'Financial sources have no supported directory allocation.', 'directorist' ), 'unsupported_operation' );
        $table = Order_Policy::tables()[0];
        $where = $wpdb->prepare( 'o.created_at >= FROM_UNIXTIME(%d) AND o.created_at < FROM_UNIXTIME(%d)', strtotime( $range['start_utc'] . ' UTC' ), strtotime( $range['end_exclusive_utc'] . ' UTC' ) );
        if ( 'own' === $args['scope'] ) {
            $where .= $wpdb->prepare( ' AND o.user_id=%d', get_current_user_id() );
        }
        $unambiguous = " AND (o.legacy_id IS NULL OR o.legacy_id=0 OR NOT EXISTS (SELECT 1 FROM {$table} other_order WHERE other_order.legacy_id=o.legacy_id AND other_order.id<>o.id))";
        $ambiguous   = Read_Support::query( "SELECT COUNT(*) count FROM {$table} o WHERE {$where} AND o.legacy_id>0 AND EXISTS (SELECT 1 FROM {$table} other_order WHERE other_order.legacy_id=o.legacy_id AND other_order.id<>o.id)" );
        $where      .= $unambiguous;
        $rows        = [];
        // Explicit UTC bounds per local day also work when MySQL timezone tables are absent.
        if ( $series ) {
            $case = 'CASE';
            $date = new \DateTimeImmutable( $range['start_date'], wp_timezone() );
            for ( $day = 0; $day < $range['days']; ++$day ) {
                $next  = $date->modify( '+1 day' );
                $case .= $wpdb->prepare( ' WHEN o.created_at >= FROM_UNIXTIME(%d) AND o.created_at < FROM_UNIXTIME(%d) THEN %s', $date->getTimestamp(), $next->getTimestamp(), $date->format( 'Y-m-d' ) );
                $date  = $next;
            }
            $case .= ' END';
            $rows  = Read_Support::query( "SELECT o.currency,o.status,{$case} local_date,SUM(o.amount) amount,COUNT(*) count FROM {$table} o WHERE {$where} GROUP BY o.currency,o.status,local_date ORDER BY o.currency,o.status,local_date" );
        } else {
            $rows = Read_Support::query( "SELECT o.currency,o.status,SUM(o.amount) amount,COUNT(*) count FROM {$table} o WHERE {$where} GROUP BY o.currency,o.status ORDER BY o.currency,o.status" );
        }
        $groups     = [];
        $daily      = [];
        $statuses   = [];
        $incomplete = (int) $ambiguous[0]['count'];
        foreach ( $rows as $row ) {
            $status = $row['status'];
            Validation::check( preg_match( '/^[a-z_]{1,32}$/', $status ), __( 'Unsupported stored order status.', 'directorist' ), 'unsupported_operation' );
            $statuses[ $status ] = ( $statuses[ $status ] ?? 0 ) + (int) $row['count'];
            if ( 'paid' !== $status ) {
                continue;
            }
            $currency = $row['currency'];
            try {
                $minor = Money::minor( Money::stored( $row['amount'], $currency ), $currency );
            } catch ( Failure $error ) {
                $incomplete += (int) $row['count'];
                continue;
            }
            if ( ! isset( $groups[ $currency ] ) ) {
                $groups[ $currency ] = [ 'currency' => $currency, 'precision' => Money::precision( $currency ), 'count' => 0, 'minor' => 0 ];
            }
            Validation::check( count( $groups ) <= 20, __( 'Too many currencies in the requested range.', 'directorist' ), 'unsupported_operation' );
            $groups[ $currency ]['count'] += (int) $row['count'];
            $groups[ $currency ]['minor'] += $minor;
            Validation::check( is_int( $groups[ $currency ]['minor'] ), __( 'Financial aggregate exceeds exact arithmetic bounds.', 'directorist' ), 'unsupported_operation' );
            if ( $series ) {
                $daily[ $currency ][ $row['local_date'] ] = $minor;
            }
        }
        $legacy_where = $wpdb->prepare( 'p.post_type=%s AND p.post_status=%s AND p.post_date_gmt >= %s AND p.post_date_gmt < %s', ATBDP_ORDER_POST_TYPE, 'publish', $range['start_utc'], $range['end_exclusive_utc'] );
        if ( 'own' === $args['scope'] ) {
            $legacy_where .= $wpdb->prepare( ' AND p.post_author=%d', get_current_user_id() );
        }
        $legacy      = Read_Support::query( "SELECT COUNT(*) count FROM {$wpdb->posts} p WHERE {$legacy_where} AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_payment_status' AND m.meta_value='completed') AND NOT EXISTS (SELECT 1 FROM {$table} o WHERE o.legacy_id=p.ID)" );
        $incomplete += (int) $legacy[0]['count'];
        foreach ( $groups as &$group ) {
            $group['amount'] = Money::decimal( $group['minor'], $group['currency'] );
            unset( $group['minor'] );
        }
        $result = [ 'range_kind' => 'period', 'meaning' => 'own' === $args['scope'] ? 'purchase_spending_order_value' : 'paid_order_value_not_cash_receipts', 'groups' => array_values( $groups ), 'by_status' => $statuses, 'coverage_complete' => 0 === $incomplete, 'coverage_reason' => $incomplete ? 'unmapped_legacy_or_unsupported_money' : null ];
        if ( current_user_can( 'manage_options' ) ) {
            $result['excluded_records'] = $incomplete;
        }
        if ( $series ) {
            $result['daily'] = $daily;
        }
        return $result;
    }

    /** Favorites. */
    public static function favorites(): int {
        $ids = directorist_get_user_favorites( get_current_user_id() );
        Validation::check( is_array( $ids ) && count( $ids ) <= self::LISTING_BUDGET, __( 'Favorites exceed the supported scope budget.', 'directorist' ), 'unsupported_operation' );
        $count = 0;
        foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
            $post = get_post( $id );
            if ( $post && ATBDP_POST_TYPE === $post->post_type && Access_Control::public_listing( $post ) && current_user_can( 'read_post', $id ) && true === apply_filters( 'directorist_rest_check_permissions', true, 'read', $id, ATBDP_POST_TYPE ) ) {
                ++$count;
            }
        }
        return $count;
    }
}
