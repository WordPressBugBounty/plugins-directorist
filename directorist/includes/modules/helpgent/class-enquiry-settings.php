<?php

namespace Directorist\Integrations\HelpGent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Enquiry_Settings {
    public const KEY = 'helpgent_enquiry_settings';
    public const UNBANDED = 'unbanded';

    public static function defaults(): array {
        return [
            'score_enabled'  => true,
            'score_label'    => __( 'Lead score', 'directorist' ),
            'bands'          => [
                [ 'id' => 'hot', 'value' => [ 'name' => __( 'Hot', 'directorist' ), 'min' => 70, 'max' => 100, 'color' => '#ba3327' ] ],
                [ 'id' => 'warm', 'value' => [ 'name' => __( 'Warm', 'directorist' ), 'min' => 40, 'max' => 69, 'color' => '#b45309' ] ],
                [ 'id' => 'low', 'value' => [ 'name' => __( 'Low', 'directorist' ), 'min' => 0, 'max' => 39, 'color' => '#545865' ] ],
            ],
            'status_enabled' => true,
            'status_label'   => __( 'Status', 'directorist' ),
            'default_status' => 'new',
            'statuses'       => [
                [ 'id' => 'new', 'value' => [ 'name' => __( 'New', 'directorist' ), 'color' => '#545865' ] ],
                [ 'id' => 'contacted', 'value' => [ 'name' => __( 'Contacted', 'directorist' ), 'color' => '#2c99ff' ] ],
                [ 'id' => 'qualified', 'value' => [ 'name' => __( 'Qualified', 'directorist' ), 'color' => '#5257a5' ] ],
                [ 'id' => 'booked', 'value' => [ 'name' => __( 'Booked', 'directorist' ), 'color' => '#6d46a8' ] ],
                [ 'id' => 'won', 'value' => [ 'name' => __( 'Won', 'directorist' ), 'color' => '#1a7431' ] ],
                [ 'id' => 'lost', 'value' => [ 'name' => __( 'Lost', 'directorist' ), 'color' => '#ba3327' ] ],
            ],
        ];
    }

    public static function for_directory( int $directory_id ): array {
        $settings = $directory_id ? get_term_meta( $directory_id, self::KEY, true ) : [];
        $settings = is_array( $settings ) && $settings ? self::validate( $settings ) : self::defaults();

        return is_wp_error( $settings ) ? self::defaults() : $settings;
    }

    public static function score_value( $value ): ?int {
        if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^\d{1,3}$/D', $value ) ) ) {
            return null;
        }
        $value = (int) $value;

        return $value >= 0 && $value <= 100 ? $value : null;
    }

    public static function filter_id( $value ): bool {
        return is_string( $value ) && (bool) preg_match( '/^[a-z][a-z0-9_-]{0,23}$/D', $value );
    }

    public static function validate( $settings ) {
        if ( ! is_array( $settings ) || ! is_array( $settings['bands'] ?? null ) || ! is_array( $settings['statuses'] ?? null ) ) {
            return new WP_Error( 'dhgi_settings_invalid', __( 'Invalid HelpGent enquiry settings.', 'directorist' ) );
        }

        $defaults = self::defaults();
        $clean = [];
        foreach ( [ 'score', 'status' ] as $feature ) {
            $clean[ $feature . '_enabled' ] = in_array( $settings[ $feature . '_enabled' ] ?? true, [ true, 1, '1', 'true' ], true );
            $clean[ $feature . '_label' ] = self::label( $settings[ $feature . '_label' ] ?? '' ) ?: $defaults[ $feature . '_label' ];
        }

        $bands = [];
        $covered_scores = [];
        foreach ( $settings['bands'] as $row ) {
            $id = $row['id'] ?? '';
            $name = self::label( $row['value']['name'] ?? '' );
            $min = self::score_value( $row['value']['min'] ?? null );
            $max = self::score_value( $row['value']['max'] ?? null );
            $raw_color = $row['value']['color'] ?? '';
            $color = is_string( $raw_color ) ? sanitize_hex_color( $raw_color ) : null;
            if ( ! self::filter_id( $id ) || isset( $bands[ $id ] ) || in_array( $id, [ 'any', self::UNBANDED ], true ) || '' === $name || null === $min || null === $max || $min > $max || ! $color ) {
                return new WP_Error( 'dhgi_band_invalid', __( 'Each score band needs a name, a color, and a valid range from 0 to 100.', 'directorist' ) );
            }
            for ( $score = $min; $score <= $max; ++$score ) {
                if ( isset( $covered_scores[ $score ] ) ) {
                    return new WP_Error( 'dhgi_bands_overlap', __( 'HelpGent score bands cannot overlap. Adjust the minimum and maximum scores.', 'directorist' ) );
                }
                $covered_scores[ $score ] = true;
            }
            $bands[ $id ] = [ 'id' => $id, 'value' => compact( 'name', 'min', 'max', 'color' ) ];
        }

        $status_defaults = array_column( $defaults['statuses'], 'value', 'id' );
        $statuses = [];
        foreach ( $settings['statuses'] as $row ) {
            // Previously archived statuses are already inactive.
            if ( in_array( $row['value']['archived'] ?? false, [ true, 1, '1', 'true' ], true ) ) {
                continue;
            }
            $id = $row['id'] ?? '';
            $name = self::label( $row['value']['name'] ?? '' );
            if ( ! self::filter_id( $id ) || in_array( $id, [ 'all', 'unread' ], true ) || isset( $statuses[ $id ] ) || '' === $name ) {
                return new WP_Error( 'dhgi_status_invalid', __( 'Each HelpGent enquiry status needs a name and a unique ID.', 'directorist' ) );
            }
            $raw_color = $row['value']['color'] ?? ( $status_defaults[ $id ]['color'] ?? '#545865' );
            $color = is_string( $raw_color ) ? sanitize_hex_color( $raw_color ) : null;
            if ( ! $color ) {
                return new WP_Error( 'dhgi_status_color_invalid', __( 'Each HelpGent enquiry status needs a valid badge color.', 'directorist' ) );
            }
            $statuses[ $id ] = [ 'id' => $id, 'value' => compact( 'name', 'color' ) ];
        }

        $default_status = $settings['default_status'] ?? '';
        if ( ! self::filter_id( $default_status ) || ! isset( $statuses[ $default_status ] ) ) {
            return new WP_Error( 'dhgi_default_status_invalid', __( 'Choose an active default HelpGent enquiry status.', 'directorist' ) );
        }

        $clean['bands'] = array_values( $bands );
        $clean['statuses'] = array_values( $statuses );
        $clean['default_status'] = $default_status;

        return $clean;
    }

    private static function label( $value ): string {
        return is_string( $value ) ? wp_html_excerpt( sanitize_text_field( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 80 ) : '';
    }

    public static function band( array $settings, int $score ): ?array {
        foreach ( $settings['bands'] as $band ) {
            if ( $score >= $band['value']['min'] && $score <= $band['value']['max'] ) {
                return $band;
            }
        }

        return null;
    }
}
