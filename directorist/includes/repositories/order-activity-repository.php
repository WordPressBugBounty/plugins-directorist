<?php
namespace Directorist\Repositories;

defined( 'ABSPATH' ) || exit;

class OrderActivityRepository {
    public function get( int $order_id, int $page = 1, int $per_page = 20 ): array {
        global $wpdb;
        $page     = max( 1, $page );
        $per_page = min( 100, max( 1, $per_page ) );
        $table    = $wpdb->prefix . 'directorist_order_activities';
        $items    = $this->check_read_result( $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, activity_type, activity_by, data, created_at FROM {$table} WHERE order_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $order_id, $per_page, ( $page - 1 ) * $per_page ) ) );
        foreach ( $items as $item ) {
            $item->data = null === $item->data ? null : json_decode( $item->data, true );
        }
        return [
            'items' => $items,
            'total' => (int) $this->check_read_result( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $order_id ) ) ),
            'page'  => $page,
        ];
    }

    private function check_read_result( $result ) {
        global $wpdb;
        if ( null === $result || false === $result || '' !== $wpdb->last_error ) {
            throw new \RuntimeException( __( 'Order activities could not be loaded.', 'directorist' ), 500 );
        }
        return $result;
    }

    public static function encode_data( ?array $data ): ?string {
        if ( null === $data ) {
            return null;
        }
        $json = wp_json_encode( $data );
        if ( false === $json ) {
            throw new \RuntimeException( __( 'The activity data could not be saved.', 'directorist' ), 500 );
        }
        return $json;
    }

    public function append( array $values ): int {
        global $wpdb;
        $values['data'] = self::encode_data( $values['data'] ?? null );
        \Directorist\Order\Mutation::check_result( $wpdb->insert( $wpdb->prefix . 'directorist_order_activities', $values ) );
        return (int) $wpdb->insert_id;
    }
}
