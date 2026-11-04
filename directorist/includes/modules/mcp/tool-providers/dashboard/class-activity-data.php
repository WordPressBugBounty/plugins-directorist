<?php
/**
 * Activity Data for dashboard data.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Dashboard;

use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Activity Data. */
class Activity_Data {
    const LISTING_BUDGET = Listing_Data::LISTING_BUDGET;

    /**
     * Activity types.
     *
     * @param string $scope Scope.
     */
    public static function activity_types( string $scope ): array {
        $types = Read_Callbacks::listing_access() ? [ 'listing' ] : [];
        if ( Order_Policy::admission() ) {
            $types[] = 'payment';
        }
        if ( 'site' === $scope && current_user_can( 'moderate_comments' ) && Read_Callbacks::listing_access() ) {
            $types[] = 'review';
        }
        if ( 'site' === $scope && current_user_can( 'list_users' ) && ! is_multisite() ) {
            $types[] = 'user';
        }
        return $types;
    }

    /**
     * Event.
     *
     * @param string $source Source.
     * @param mixed $id Id.
     * @param string $type Type.
     * @param string $date Date.
     * @param bool $upcoming Upcoming.
     * @param string|null $tool Unprefixed tool action name.
     */
    public static function event( string $source, $id, string $type, string $date, bool $upcoming = false, ?string $tool = null ): array {
        return [ 'id' => $source . ':' . $id, 'object_id' => (int) $id, 'type' => $type, 'timestamp' => str_replace( ' ', 'T', $date ) . 'Z', 'upcoming' => $upcoming, 'detail_tool' => $tool ];
    }

    /**
     * Trim.
     *
     * @param array $items Items.
     */
    public static function trim( array &$items ): void {
        usort(
            $items, static function ( $a, $b ) { return $a['upcoming'] === $b['upcoming'] ? ( ( $a['upcoming'] ? strcmp( $a['timestamp'], $b['timestamp'] ) : strcmp( $b['timestamp'], $a['timestamp'] ) ) ?: ( strcmp( strstr( $a['id'], ':', true ), strstr( $b['id'], ':', true ) ) ?: $a['object_id'] <=> $b['object_id'] ) ) : ( $a['upcoming'] ? 1 : -1 );
            }
        );
        $items = array_slice( $items, 0, 1001 );
    }

