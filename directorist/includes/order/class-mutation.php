<?php
/** Atomic native ledger writes, including writes inside an existing transaction. */
namespace Directorist\Order;

defined( 'ABSPATH' ) || exit;

class Mutation {
    private static $active = false;

    private static $depth = 0;

    private static $sequence = 0;

    private static $checked = false;

    private static $boundary;

    private static $pending = [];

    private static $committed = [];

    public static function register_hooks() {
        // wpdb exposes a filter before execution, so check its result at the next
        // query or at shutdown for MCP and gateways that own their transactions.
        add_filter( 'query', [ __CLASS__, 'observe_transaction' ], PHP_INT_MAX );
        add_action( 'shutdown', [ __CLASS__, 'dispatch_committed' ] );
    }

    public static function observe_transaction( $sql ) {
        self::confirm_boundary();
        if ( preg_match( '/^\s*(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/i', $sql, $match )
            && ! preg_match( '/^\s*ROLLBACK\s+(?:WORK\s+)?TO\b/i', $sql ) ) {
            self::$boundary = [ $sql, strtoupper( $match[1] ) ];
        }

        return $sql;
    }

    private static function confirm_boundary() {
        global $wpdb;
        if ( ! self::$boundary ) {
            return;
        }

        [ $sql, $command ] = self::$boundary;
        self::$boundary = null;
        if ( $sql !== $wpdb->last_query || '' !== $wpdb->last_error || true !== $wpdb->result ) {
            return;
        }

        self::$active = 'COMMIT' !== $command && 'ROLLBACK' !== $command;
        if ( self::$active ) {
            return;
        }

        if ( 'COMMIT' === $command ) {
            self::$committed += self::$pending;
        }
        self::$pending = [];
    }

    public static function in_transaction(): bool {
        self::confirm_boundary();
        return self::$active || self::$depth > 0;
    }

    public static function after_commit( int $activity_id, callable $callback ) {
        self::$pending[ $activity_id ] = $callback;
    }

    public static function dispatch_committed() {
        global $wpdb;
        if ( self::in_transaction() || ! self::$committed ) {
            return;
        }

        $callbacks = self::$committed;
        self::$committed = [];
        foreach ( $callbacks as $activity_id => $callback ) {
            try {
                // A savepoint rollback or lost connection can remove the activity
                // even if the final COMMIT itself succeeded.
                $exists = self::check_result( $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}directorist_order_activities WHERE id = %d", $activity_id
                ) ) );
                if ( (int) $exists === $activity_id ) {
                    $callback();
                }
            } catch ( \Throwable $error ) {
                // A notification failure must not make a committed write look like it rolled back.
                error_log( 'Directorist could not dispatch a committed order notification.' );
            }
        }
    }

    public static function run( callable $callback ) {
        global $wpdb;
        if ( 0 === self::$depth ) {
            // Keep the existing MCP coordinator first in the lock order.
            do_action( 'directorist_mcp_before_native_order_write' );
        }
        $savepoint = 'directorist_ledger_' . ++self::$sequence;
        $owns      = ! self::in_transaction();
        $saved     = false;
        ++self::$depth;
        try {
            self::check_storage();
            if ( $owns ) {
                self::query( 'START TRANSACTION' );
            }
            // InnoDB retains this mutex until the actual transaction ends, including
            // transactions owned by gateways. No named gateway lock is replaced on
            // MySQL versions that support only one advisory lock per connection.
            $mutex = self::check_result( $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}directorist_order_locks WHERE id = 1 FOR UPDATE" ) );
            if ( '1' !== (string) $mutex ) {
                throw new \RuntimeException( __( 'The order database upgrade is required.', 'directorist' ), 503 );
            }
            // Acquire before the savepoint so a nested rollback keeps the mutex.
            if ( ! $owns ) {
                self::query( "SAVEPOINT {$savepoint}" );
                $saved = true;
            }
            $result = $callback();
            self::query( $owns ? 'COMMIT' : "RELEASE SAVEPOINT {$savepoint}" );
        } catch ( \Throwable $error ) {
            if ( $owns ) {
                $wpdb->query( 'ROLLBACK' );
            } elseif ( $saved ) {
                $wpdb->query( "ROLLBACK TO SAVEPOINT {$savepoint}" );
                $wpdb->query( "RELEASE SAVEPOINT {$savepoint}" );
            }
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            throw $error;
        } finally {
            --self::$depth;
        }

        self::dispatch_committed();
        return $result;
    }

    /** Persist schema version options, accepting only verified no-op saves. */
    public static function save_option( string $name, $value ) {
        global $wpdb;
        update_option( $name, $value, false );
        // update_option() also returns false for unchanged values. Inspect the SQL
        // error immediately, then verify the persisted value with a fresh read.
        self::check_result( null );
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        $stored = self::check_result( get_option( $name, null ) );
        if ( $stored !== $value ) {
            throw new \RuntimeException( __( 'The order activity migration checkpoint could not be saved.', 'directorist' ), 500 );
        }
    }

    public static function check_result( $result ) {
        global $wpdb;
        if ( false === $result || '' !== $wpdb->last_error ) {
            throw new \RuntimeException( __( 'The order update could not be saved.', 'directorist' ), 500 );
        }
        return $result;
    }

    /** Current persisted rows, independent of a caller's repeatable-read snapshot. */
    public static function locked_row( \Directorist\Utils\Database\Query\Builder $query ): ?\stdClass {
        global $wpdb;
        return self::check_result( $wpdb->get_row( $query->limit( 1 )->to_sql() . ' FOR UPDATE' ) );
    }

    public static function locked_rows( \Directorist\Utils\Database\Query\Builder $query ): array {
        global $wpdb;
        return self::check_result( $wpdb->get_results( $query->to_sql() . ' FOR UPDATE' ) );
    }

    private static function query( $sql ) {
        global $wpdb;
        self::check_result( $wpdb->query( $sql ) );
    }

    private static function check_storage() {
        global $wpdb;
        if ( self::$checked ) {
            return;
        }
        foreach ( [ 'directorist_orders', 'directorist_payments', 'directorist_refunds', 'directorist_order_activities', 'directorist_order_locks' ] as $name ) {
            $engine = self::check_result( $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix . $name ) ) );
            if ( 'INNODB' !== strtoupper( (string) $engine ) ) {
                throw new \RuntimeException( __( 'Order activities require the order database upgrade and InnoDB storage.', 'directorist' ), 503 );
            }
        }
        self::$checked = true;
    }
}
