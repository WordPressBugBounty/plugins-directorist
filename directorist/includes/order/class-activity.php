<?php
/** Record immutable event data at the repository boundary. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

class Activity {
    public static function append( $order_id, $type, ?array $data = null, array $extra = [] ) {
        $values = array_merge(
            [
                'order_id'      => (int) $order_id,
                'activity_type' => $type,
                'activity_by'   => Actor::resolve(),
                'data'          => $data,
                'created_at'    => gmdate( 'Y-m-d H:i:s' ),
            ], $extra
        );
        return ( new \Directorist\Repositories\OrderActivityRepository() )->append( $values );
    }

    public static function order_changed( $before, $after ): ?int {
        if ( $before->status === $after->status ) {
            return null;
        }
        return self::append( $after->id, 'order_status_updated', [ 'previous_status' => $before->status, 'new_status' => $after->status ] );
    }

    public static function payment_data( $payment ): array {
        return [
            'payment_id'     => (int) $payment->id,
            'method'         => $payment->method,
            'transaction_id' => $payment->transaction_id,
            'amount'         => (string) $payment->amount,
            'currency'       => $payment->currency,
        ];
    }

    public static function payment_changed( $before, $after ) {
        if ( $before && $before->status === $after->status ) {
            return;
        }
        self::append(
            $after->order_id, 'payment_status_updated', array_merge(
                self::payment_data( $after ),
                [ 'previous_status' => $before ? $before->status : null, 'new_status' => $after->status ]
            )
        );
    }

    public static function payment_deleted( $payment ) {
        self::append(
            $payment->order_id, 'payment_deleted', array_merge(
                self::payment_data( $payment ),
                [ 'previous_status' => $payment->status, 'new_status' => null ]
            )
        );
    }

    public static function refund_changed( $before, $after, $currency, $deleted = false ) {
        $row = $after ?: $before;
        if ( $before && $after && $before->status === $after->status && (string) $before->amount === (string) $after->amount && $before->reason === $after->reason ) {
            return;
        }
        $type       = $deleted ? 'refund_deleted' : ( $before ? 'refund_updated' : 'refund_added' );
        $transition = ! $before || ! $after || $before->status !== $after->status;
        $data       = [
            'refund_id'       => (int) $row->id,
            'amount'          => (string) $row->amount,
            'currency'        => $currency,
            'reason'          => $row->reason,
            'status'          => $row->status,
            'previous_status' => $transition && $before ? $before->status : null,
            'new_status'      => $transition && $after ? $after->status : null,
        ];
        if ( $before && $after ) {
            if ( (string) $before->amount !== (string) $after->amount ) {
                $data['previous_amount'] = (string) $before->amount;
            }
            if ( $before->reason !== $after->reason ) {
                $data['previous_reason'] = $before->reason;
            }
        }
        self::append( $row->order_id, $type, $data );
    }
}
