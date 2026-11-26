<?php
/**
 * Directory-specific price range options and their deprecated values.
 *
 * @package Directorist
 */

namespace Directorist;

defined( 'ABSPATH' ) || exit;

class Price_Ranges {
    public static function defaults() {
        $symbol = html_entity_decode( atbdp_currency_symbol( directorist_get_currency() ), ENT_QUOTES, 'UTF-8' );
        $labels = [
            /* translators: %s: Currency symbol. */
            'bellow_economy' => __( 'Cheap (%s)', 'directorist' ),
            /* translators: %s: Repeated currency symbol. */
            'economy'       => __( 'Economy (%s)', 'directorist' ),
            /* translators: %s: Repeated currency symbol. */
            'moderate'      => __( 'Moderate (%s)', 'directorist' ),
            /* translators: %s: Repeated currency symbol. */
            'skimming'      => __( 'Ultra High (%s)', 'directorist' ),
        ];
        $rows = [];
        foreach ( $labels as $value => $label ) {
            $rows[] = [
                'option_value'      => $value,
                'option_label'      => sprintf( $label, str_repeat( $symbol, count( $rows ) + 1 ) ),
                'deprecated_values' => '',
            ];
        }

        return $rows;
    }

    public static function options( $field ) {
        if ( ! isset( $field['price_range_options'] ) ) {
            return self::defaults();
        }

        $options = self::validate_options( $field['price_range_options'], ( $field['pricing_type'] ?? 'both' ) !== 'price_unit' );
        return is_wp_error( $options ) ? self::defaults() : $options;
    }

    public static function directory_options( $directory_id ) {
        $form = directorist_submission_form_fields( (int) $directory_id );
        return self::options( $form['fields']['pricing'] ?? [] );
    }

    /**
     * Sanitize the builder rows and ensure each value identifies only one option.
     *
     * @return array|\WP_Error
     */
    public static function validate_options( $rows, $range_enabled = true ) {
        if ( ! is_array( $rows ) || ( $range_enabled && ! $rows ) ) {
            return new \WP_Error( 'invalid_price_range_options', __( 'Add at least one Price Range option with a label and value.', 'directorist' ) );
        }

        $options = [];
        $seen    = [];
        foreach ( array_values( $rows ) as $index => $row ) {
            $value   = self::clean( $row['option_value'] ?? null );
            $label   = self::clean( $row['option_label'] ?? null );
            $aliases = $row['deprecated_values'] ?? '';

            if ( '' === $label || '' === $value || false !== strpos( $value, ',' ) || ! is_string( $aliases ) ) {
                return new \WP_Error(
                    'invalid_price_range_options',
                    /* translators: %d: Price Range option row number. */
                    sprintf( __( 'Price Range option %d needs a label and a value without commas. Enter deprecated values as text.', 'directorist' ), $index + 1 )
                );
            }

            $aliases = array_values( array_unique( array_filter( array_map( [ self::class, 'clean' ], explode( ',', $aliases ) ), 'strlen' ) ) );
            foreach ( array_merge( [ $value ], $aliases ) as $key ) {
                if ( isset( $seen[ $key ] ) || 'none' === $key ) {
                    return new \WP_Error(
                        'invalid_price_range_options',
                        /* translators: %s: The duplicate or reserved value. */
                        sprintf( __( 'Price Range value “%s” is already used or reserved. Use a different value.', 'directorist' ), $key )
                    );
                }
                $seen[ $key ] = true;
            }

            $options[] = [
                'option_value'      => $value,
                'option_label'      => $label,
                'deprecated_values' => implode( ', ', $aliases ),
            ];
        }

        return $options;
    }

    public static function clean( $value ) {
        return is_string( $value ) ? sanitize_text_field( $value ) : '';
    }

    private static function aliases( $option ) {
        return '' === $option['deprecated_values'] ? [] : explode( ', ', $option['deprecated_values'] );
    }

    public static function find( $value, $options ) {
        foreach ( $options as $option ) {
            if ( $value === $option['option_value'] || in_array( $value, self::aliases( $option ), true ) ) {
                return $option;
            }
        }

        return null;
    }

    public static function current_value( $value, $options ) {
        return self::find( $value, $options )['option_value'] ?? $value;
    }

