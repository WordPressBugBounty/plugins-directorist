<?php
/** Bounded, retryable upgrade from activity prose to structured JSON. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

class ActivityDataMigration {
    const STATE = 'directorist_order_activity_data_import';

    /** Called by the installer while holding the shared ledger lock, outside a transaction. */
    public static function prepare() {
        global $wpdb;
        $table   = $wpdb->prefix . 'directorist_order_activities';
        $columns = Mutation::check_result( $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
        if ( ! in_array( 'data', $columns, true ) ) {
            Mutation::check_result( $wpdb->query( "ALTER TABLE {$table} ADD COLUMN data longtext NULL" ) );
        }
        $types = Mutation::check_result( $wpdb->get_results( "SHOW COLUMNS FROM {$table}", OBJECT_K ) );
        if ( 'json' === strtolower( $types['data']->Type ) ) {
            Mutation::check_result( $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN data longtext NULL" ) );
        }
        $legacy = self::legacy_columns( $columns );
        if ( in_array( 'message', $legacy, true ) ) {
            // New structured writers must work while old rows are being converted.
            Mutation::check_result( $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN message text NULL" ) );
        }
        $state = MigrationState::get( self::STATE );
        if ( ( $state['version'] ?? '' ) !== ActivityMigration::SCHEMA_VERSION ) {
            MigrationState::save(
                self::STATE, [
                    'version'    => ActivityMigration::SCHEMA_VERSION,
                    'cursor'     => 0,
                    'high_water' => (int) Mutation::check_result( $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$table}" ) ),
                    'complete'   => ! $legacy,
                    'unparsed'   => 0,
                    'error'      => '',
                ]
            );
        }
    }

    public static function pending(): bool {
        $state = MigrationState::get( self::STATE );
        return ! empty( $state ) && empty( $state['complete'] );
    }

    public static function batch(): bool {
        global $wpdb;
        if ( ! self::pending() ) {
            return false;
        }
        $table = $wpdb->prefix . 'directorist_order_activities';
        try {
            Mutation::run(
                static function () use ( $wpdb, $table ) {
                    $state = MigrationState::get( self::STATE );
                    // A crash after DROP but before the completion checkpoint is safe.
                    $columns = Mutation::check_result( $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
                    if ( ! self::legacy_columns( $columns ) ) {
                        $state['complete'] = true;
                    } else {
                        $rows = Mutation::check_result( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 100 FOR UPDATE", $state['cursor'], $state['high_water'] ) ) );
                        foreach ( $rows as $row ) {
                            if ( self::convert_row( $row ) ) {
                                ++$state['unparsed'];
                            }
                            $state['cursor'] = (int) $row->id;
                        }
                        if ( count( $rows ) < 100 ) {
                            $state['cursor'] = $state['high_water'];
                        }
                    }
                    $state['error'] = '';
                    MigrationState::save( self::STATE, $state );
                }
            );
            self::finish();
        } catch ( \Throwable $error ) {
            MigrationState::record_error( self::STATE, __( 'Activity data conversion could not finish. It will retry on the next request.', 'directorist' ) );
            throw $error;
        }
        return self::pending();
    }

    /** Convert a legacy snapshot on demand before a payment is changed/imported.
     * The caller holds the ledger lock and the payment row lock.
     */
    public static function payment_snapshot( int $payment_id ) {
        global $wpdb;
        if ( ! self::pending() ) {
            return;
        }
        $table   = $wpdb->prefix . 'directorist_order_activities';
        $columns = Mutation::check_result( $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
        if ( ! in_array( 'source_key', $columns, true ) ) {
            return;
        }
        $row = Mutation::check_result( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_key = %s FOR UPDATE", 'legacy_payment:' . $payment_id ) ) );
        if ( $row ) {
            self::convert_row( $row );
        }
    }

    private static function legacy_columns( array $columns ): array {
        return array_values( array_intersect( [ 'message', 'previous_status', 'new_status', 'source_key' ], $columns ) );
    }

    /** Return whether an old message had no recoverable variable data. */
    private static function convert_row( $row ): bool {
        global $wpdb;
        $original = null === $row->data ? null : json_decode( $row->data, true );
        $data     = $original;
        if ( null === $data ) {
            $data = LegacyActivityData::convert( $row );
        }
        if ( preg_match( '/^legacy_payment:(\d+)/', (string) ( $row->source_key ?? '' ), $match ) ) {
            $data               = $data ?? [];
            $data['payment_id'] = (int) $match[1];
            $data['imported']   = true;
        }
        if ( $data !== $original ) {
            Mutation::check_result( $wpdb->update( $wpdb->prefix . 'directorist_order_activities', [ 'data' => \Directorist\Repositories\OrderActivityRepository::encode_data( $data ) ], [ 'id' => $row->id ] ) );
        }
        return null === $data && isset( $row->message );
    }

    private static function finish() {
        global $wpdb;
        $state = MigrationState::get( self::STATE );
        if ( ! self::pending() || $state['cursor'] < $state['high_water'] || Mutation::in_transaction() ) {
            return;
        }
        // ALTER TABLE implicitly commits. Never execute it inside a caller's transaction.
        do_action( 'directorist_mcp_before_native_order_write' );
        $lock = 'directorist_ledger_' . md5( DB_NAME . ':' . $wpdb->prefix );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
            return;
        }
        try {
            $table   = $wpdb->prefix . 'directorist_order_activities';
            $state   = MigrationState::get( self::STATE );
            $columns = Mutation::check_result( $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
            if ( self::legacy_columns( $columns ) ) {
                $conditions = array_map( static function ( $column ) { return "`{$column}` IS NOT NULL"; }, self::legacy_columns( $columns ) );
                $new_rows   = (int) Mutation::check_result( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id),0) FROM {$table} WHERE id > %d AND (" . implode( ' OR ', $conditions ) . ')', $state['cursor'] ) ) );
                if ( $new_rows ) {
                    $state['high_water'] = $new_rows;
                    MigrationState::save( self::STATE, $state );
                    return;
                }
                $drop = [];
                foreach ( self::legacy_columns( $columns ) as $column ) {
                    if ( in_array( $column, $columns, true ) ) {
                        $drop[] = "DROP COLUMN `{$column}`";
                    }
                }
                Mutation::check_result( $wpdb->query( "ALTER TABLE {$table} " . implode( ', ', $drop ) ) );
            }
            $state['complete'] = true;
            MigrationState::save( self::STATE, $state );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }
}
