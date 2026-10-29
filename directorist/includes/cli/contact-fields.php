<?php
/**
 * Explicit, reversible cleanup of literal contact-field whitespace.
 *
 * @package Directorist
 */

defined( 'ABSPATH' ) || exit;

class Directorist_Contact_Fields_Command {
    /**
     * Preview a batch of contact metadata; this never writes listing data.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : New JSON plan file, outside the public web root.
     * [--after-id=<id>]
     * : Resume after this listing ID. Default 0.
     * [--limit=<count>]
     * : Batch size, 1 to 500. Default 100.
     * [--directory=<id>]
     * : Only inspect this directory.
     */
    public function scan( $args, $assoc ) {
        global $wpdb;
        $file = $assoc['file'];
        $parent = realpath( dirname( $file ) );
        $public = realpath( ABSPATH );
        if ( ! $parent || $parent === $public || 0 === strpos( $parent . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR ) ) {
            WP_CLI::error( 'Store the plan outside the public web root.' );
        }
        if ( file_exists( $file ) ) {
            WP_CLI::error( 'Choose a new file; existing plans are never overwritten.' );
        }
        $after = isset( $assoc['after-id'] ) ? absint( $assoc['after-id'] ) : 0;
        $limit = isset( $assoc['limit'] ) ? max( 1, min( 500, absint( $assoc['limit'] ) ) ) : 100;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d", ATBDP_POST_TYPE, $after, $limit ) );
        $plan = [ 'version' => 1, 'site' => home_url(), 'after_id' => $after, 'last_id' => $after, 'changes' => [], 'review' => [] ];
        foreach ( $ids as $id ) {
            $plan['last_id'] = (int) $id;
            $directory = (int) directorist_get_listings_directory_type( $id );
            if ( isset( $assoc['directory'] ) && (int) $assoc['directory'] !== $directory ) {
                continue;
            }
            foreach ( directorist_get_contact_fields( $directory ) as $key => $field ) {
                if ( ! metadata_exists( 'post', $id, $key ) ) {
                    continue;
                }
                $old = get_post_meta( $id, $key, true );
                $new = directorist_normalize_contact_field( $old, $field->type );
                $entry = [ 'id' => (int) $id, 'directory' => $directory, 'key' => $key, 'old' => $old, 'new' => $new ];
                $valid = directorist_prepare_contact_field( $field, $new );
                if ( is_wp_error( $valid ) || $this->has_encoded_boundary( $new ) ) {
                    $entry['reason'] = is_wp_error( $valid ) ? $valid->get_error_message() : 'Encoded boundary is ambiguous; review the intended destination.';
                    $plan['review'][] = $entry;
                } elseif ( $old !== $new ) {
                    // Only literal normalization is proposed; no URL reserialization.
                    $plan['changes'][] = $entry;
                }
            }
        }
        $encoded = wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === $encoded || false === file_put_contents( $file, $encoded, LOCK_EX ) ) {
            WP_CLI::error( 'Could not write the plan.' );
        }
        chmod( $file, 0600 );
        WP_CLI::success( sprintf( '%d safe changes; %d need review. Resume with --after-id=%d.', count( $plan['changes'] ), count( $plan['review'] ), $plan['last_id'] ) );
    }

    /**
     * Apply a reviewed plan, skipping records edited since the scan.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : JSON plan from scan. Retain this file for rollback.
     */
    public function apply( $args, $assoc ) {
        $this->change( $assoc['file'], false );
    }

    /**
     * Restore old values only where the applied value is still unchanged.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : The original JSON plan.
     */
    public function rollback( $args, $assoc ) {
        $this->change( $assoc['file'], true );
    }

    private function change( $file, $rollback ) {
        $plan = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
        if ( ! is_array( $plan ) || 1 !== ( $plan['version'] ?? null ) || home_url() !== ( $plan['site'] ?? '' ) || ! isset( $plan['changes'] ) || ! is_array( $plan['changes'] ) ) {
            WP_CLI::error( 'Invalid plan or site mismatch.' );
        }
        $changed = 0;
        $skipped = 0;
        foreach ( $plan['changes'] as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['key'], $entry['directory'] ) || ! array_key_exists( 'old', $entry ) || ! array_key_exists( 'new', $entry ) ) {
                WP_CLI::error( 'Malformed plan entry.' );
            }
            $id = absint( $entry['id'] );
            $directory = (int) directorist_get_listings_directory_type( $id );
            $fields = directorist_get_contact_fields( $directory );
            $field = $fields[ $entry['key'] ] ?? null;
            if ( ATBDP_POST_TYPE !== get_post_type( $id ) || $directory !== $entry['directory'] || ! $field || directorist_normalize_contact_field( $entry['old'], $field->type ) !== $entry['new'] || $this->has_encoded_boundary( $entry['new'] ) || is_wp_error( directorist_prepare_contact_field( $field, $entry['new'] ) ) ) {
                ++$skipped;
                WP_CLI::warning( "Skipped invalid or changed field definition for listing {$id}." );
                continue;
            }
            $expected = $rollback ? $entry['new'] : $entry['old'];
            $replacement = $rollback ? $entry['old'] : $entry['new'];
            if ( get_post_meta( $id, $entry['key'], true ) !== $expected ) {
                ++$skipped;
                continue;
            }
            if ( $this->replace_value( $id, $entry['key'], $expected, $replacement ) ) {
                ++$changed;
            } else {
                ++$skipped;
            }
        }
        WP_CLI::success( sprintf( '%d changed; %d skipped (conflict, already processed, or invalid).', $changed, $skipped ) );
    }

    /** Compare exact stored bytes, including empty values, before changing one row. */
    private function replace_value( $id, $key, $expected, $replacement ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, $key ) );
        if ( 1 !== count( $rows ) || $rows[0]->meta_value !== maybe_serialize( $expected ) ) {
            return false;
        }
        $meta_id = (int) $rows[0]->meta_id;
        do_action( 'update_post_meta', $meta_id, $id, $key, $replacement );
        do_action( 'update_postmeta', $meta_id, $id, $key, maybe_serialize( $replacement ) );
        $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND BINARY meta_value = BINARY %s", maybe_serialize( $replacement ), $meta_id, maybe_serialize( $expected ) ) );
        if ( 1 !== $updated ) {
            return false;
        }
        wp_cache_delete( $id, 'post_meta' );
        do_action( 'updated_post_meta', $meta_id, $id, $key, $replacement );
        do_action( 'updated_postmeta', $meta_id, $id, $key, maybe_serialize( $replacement ) );
        return true;
    }

    private function has_encoded_boundary( $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( $this->has_encoded_boundary( $item ) ) {
                    return true;
                }
            }
            return false;
        }
        return is_string( $value ) && (bool) preg_match( '/^(?:%20|%09|%0a|%0d|%c2%a0)|(?:%20|%09|%0a|%0d|%c2%a0)$/i', $value );
    }
}

WP_CLI::add_command( 'directorist contact-fields', 'Directorist_Contact_Fields_Command' );