    public static function listing_directory( $listing_id ) {
        return (int) ( directorist_get_listing_directory( $listing_id ) ?: directorist_get_default_directory() );
    }

    public static function listing_value( $listing_id ) {
        $value = (string) get_post_meta( $listing_id, '_price_range', true );
        return '' === $value ? '' : self::current_value( $value, self::directory_options( self::listing_directory( $listing_id ) ) );
    }

    public static function can_keep_saved_value( $value, $listing_id, $directory_id ) {
        return $listing_id && self::listing_directory( $listing_id ) === (int) $directory_id
            && $value === get_post_meta( $listing_id, '_price_range', true );
    }

    /**
     * Recover an existing range when an edit omits the pricing fields.
     */
    public static function saved_range_for_edit( $field, $listing_id, $directory_id ) {
        if ( ! $listing_id || ( $field['pricing_type'] ?? 'both' ) === 'price_unit'
            || self::listing_directory( $listing_id ) !== $directory_id || 'range' !== Helper::pricing_type( $listing_id ) ) {
            return null;
        }

        $range = get_post_meta( $listing_id, '_price_range', true );
        return is_string( $range ) ? $range : null;
    }

    /**
     * Return the value to store, or null for an invalid new selection.
     */
    public static function submitted_value( $raw, $field, $listing_id, $directory_id ) {
        if ( ! is_string( $raw ) ) {
            return null;
        }

        if ( '' === $raw || self::can_keep_saved_value( $raw, $listing_id, $directory_id ) ) {
            return self::current_value( $raw, self::options( $field ) );
        }

        return self::find( self::clean( $raw ), self::options( $field ) )['option_value'] ?? null;
    }

    public static function legacy_label( $value ) {
        return self::find( $value, self::defaults() )['option_label'] ?? $value;
    }

    public static function default_level( $option ) {
        foreach ( self::defaults() as $index => $default ) {
            if ( $default['option_value'] === $option['option_value'] && $default['option_label'] === $option['option_label'] ) {
                return $index + 1;
            }
        }

        return 0;
    }

    public static function presentation( $value, $listing_id = 0 ) {
        $option = self::find( $value, $listing_id ? self::directory_options( self::listing_directory( $listing_id ) ) : self::defaults() );

        return [
            'active_items'     => $option ? self::default_level( $option ) : 0,
            'currency'         => atbdp_currency_symbol( directorist_get_currency() ),
            'price_range_text' => $option['option_label'] ?? self::legacy_label( $value ),
        ];
    }

    public static function display_content( $active_items, $currency, $price_range_text ) {
        if ( ! $active_items ) {
            return esc_html( $price_range_text );
        }

        $symbol = esc_html( $currency );
        return str_repeat( '<span class="directorist-price-active">' . $symbol . '</span>', $active_items )
             . str_repeat( '<span>' . $symbol . '</span>', 4 - $active_items );
    }

    private static function equivalent_values( $value, $directory_id ) {
        $option = self::find( $value, self::directory_options( $directory_id ) );
        return $option ? array_merge( [ $option['option_value'] ], self::aliases( $option ) ) : [ $value ];
    }

    private static function value_clause( $values ) {
        return [ 'key' => '_price_range', 'value' => $values, 'compare' => 'IN', 'type' => 'BINARY' ];
    }

    public static function query_clause( $value, $directory_id = 0 ) {
        if ( $directory_id ) {
            return self::value_clause( self::equivalent_values( $value, $directory_id ) );
        }

        // An unscoped search can match the requested value everywhere. Any
        // additional matches must belong to a directory that defined the alias.
        $exact = self::value_clause( [ $value ] );
        $query = [ 'relation' => 'OR', $exact ];
        $directories = directorist_get_directories( [ 'fields' => 'ids' ] );
        if ( is_wp_error( $directories ) ) {
            return $exact;
        }

        foreach ( $directories as $id ) {
            $additional = array_values( array_diff( self::equivalent_values( $value, $id ), [ $value ] ) );
            if ( $additional ) {
                $query[] = [
                    'relation' => 'AND',
                    [ 'key' => '_directory_type', 'value' => (int) $id ],
                    self::value_clause( $additional ),
                ];
            }
        }

        return count( $query ) > 2 ? $query : $exact;
    }
}
