<?php
namespace Directorist\Integrations\HelpGent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Throwable;

/**
 * Integration persistence layer.
 *
 * @package Directorist\Integrations\HelpGent
 */

defined( 'ABSPATH' ) || exit;

final class Database {
    public const SCHEMA_VERSION = '2';
    private const EFFECTIVE_SCORE = 'LEAST(COALESCE(c.manual_score, c.qualification_score), 100)';

    public static function maybe_upgrade(): void {
        if ( self::SCHEMA_VERSION !== get_option( 'directorist_helpgent_db_version' ) ) {
            self::activate();
        }
    }

    /**
     * Return the integration table name.
     */
    public static function table(): string {
        global $wpdb;

        return $wpdb->prefix . 'directorist_helpgent_conversations';
    }

    /**
     * Create or upgrade the relation table.
     */
    public static function activate(): void {
        global $wpdb;

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			response_id BIGINT UNSIGNED NOT NULL,
			form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			listing_id BIGINT UNSIGNED NOT NULL,
			owner_id BIGINT UNSIGNED NOT NULL,
			directory_type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(24) NOT NULL DEFAULT 'new',
			qualification VARCHAR(24) NOT NULL DEFAULT 'new',
			qualification_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			manual_score SMALLINT UNSIGNED DEFAULT NULL,
			summary TEXT NULL,
			contact_name VARCHAR(255) NULL,
			contact_email VARCHAR(255) NULL,
			contact_phone VARCHAR(100) NULL,
			is_locked TINYINT UNSIGNED NOT NULL DEFAULT 0,
			first_replied_at DATETIME NULL,
			converted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY response_id (response_id),
			KEY owner_id (owner_id),
			KEY listing_id (listing_id),
			KEY status (status),
			KEY directory_type_id (directory_type_id)
		) {$charset_collate};";

