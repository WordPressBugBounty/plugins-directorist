<?php
/**
 * Listing Data for dashboard data.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Dashboard;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Listing Data. */
class Listing_Data {
    const LISTING_BUDGET = 20000;

    /**
     * Listings.
     *
     * @param array $args Validated tool input.
     * @param array $range Range.
     * @param bool $activity Activity.
     * @param bool $counts_only Only current listing counts and upcoming expiry totals.
     */
    public static function listings( array $args, array $range, bool $activity = false, bool $counts_only = false ): array {
        global $wpdb;
        Validation::check( Read_Callbacks::listing_access(), __( 'Listing access is required.', 'directorist' ), 'forbidden' );
        $where  = $wpdb->prepare( 'p.post_type = %s', ATBDP_POST_TYPE );
        $where .= " AND p.post_status IN ('publish','pending','private','expired','rejected','draft','trash')";
        if ( 'own' === $args['scope'] ) {
            $where .= $wpdb->prepare( ' AND p.post_author = %d', get_current_user_id() );
        }
        if ( isset( $args['directory_id'] ) ) {
            $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=p.ID AND t.taxonomy=%s AND t.term_id=%d)", ATBDP_DIRECTORY_TYPE, $args['directory_id'] );
        }
        $out               = [ 'counts' => array_fill_keys( [ 'publish', 'pending', 'private', 'expired', 'rejected', 'draft', 'trash' ], 0 ), 'views' => 0, 'views_complete' => true, 'expiring' => 0, 'daily' => [], 'distribution' => [], 'activity' => [], 'upcoming' => [] ];
        $cursor            = 0;
        $scanned           = 0;
        $listing_tool      = $activity ? Read_Support::tool_reference( 'directorist-get-listing' ) : null;
        $now               = current_datetime();
        $end               = $now->modify( '+7 days' );
        $published_counted = $counts_only && ! $activity && self::can_count_published( $args['scope'] );
        if ( $published_counted ) {
            $counts                   = Read_Support::query( "SELECT COUNT(*) AS total FROM {$wpdb->posts} p WHERE {$where} AND p.post_status = 'publish'" );
            $out['counts']['publish'] = (int) $counts[0]['total'];
            // Keep PHP's strict expiry parsing. Only fetch published rows that could expire in range.
            $where .= $wpdb->prepare(
                " AND (p.post_status <> 'publish' OR EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} e WHERE e.post_id = p.ID AND e.meta_key = '_expiry_date'
                    AND e.meta_value >= %s AND e.meta_value < %s
                ))", $now->format( 'Y-m-d' ), $end->modify( '+1 day' )->format( 'Y-m-d' )
            );
        }
        do {
            $rows     = Read_Support::query( "SELECT p.* FROM {$wpdb->posts} p WHERE {$where} AND p.ID > " . (int) $cursor . ' ORDER BY p.ID ASC LIMIT 128' );
            $scanned += $published_counted ? count( $rows ) - count( array_keys( array_column( $rows, 'post_status' ), 'publish', true ) ) : count( $rows );
            Validation::check( $scanned <= self::LISTING_BUDGET, __( 'This listing scope exceeds the native object-permission scan budget. Select a directory or use a narrower source.', 'directorist' ), 'unsupported_operation' );
            if ( ! $rows ) {
                break;
            }
            $ids       = array_map( 'intval', array_column( $rows, 'ID' ) );
            $keys      = [ '_expiry_date', '_never_expire', directorist_get_listing_views_count_meta_key() ];
            $meta_rows = Read_Support::query( $wpdb->prepare( "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND meta_key IN (%s,%s,%s) ORDER BY meta_id ASC', ...$keys ) );
            $meta      = [];
            foreach ( $meta_rows as $item ) {
                if ( ! isset( $meta[ $item['post_id'] ][ $item['meta_key'] ] ) ) {
                    $meta[ $item['post_id'] ][ $item['meta_key'] ] = $item['meta_value'];
                }
            }
            foreach ( $rows as $row ) {
                $id         = (int) $row['ID'];
                $cursor     = $id;
                $status     = $row['post_status'];
                $precounted = $published_counted && 'publish' === $status;
                $cached     = $precounted || wp_cache_get( $id, 'posts' );
                if ( ! $cached ) {
                    wp_cache_add( $id, new \WP_Post( (object) $row ), 'posts' );
                }
                try {
                    if ( ! $precounted ) {
                        Access_Control::listing( $id );
                        ++$out['counts'][ $status ];
                    }
                    if ( 'publish' === $status ) {
                        if ( ! $counts_only ) {
                            $views = $meta[ $id ][ $keys[2] ] ?? '0';
                            if ( ! ctype_digit( (string) $views ) || strlen( (string) $views ) >= 15 ) {
                                $out['views_complete'] = false;
                            }
                            $views                 = ctype_digit( (string) $views ) && strlen( (string) $views ) < 15 ? (int) $views : 0;
                            $out['views']         += $views;
                            $out['distribution'][] = $views;
                            $date                  = substr( $row['post_date'], 0, 10 );
                            if ( $date >= $range['start_date'] && $date <= $range['end_date'] ) {
                                $out['daily'][ $date ] = ( $out['daily'][ $date ] ?? 0 ) + 1;
                            }
                        }
                        $expiry  = $meta[ $id ]['_expiry_date'] ?? '';
                        $expires = self::expiry( $expiry );
                        if ( empty( $meta[ $id ]['_never_expire'] ) && $expires && $expires >= $now && $expires < $end ) {
                            ++$out['expiring'];
                            if ( $activity ) {
                                $out['upcoming'][] = Activity_Data::event( 'expiry', $id, 'listing', $expires->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), true, $listing_tool );
                            }
                        }
                    }
                    if ( $activity ) {
                        $timestamp = $row['post_modified_gmt'] !== '0000-00-00 00:00:00' ? $row['post_modified_gmt'] : $row['post_date_gmt'];
                        if ( $timestamp >= $range['start_utc'] && $timestamp < $range['end_exclusive_utc'] ) {
                            $out['activity'][] = Activity_Data::event( 'listing', $id, 'listing', $timestamp, false, $listing_tool );
                        }
                    }
                } catch ( Failure $error ) {
                    if ( ! in_array( $error->reason, [ 'not_found', 'forbidden' ], true ) ) {
                        throw $error;
                    }
                } finally {
                    if ( ! $cached ) {
                        wp_cache_delete( $id, 'posts' );
                    }
                }
            }
            Activity_Data::trim( $out['activity'] );
            Activity_Data::trim( $out['upcoming'] );
        } while ( count( $rows ) === 128 );
        // Equal-count buckets of listings ranked by lifetime views, never time buckets.
        $distribution = $out['distribution'];
        rsort( $distribution, SORT_NUMERIC );
        $out['distribution'] = [];
        if ( $distribution ) {
            foreach ( array_chunk( $distribution, (int) ceil( count( $distribution ) / 8 ) ) as $bucket ) {
                $out['distribution'][] = array_sum( $bucket );
            }
        }
        return $out;
    }

    /**
     * Published listings require only the native read and ownership capabilities
     * when no installed callback can change the permission for an individual post.
     *
     * @param string $scope Dashboard scope.
     */
    private static function can_count_published( string $scope ): bool {
        global $wp_filter;
        $type = get_post_type_object( ATBDP_POST_TYPE );
        if ( ! $type->map_meta_cap || empty( get_post_status_object( 'publish' )->public ) || has_filter( 'get_post_status' ) ) {
            return false;
        }
        $stock = [
            'directorist_rest_check_permissions' => [ 'directorist_allow_read_context_permission' ],
            'map_meta_cap'                       => [ \Directorist\Review\Bootstrap::class . '::map_meta_cap_for_review_author', \ATBDP_Roles::class . '::meta_caps' ],
            'user_has_cap'                       => [ 'wp_maybe_grant_install_languages_cap', 'wp_maybe_grant_resume_extensions_caps', 'wp_maybe_grant_site_health_caps' ],
        ];
        foreach ( $stock as $hook => $allowed ) {
            foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $callbacks ) {
                foreach ( $callbacks as $entry ) {
                    $callback = $entry['function'];
                    if ( is_array( $callback ) ) {
                        $owner    = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
                        $callback = $owner . '::' . $callback[1];
                    }
                    if ( ! in_array( $callback, $allowed, true ) ) {
                        return false;
                    }
                }
            }
        }
        return current_user_can( $type->cap->read ) && ( 'own' === $scope || current_user_can( $type->cap->edit_others_posts ) );
    }

    /**
     * Expiry.
     *
     * @param mixed $value Value.
     */
    public static function expiry( $value ): ?\DateTimeImmutable {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $value ) ) {
            return null;
        }
        $format = strlen( $value ) === 10 ? '!Y-m-d' : '!Y-m-d H:i:s';
        $date   = \DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );
        return $date && $date->format( substr( $format, 1 ) ) === $value ? $date : null;
    }
}
