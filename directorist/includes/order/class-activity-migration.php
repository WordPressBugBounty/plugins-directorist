<?php
/** Resumable import of historical payment snapshots. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

class ActivityMigration {
    const STATE          = 'directorist_order_activity_import';
    const VERSION        = 'directorist_order_activity_db_version';
    const SCHEMA_VERSION = '7';
    const ERROR          = 'directorist_order_activity_upgrade_error';

    public static function register_hooks() {
        add_action( 'init', [ __CLASS__, 'install' ], 20 );
        add_action( 'init', [ __CLASS__, 'schedule' ], 25 );
        add_action( 'directorist_installed', [ __CLASS__, 'install' ], 20 );
        add_action( 'directorist_updated', [ __CLASS__, 'install' ], 20 );
        add_action( 'admin_notices', [ __CLASS__, 'upgrade_notice' ] );
    }

    public static function install() {
        global $wpdb;
        if ( self::ready() || Mutation::in_transaction() ) {
            return;
        }
        // DDL implicitly commits in MySQL. Run before ledger transactions and
        // serialize schema installers. InnoDB metadata locks protect active ledger
        // transactions while native writes use their transaction-scoped mutex.
        $locked = false;
        $lock   = 'directorist_ledger_' . md5( DB_NAME . ':' . $wpdb->prefix );
        try {
            do_action( 'directorist_mcp_before_native_order_write' );
            $locked = '1' === (string) Mutation::check_result( $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) );
            if ( ! $locked ) {
                throw new \RuntimeException( 'Order database upgrade is busy.' );
            }
            wp_cache_delete( self::VERSION, 'options' );
            if ( self::ready() ) {
                return;
            }
            $table = $wpdb->prefix . 'directorist_order_activities';
            $sql   = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint unsigned NOT NULL,
            activity_type varchar(32) NOT NULL,
            activity_by varchar(20) NOT NULL,
            data longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY order_timeline (order_id, created_at, id)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate();
            Mutation::check_result( $wpdb->query( $sql ) );
            $mutex = $wpdb->prefix . 'directorist_order_locks';
            Mutation::check_result( $wpdb->query( "CREATE TABLE IF NOT EXISTS {$mutex} (id tinyint unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" ) );
            Mutation::check_result( $wpdb->query( "INSERT IGNORE INTO {$mutex} (id) VALUES (1)" ) );
            // Older native tables inherited the server's default engine. Convert
            // only the ledger tables, before any transactional activity writes.
            foreach ( [ 'directorist_orders', 'directorist_payments', 'directorist_refunds', 'directorist_order_activities', 'directorist_order_locks' ] as $name ) {
                $table  = $wpdb->prefix . $name;
                $engine = Mutation::check_result( $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ) );
                if ( ! $engine ) {
                    throw new \RuntimeException( 'Native order tables are not installed yet.' );
                }
                if ( 'INNODB' !== strtoupper( $engine ) ) {
                    Mutation::check_result( $wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" ) );
                }
            }
            MigrationState::prepare();
            ActivityDataMigration::prepare();
            if ( ! self::valid_state( MigrationState::get( self::STATE ) ) ) {
                // Import legacy options once, rewinding the cursor so a formerly
                // nontransactional checkpoint cannot hide rolled-back snapshots.
                $legacy     = get_option( self::STATE, [] );
                $high_water = self::valid_state( $legacy ) ? $legacy['high_water']
                    : Mutation::check_result( $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}directorist_payments" ) );
                MigrationState::save(
                    self::STATE, [
                        'high_water' => (int) $high_water,
                        'cursor'     => 0,
                        'orphans'    => 0,
                        'error'      => '',
                    ]
                );
            }
            // Mark ready only after every prerequisite checkpoint is persisted.
            Mutation::save_option( self::VERSION, self::SCHEMA_VERSION );
            delete_option( self::ERROR );
        } catch ( \Throwable $error ) {
            update_option( self::ERROR, __( 'The order activity database upgrade could not finish. It will retry on the next request.', 'directorist' ), false );
            return;
        } finally {
            if ( $locked ) {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            }
        }
        // Complete small upgrades immediately; larger installations use the updater.
        self::batch();
    }

    private static function valid_state( $state ): bool {
        return is_array( $state ) && isset( $state['cursor'], $state['high_water'], $state['orphans'] )
            && is_int( $state['cursor'] ) && is_int( $state['high_water'] ) && is_int( $state['orphans'] )
            && $state['cursor'] >= 0 && $state['cursor'] <= $state['high_water'] && $state['orphans'] >= 0;
    }

    private static function ready(): bool {
        if ( get_option( self::ERROR, '' ) || self::SCHEMA_VERSION !== get_option( self::VERSION ) ) {
            return false;
        }
        try {
            $data = MigrationState::get( ActivityDataMigration::STATE );
            return self::valid_state( MigrationState::get( self::STATE ) )
                && ( $data['version'] ?? '' ) === self::SCHEMA_VERSION;
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    public static function upgrade_notice() {
        $error = get_option( self::ERROR, '' );
        if ( $error && current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
    }

    public static function schedule() {
        if ( ! self::ready() ) {
            return;
        }
        $state = MigrationState::get( self::STATE );
        if ( ! ActivityDataMigration::pending() && ( $state['cursor'] ?? 0 ) >= ( $state['high_water'] ?? 0 ) ) {
            return;
        }
        $queued = (int) get_option( 'directorist_order_activity_import_queued', 0 );
        if ( $queued && time() - $queued < HOUR_IN_SECONDS ) {
            return;
        }
        if ( $queued ) {
            delete_option( 'directorist_order_activity_import_queued' );
        }
        if ( add_option( 'directorist_order_activity_import_queued', time(), '', false ) ) {
            \ATBDP_Installation::queue_order_activity_import();
        }
    }

    public static function baseline( $payment ) {
        // The check and insert share the same lock even when called directly.
        return Mutation::run(
            static function () use ( $payment ) {
                global $wpdb;
                $state = MigrationState::get( self::STATE );
                if ( ! self::valid_state( $state ) ) {
                    throw new \RuntimeException( 'Payment activity import checkpoint is unavailable.' );
                }
                if ( (int) $payment->id > (int) ( $state['high_water'] ?? 0 ) ) {
                    return;
                }
                // Retain serialization until an enclosing transaction commits, too.
                $payment = Mutation::locked_row( directorist_payment_repository()->get_query_builder()->where( 'id', (int) $payment->id ) );
                if ( ! $payment ) {
                    return;
                }
                ActivityDataMigration::payment_snapshot( (int) $payment->id );
                if ( self::imported( $payment ) || ! Mutation::locked_row( directorist_order_repository()->get_query_builder()->where( 'id', (int) $payment->order_id ) ) ) {
                    return;
                }
                $dates = Mutation::check_result( $wpdb->get_row( $wpdb->prepare( "SELECT UNIX_TIMESTAMP(updated_at) AS updated, UNIX_TIMESTAMP(created_at) AS created FROM {$wpdb->prefix}directorist_payments WHERE id = %d FOR UPDATE", $payment->id ) ) );
                $date  = (int) ( $dates->updated ?? 0 ) ?: (int) ( $dates->created ?? 0 );
                $data  = array_merge( Activity::payment_data( $payment ), [ 'previous_status' => null, 'new_status' => $payment->status, 'imported' => true ] );
                if ( ! $date ) {
                    $data['date_unavailable'] = true;
                }
                Activity::append(
                    $payment->order_id, 'payment_status_updated', $data, [
                        'activity_by' => 'system',
                        'created_at'  => gmdate( 'Y-m-d H:i:s', $date ?: time() ),
                    ]
                );
            }
        );
    }

    /** Portable JSON identity lookup, bounded and current even in an outer RR transaction. */
    private static function imported( $payment ): bool {
        global $wpdb;
        $table  = $wpdb->prefix . 'directorist_order_activities';
        $cursor = 0;
        do {
            $rows = Mutation::check_result( $wpdb->get_results( $wpdb->prepare( "SELECT id, data FROM {$table} WHERE order_id = %d AND activity_type = 'payment_status_updated' AND id > %d ORDER BY id ASC LIMIT 100 FOR UPDATE", $payment->order_id, $cursor ) ) );
            foreach ( $rows as $row ) {
                $data = json_decode( (string) $row->data, true );
                if ( ( $data['payment_id'] ?? null ) === (int) $payment->id && true === ( $data['imported'] ?? false ) ) {
                    return true;
                }
                $cursor = (int) $row->id;
            }
        } while ( count( $rows ) === 100 );
        return false;
    }

    public static function batch() {
        global $wpdb;
        try {
            $data_pending = ActivityDataMigration::batch();
            $more         = Mutation::run(
                static function () use ( $wpdb ) {
                    $state = MigrationState::get( self::STATE );
                    if ( ! self::valid_state( $state ) ) {
                        throw new \RuntimeException( 'Payment activity import checkpoint is unavailable.' );
                    }
                    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}directorist_payments WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 100 FOR UPDATE", $state['cursor'] ?? 0, $state['high_water'] ?? 0 ) );
                    Mutation::check_result( $rows );
                    foreach ( $rows as $payment ) {
                        if ( Mutation::locked_row( directorist_order_repository()->get_query_builder()->where( 'id', (int) $payment->order_id ) ) ) {
                            self::baseline( $payment );
                        } else {
                            ++$state['orphans'];
                        }
                        $state['cursor'] = (int) $payment->id;
                    }
                    if ( count( $rows ) < 100 ) {
                        $state['cursor'] = $state['high_water'] ?? 0;
                    }
                    $state['error'] = '';
                    MigrationState::save( self::STATE, $state );
                    return $state['cursor'] < $state['high_water'];
                }
            );
            $more         = $more || $data_pending;
            if ( ! $more ) {
                delete_option( 'directorist_order_activity_import_queued' );
            }
            return $more;
        } catch ( \Throwable $error ) {
            MigrationState::record_error( self::STATE, __( 'Payment activity import could not finish. It will retry on the next request.', 'directorist' ) );
            delete_option( 'directorist_order_activity_import_queued' );
            return false;
        }
    }
}