        dbDelta( $sql );
        if ( $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'manual_score'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
            update_option( 'directorist_helpgent_db_version', self::SCHEMA_VERSION );
        }
    }

    /**
     * Get a conversation relation by HelpGent response ID.
     */
    public static function get_by_response( int $response_id ): ?object {
        global $wpdb;

        $table = self::table();
        $directory = self::directory_expression();
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT c.*, {$directory} AS effective_directory_id FROM {$table} c WHERE c.response_id = %d LIMIT 1", $response_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        return $row ?: null;
    }

    private static function single_directory_id(): int {
        $directories = directorist_get_directories( [ 'fields' => 'ids', 'number' => 2 ] );

        return is_array( $directories ) && 1 === count( $directories ) ? (int) reset( $directories ) : 0;
    }

    private static function directory_expression(): string {
        $single_directory = self::single_directory_id();
        if ( $single_directory ) {
            return (string) $single_directory;
        }

        global $wpdb;
        $taxonomy = $wpdb->prepare( '%s', ATBDP_DIRECTORY_TYPE );

        // Use the listing's current type if the saved association is missing or deleted.
        return "COALESCE(
            (SELECT saved_type.term_id FROM {$wpdb->term_taxonomy} saved_type
                WHERE saved_type.term_id = c.directory_type_id AND saved_type.taxonomy = {$taxonomy}),
            (SELECT listing_type.term_id FROM {$wpdb->term_relationships} listing_terms
                INNER JOIN {$wpdb->term_taxonomy} listing_type ON listing_type.term_taxonomy_id = listing_terms.term_taxonomy_id
                WHERE listing_terms.object_id = c.listing_id AND listing_type.taxonomy = {$taxonomy}
                ORDER BY listing_type.term_id LIMIT 1),
            0
        )";
    }

    private static function inbox_scope( int $user_id, bool $is_admin ): array {
        global $wpdb;

        $table = self::table();
        $from = "FROM {$table} c
            INNER JOIN {$wpdb->prefix}helpgent_responses r ON r.id = c.response_id
            LEFT JOIN {$wpdb->posts} p ON p.ID = c.listing_id
            LEFT JOIN {$wpdb->usermeta} rc ON rc.user_id = {$user_id} AND rc.meta_key = CONCAT('dhgi_read_chat_', c.response_id)
            LEFT JOIN {$wpdb->usermeta} rm ON rm.user_id = {$user_id} AND rm.meta_key = CONCAT('dhgi_read_message_', c.response_id)
            LEFT JOIN {$wpdb->usermeta} rs ON rs.user_id = {$user_id} AND rs.meta_key = CONCAT('dhgi_read_submission_', c.response_id)";
        $owner = $is_admin ? '1 = 1' : $wpdb->prepare( 'p.post_author = %d', $user_id );
        $unread = "GREATEST(
            (SELECT COUNT(*) FROM {$wpdb->prefix}helpgent_chat_messages cm WHERE cm.response_id = c.response_id AND cm.created_by = r.created_by AND cm.status = 'publish' AND cm.id > COALESCE(rc.meta_value, 0)) +
            (SELECT COUNT(*) FROM {$wpdb->prefix}helpgent_messages mm WHERE mm.response_id = c.response_id AND mm.created_by = r.created_by AND mm.status = 'publish' AND mm.id > COALESCE(rm.meta_value, 0)),
            CASE WHEN r.is_completed = 1 AND COALESCE(rs.meta_value, 0) = 0 THEN 1 ELSE 0 END
        )";

        return [ $from, $owner, $unread ];
    }

    public static function list_for_user( int $user_id, bool $is_admin, array $filters, int $limit, int $offset ): array {
        global $wpdb;

        [ $from, $where, $unread ] = self::inbox_scope( $user_id, $is_admin );
        $directory = self::directory_expression();
        if ( 'unread' === $filters['view'] ) {
            $where .= " AND {$unread} > 0";
        }
        if ( 'all' !== $filters['status'] ) {
            $where .= $wpdb->prepare( ' AND c.status = %s', $filters['status'] );
        }
        if ( null !== $filters['directory_id'] ) {
            $where .= $wpdb->prepare( " AND {$directory} = %d", $filters['directory_id'] );
        }
        $score = self::EFFECTIVE_SCORE;
        $ranges = [];
        foreach ( $filters['score_ranges'] as $range ) {
            $ranges[] = $wpdb->prepare( "{$score} BETWEEN %d AND %d", $range['min'], $range['max'] );
        }
        if ( $ranges ) {
            $where .= ' AND ' . ( Enquiry_Settings::UNBANDED === $filters['score'] ? 'NOT ' : '' ) . '(' . implode( ' OR ', $ranges ) . ')';
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.*, {$directory} AS effective_directory_id, {$score} AS effective_score,
                    r.is_completed, r.updated_at AS response_updated_at,
                    f.title AS form_title, u.name AS visitor_name, u.email AS visitor_email,
                    p.post_title AS listing_title, {$unread} AS unread_count
                {$from}
                LEFT JOIN {$wpdb->prefix}helpgent_forms f ON f.id = r.form_id
                LEFT JOIN {$wpdb->prefix}helpgent_users u ON u.id = r.created_by
                WHERE {$where}
                ORDER BY COALESCE(r.updated_at, c.updated_at) DESC, c.response_id DESC
                LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
    }

    public static function inbox_groups( int $user_id, bool $is_admin ): array {
        global $wpdb;

        [ $from, $where, $unread ] = self::inbox_scope( $user_id, $is_admin );
        $directory = self::directory_expression();
        $score = self::EFFECTIVE_SCORE;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        return $wpdb->get_results(
            "SELECT {$directory} AS effective_directory_id, c.status, {$score} AS effective_score,
                ({$unread} > 0) AS is_unread, COUNT(*) AS total
            {$from} WHERE {$where}
            GROUP BY effective_directory_id, c.status, effective_score, is_unread"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
    }

    public static function unread_for_user( int $user_id, bool $is_admin ): int {
        global $wpdb;

        [ $from, $where, $unread ] = self::inbox_scope( $user_id, $is_admin );

        return (int) $wpdb->get_var( "SELECT COUNT(*) {$from} WHERE {$where} AND {$unread} > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
    }

    /**
     * Insert or refresh a relation.
     */
    public static function upsert( array $data ): void {
        global $wpdb;

        $existing = self::get_by_response( absint( $data['response_id'] ?? 0 ) );
        $now      = current_time( 'mysql', true );
        $data['updated_at'] = $now;

        if ( $existing ) {
            unset( $data['response_id'], $data['created_at'] );
            if ( $existing->directory_type_id ) {
                unset( $data['directory_type_id'] );
            }
            $wpdb->update( self::table(), $data, [ 'id' => absint( $existing->id ) ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return;
        }

        $data     = array_merge(
            [
                'form_id'            => 0,
                'directory_type_id'  => 0,
                'status'             => Enquiry_Settings::for_directory( absint( $data['directory_type_id'] ?? 0 ) ?: self::single_directory_id() )['default_status'],
                'qualification'      => 'new',
                'qualification_score' => 0,
                'summary'            => '',
                'contact_name'       => '',
                'contact_email'      => '',
                'contact_phone'      => '',
                'is_locked'          => 0,
                'updated_at'         => $now,
            ],
            $data
        );

        $data['created_at'] = $now;
        $wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    }
}