    /**
     * Activity.
     *
     * @param array $args Validated tool input.
     * @param array $range Range.
     */
    public static function activity( array $args, array $range ): array {
        global $wpdb;
        $types    = self::activity_types( $args['scope'] );
        $selected = 'all' === ( $args['type'] ?? 'all' ) ? $types : [ $args['type'] ];
        Validation::check( ! array_diff( $selected, $types ), __( 'This activity type is not permitted.', 'directorist' ), 'forbidden' );
        Validation::check( ! isset( $args['directory_id'] ) || $selected === [ 'listing' ], __( 'Select listing activity for directory filtering.', 'directorist' ), 'unsupported_operation' );
        $items    = [];
        $upcoming = [];
        $as_of    = gmdate( 'c' );
        // Native sources have second precision. Include this second but never future events.
        $range['end_exclusive_utc'] = min( $range['end_exclusive_utc'], gmdate( 'Y-m-d H:i:s', strtotime( $as_of ) + 1 ) );
        if ( in_array( 'listing', $selected, true ) ) {
            $data     = Listing_Data::listings( $args, $range, true );
            $items    = $data['activity'];
            $upcoming = $data['upcoming'];
        }
        if ( in_array( 'payment', $selected, true ) ) {
            $order_tool = Read_Support::tool_reference( 'directorist-get-order' );
            $table      = Order_Policy::tables()[0];
            $where      = $wpdb->prepare( "o.status='paid' AND o.created_at >= FROM_UNIXTIME(%d) AND o.created_at < FROM_UNIXTIME(%d)", strtotime( $range['start_utc'] . ' UTC' ), strtotime( $range['end_exclusive_utc'] . ' UTC' ) );
            if ( 'own' === $args['scope'] ) {
                $where .= $wpdb->prepare( ' AND o.user_id=%d', get_current_user_id() );
            }
            $where .= " AND (o.legacy_id IS NULL OR o.legacy_id=0 OR NOT EXISTS (SELECT 1 FROM {$table} other_order WHERE other_order.legacy_id=o.legacy_id AND other_order.id<>o.id))";
            foreach ( Read_Support::query( "SELECT o.id,UNIX_TIMESTAMP(o.created_at) created_epoch FROM {$table} o WHERE {$where} ORDER BY o.created_at DESC,o.id ASC LIMIT 1001" ) as $row ) {
                $event            = self::event( 'order-paid', $row['id'], 'payment', gmdate( 'Y-m-d H:i:s', (int) $row['created_epoch'] ), false, $order_tool );
                $event['meaning'] = 'paid_order_created_not_settlement_event';
                $items[]          = $event;
            }
        }
        if ( in_array( 'review', $selected, true ) ) {
            $cursor_date = $range['end_exclusive_utc'];
            $cursor_id   = 0;
            $accepted    = 0;
            $scanned     = 0;
            do {
                $rows = Read_Support::query( $wpdb->prepare( "SELECT c.comment_ID,c.comment_post_ID,c.comment_date_gmt FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type=%s AND c.comment_type='review' AND c.comment_parent=0 AND c.comment_approved IN ('0','1') AND c.comment_date_gmt >= %s AND (c.comment_date_gmt < %s OR (c.comment_date_gmt=%s AND c.comment_ID>%d)) ORDER BY c.comment_date_gmt DESC,c.comment_ID ASC LIMIT 128", ATBDP_POST_TYPE, $range['start_utc'], $cursor_date, $cursor_date, $cursor_id ) );
                foreach ( $rows as $row ) {
                    $cursor_date = $row['comment_date_gmt'];
                    $cursor_id   = (int) $row['comment_ID'];
                    ++$scanned;
                    Validation::check( $scanned <= self::LISTING_BUDGET, __( 'Review source exceeds its permission budget.', 'directorist' ), 'unsupported_operation' );
                    try {
                        Access_Control::listing( (int) $row['comment_post_ID'] );
                        if ( current_user_can( 'edit_comment', $cursor_id ) && true === apply_filters( 'directorist_rest_check_permissions', true, 'read', (int) $row['comment_post_ID'], ATBDP_POST_TYPE ) ) {
                            $items[] = self::event( 'review', $cursor_id, 'review', $cursor_date );
                            ++$accepted;
                        }
                    } catch ( Failure $error ) {
                        continue;
                    }
                    if ( $accepted >= 1001 ) {
                        break;
                    }
                }
            } while ( count( $rows ) === 128 && $accepted < 1001 );
        }
        if ( in_array( 'user', $selected, true ) ) {
            foreach ( Read_Support::query( $wpdb->prepare( "SELECT ID,user_registered FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered < %s ORDER BY user_registered DESC,ID ASC LIMIT 1001", $range['start_utc'], $range['end_exclusive_utc'] ) ) as $row ) {
                $items[] = self::event( 'user', $row['ID'], 'user', $row['user_registered'] );
            }
        }
        self::trim( $upcoming );
        $upcoming_truncated            = count( $upcoming ) > 1000;
        $upcoming_page                 = Pagination::page( array_slice( $upcoming, 0, 1000 ), $args );
        $upcoming_page['window_total'] = $upcoming_page['total'];
        unset( $upcoming_page['total'] );
        $upcoming_page += [ 'truncated' => $upcoming_truncated, 'continuation_limit' => 1000 ];
        self::trim( $items );
        $truncated              = count( $items ) > 1000;
        $items                  = array_slice( $items, 0, 1000 );
        $result                 = Pagination::page( $items, $args );
        $result['window_total'] = $result['total'];
        unset( $result['total'] );
        return $result + [ 'upcoming' => $upcoming_page, 'history_start' => $range['start_utc'], 'truncated' => $truncated, 'continuation_limit' => 1000, 'as_of' => $as_of, 'ordering' => 'past_timestamp_desc; upcoming_timestamp_asc; ties_source_namespace_then_numeric_object_id', 'source_coverage' => 'native_current_records_not_a_permanent_audit_log' ];
    }
}
