<?php
/** Transactional import checkpoints stored alongside the InnoDB ledger mutex. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

class MigrationState {
    private const COLUMNS = [
        ActivityMigration::STATE     => 'payment_import',
        ActivityDataMigration::STATE => 'activity_import',
    ];

    /** Schema changes run only under the installer's DDL lock, outside transactions. */
    public static function prepare() {
        global $wpdb;
        $table   = $wpdb->prefix . 'directorist_order_locks';
        $columns = Mutation::check_result( $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
        foreach ( self::COLUMNS as $column ) {
            if ( ! in_array( $column, $columns, true ) ) {
                Mutation::check_result( $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} longtext NULL" ) );
            }
        }
    }

    public static function get( string $name ): array {
        global $wpdb;
        $column = self::column( $name );
        // Current reads inside a caller-owned repeatable-read transaction must
        // observe the checkpoint committed by the preceding mutex owner.
        $lock = Mutation::in_transaction() ? ' FOR UPDATE' : '';
        $json = Mutation::check_result( $wpdb->get_var( "SELECT {$column} FROM {$wpdb->prefix}directorist_order_locks WHERE id = 1{$lock}" ) );
        $data = null === $json ? [] : json_decode( $json, true );
        return is_array( $data ) ? $data : [];
    }

    public static function save( string $name, array $state ) {
        $column = self::column( $name );
        $json   = wp_json_encode( $state );
        if ( false === $json ) {
            throw new \RuntimeException( __( 'The order activity migration checkpoint could not be saved.', 'directorist' ), 500 );
        }
        Mutation::run(
            static function () use ( $name, $state, $column, $json ) {
                global $wpdb;
                Mutation::check_result( $wpdb->update( $wpdb->prefix . 'directorist_order_locks', [ $column => $json ], [ 'id' => 1 ] ) );
                if ( self::get( $name ) !== $state ) {
                    throw new \RuntimeException( __( 'The order activity migration checkpoint could not be saved.', 'directorist' ), 500 );
                }
            }
        );
    }

    /** Diagnostics are best effort; never replace an import error or its progress. */
    public static function record_error( string $name, string $message ) {
        try {
            Mutation::run(
                static function () use ( $name, $message ) {
                    $state          = self::get( $name );
                    $state['error'] = $message;
                    self::save( $name, $state );
                }
            );
        } catch ( \Throwable $error ) {
            // The committed checkpoint remains authoritative if diagnostics fail.
        }
    }

    private static function column( string $name ): string {
        if ( ! isset( self::COLUMNS[ $name ] ) ) {
            throw new \InvalidArgumentException( 'Unknown order migration checkpoint.' );
        }
        return self::COLUMNS[ $name ];
    }
}
