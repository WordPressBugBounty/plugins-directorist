<?php
/** One-time conversion of the former activity message formats. Never used on reads. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

class LegacyActivityData {
    public static function convert( $row ): ?array {
        $message = (string) ( $row->message ?? '' );
        $data    = null;
        if ( 'order_status_updated' === $row->activity_type ) {
            if ( preg_match( '/^Order #\d+ status changed from ([a-z0-9_-]+) to ([a-z0-9_-]+)\.$/D', $message, $match ) ) {
                $data = [ 'previous_status' => $match[1], 'new_status' => $match[2] ];
            }
        } elseif ( in_array( $row->activity_type, [ 'payment_status_updated', 'payment_deleted' ], true ) ) {
            $imported = 0 === strpos( (string) ( $row->source_key ?? '' ), 'legacy_payment:' );
            $pattern  = '/^(?:Payment|Imported payment snapshot) #(\d+)(?: deleted)? \((.*), transaction (.*), (-?[\d.]+) ([A-Z]{3})\)(.*)$/sD';
            if ( preg_match( $pattern, $message, $match ) ) {
                $data = [
                    'payment_id'      => (int) $match[1],
                    'method'          => $match[2],
                    'transaction_id'  => in_array( $match[3], [ '—', '?' ], true ) ? '' : $match[3],
                    'amount'          => $match[4],
                    'currency'        => $match[5],
                    'previous_status' => null,
                    'new_status'      => null,
                ];
                if ( $imported && preg_match( '/^, recorded status: ([a-z0-9_-]+)\./', $match[6], $status ) ) {
                    $data['new_status'] = $status[1];
                } elseif ( preg_match( '/^; previous status: ([a-z0-9_-]+)\.$/D', $match[6], $status ) ) {
                    $data['previous_status'] = $status[1];
                } elseif ( preg_match( '/^: ([a-z0-9_-]+) (?:→|->|\?) ([a-z0-9_-]+)\.$/D', $match[6], $status ) ) {
                    $data['previous_status'] = 'created' === $status[1] ? null : $status[1];
                    $data['new_status']      = $status[2];
                }
                if ( false !== strpos( $message, 'The historical date was unavailable;' ) ) {
                    $data['date_unavailable'] = true;
                }
            }
            if ( $imported ) {
                $data             = $data ?? [ 'payment_id' => (int) substr( $row->source_key, strlen( 'legacy_payment:' ) ) ];
                $data['imported'] = true;
            }
        } elseif ( in_array( $row->activity_type, [ 'refund_added', 'refund_updated', 'refund_deleted' ], true ) ) {
            if ( preg_match( '/^Refund #(\d+): (-?[\d.]+) ([A-Z]{3}); status ([a-z0-9_-]+); reason: (.*)\.$/sD', $message, $match ) ) {
                $data = [
                    'refund_id'       => (int) $match[1],
                    'amount'          => $match[2],
                    'currency'        => $match[3],
                    'status'          => $match[4],
                    'reason'          => $match[5],
                    'previous_status' => 'refund_deleted' === $row->activity_type ? $match[4] : null,
                    'new_status'      => 'refund_added' === $row->activity_type ? $match[4] : null,
                ];
                if ( 'refund_updated' === $row->activity_type && preg_match( '/^(.*)\. Previous amount: (-?[\d.]+) [A-Z]{3}; previous status: ([a-z0-9_-]+); previous reason: (.*)$/sD', $match[5], $previous ) ) {
                    $data['reason'] = $previous[1];
                    if ( $previous[3] !== $match[4] ) {
                        $data['previous_status'] = $previous[3];
                        $data['new_status']      = $match[4];
                    }
                    if ( $previous[2] !== $match[2] ) {
                        $data['previous_amount'] = $previous[2];
                    }
                    if ( $previous[4] !== $data['reason'] ) {
                        $data['previous_reason'] = $previous[4];
                    }
                }
            }
        }
        // Older schemas stored status columns separately. Preserve those values
        // even if a translated legacy message cannot be parsed.
        foreach ( [ 'previous_status', 'new_status' ] as $key ) {
            if ( isset( $row->$key ) ) {
                $data         = $data ?? [];
                $data[ $key ] = $row->$key;
            }
        }
        return $data;
    }
}
