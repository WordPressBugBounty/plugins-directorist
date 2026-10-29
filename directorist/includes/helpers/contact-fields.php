<?php
/**
 * Normalization shared by contact field input, imports and presentation.
 *
 * @package Directorist
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once dirname( __DIR__ ) . '/cli/contact-fields.php';
}

/** Trim literal boundary whitespace without decoding percent-encoded data. */
function directorist_normalize_contact_value( $value ) {
    if ( ! is_string( $value ) ) {
        return $value;
    }

    $normalized = preg_replace( '/^[\x09-\x0D\x20\p{Z}\x{200B}\x{FEFF}]+|[\x09-\x0D\x20\p{Z}\x{200B}\x{FEFF}]+$/u', '', $value );
    // Preserve invalid input so validation can reject it instead of deleting data.
    return null === $normalized ? $value : $normalized;
}

function directorist_is_contact_string( $value ) {
    return is_string( $value ) && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1F\x7F]/', $value );
}

function directorist_normalize_contact_field( $value, $type ) {
    if ( in_array( $type, [ 'url', 'email', 'video' ], true ) ) {
        return directorist_normalize_contact_value( $value );
    }

    if ( 'button' === $type && is_array( $value ) && isset( $value['button_url_label'] ) ) {
        $value['button_url_label'] = directorist_normalize_contact_value( $value['button_url_label'] );
    }

    if ( 'social_info' === $type && is_array( $value ) ) {
        foreach ( $value as &$item ) {
            if ( is_array( $item ) && isset( $item['url'] ) ) {
                $item['url'] = directorist_normalize_contact_value( $item['url'] );
            }
        }
        unset( $item );
    }

    return $value;
}

/** Resolve contact metadata using the directory's field definitions. */
function directorist_get_contact_fields( $directory_id ) {
    $fields = [];
    foreach ( directorist_get_listing_form_fields( $directory_id ) as $props ) {
        $field = \Directorist\Fields\Fields::create( $props );
        if ( in_array( $field->type, [ 'url', 'email', 'video', 'button', 'social_info' ], true ) ) {
            $fields[ '_' . $field->get_key() ] = $field;
        }
    }
    return $fields;
}

/** Validate only submitted contact values; callers retain existing data on error. */
function directorist_prepare_contact_field( $field, $value ) {
    $field = clone $field;
    $posted = [ $field->get_key() => $value ];
    if ( 'social_info' === $field->type ) {
        $posted['social'] = $value;
    }

    if ( $field->is_value_empty( $posted ) ) {
        if ( $field->is_required() ) {
            return new WP_Error( 'directorist_contact_required', __( 'This field is required.', 'directorist' ) );
        }
    } elseif ( ! $field->validate( $posted ) ) {
        return new WP_Error( 'directorist_invalid_contact', $field->get_error() );
    }

    return $field->sanitize( $posted );
}

/** REST validates before sanitizing, so normalize for both callbacks. */
function directorist_validate_contact_rest_arg( $value, $request, $param ) {
    $attributes = $request->get_attributes();
    return rest_validate_value_from_schema( directorist_normalize_contact_value( $value ), $attributes['args'][ $param ], $param );
}

function directorist_normalize_listing_rest_fields( $value, $request ) {
    if ( ! is_array( $value ) ) {
        return $value;
    }
    $directory = $request->get_param( 'directory' );
    if ( ! $directory && $request->get_param( 'id' ) ) {
        $directory = directorist_get_listings_directory_type( $request->get_param( 'id' ) );
    }
    $directory = $directory ? absint( $directory ) : directorist_get_default_directory();
    foreach ( directorist_get_contact_fields( $directory ) as $field ) {
        $key = $field->get_key();
        if ( array_key_exists( $key, $value ) ) {
            $value[ $key ] = directorist_normalize_contact_field( $value[ $key ], $field->type );
        }
    }
    // Fixed REST aliases can differ from the directory's storage field keys.
    foreach ( [ 'email', 'website', 'video_url' ] as $key ) {
        if ( isset( $value[ $key ] ) ) {
            $value[ $key ] = directorist_normalize_contact_value( $value[ $key ] );
        }
    }
    return $value;
}

function directorist_validate_listing_rest_fields( $value, $request, $param ) {
    $attributes = $request->get_attributes();
    $value = directorist_normalize_listing_rest_fields( $value, $request );
    $schema = $attributes['args'][ $param ];
    if ( is_array( $value ) && isset( $value['email'] ) && '' === $value['email'] ) {
        // Required contact fields are checked using the selected directory's definition.
        unset( $schema['properties']['email']['format'] );
    }
    return rest_validate_value_from_schema( $value, $schema, $param );
}

function directorist_sanitize_listing_rest_fields( $value, $request, $param ) {
    $attributes = $request->get_attributes();
    return rest_sanitize_value_from_schema( directorist_normalize_listing_rest_fields( $value, $request ), $attributes['args'][ $param ], $param );
}

function directorist_validate_listing_email_rest_arg( $value, $request, $param ) {
    $attributes = $request->get_attributes();
    $schema = $attributes['args'][ $param ];
    $value = directorist_normalize_contact_value( $value );
    if ( '' === $value ) {
        unset( $schema['format'] );
    }
    return rest_validate_value_from_schema( $value, $schema, $param );
}

function directorist_normalize_social_rest_arg( $value ) {
    if ( is_array( $value ) ) {
        foreach ( [ 'facebook', 'twitter', 'linkedin', 'youtube' ] as $key ) {
            if ( isset( $value[ $key ] ) ) {
                $value[ $key ] = directorist_normalize_contact_value( $value[ $key ] );
            }
        }
    }
    return $value;
}

function directorist_validate_social_rest_arg( $value, $request, $param ) {
    $attributes = $request->get_attributes();
    $value = directorist_normalize_social_rest_arg( $value );
    $result = rest_validate_value_from_schema( $value, $attributes['args'][ $param ], $param );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    foreach ( $value as $url ) {
        if ( ! directorist_is_contact_string( $url ) || ( '' !== $url && '' === esc_url_raw( $url ) ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid social URL.', 'directorist' ) );
        }
    }
    return true;
}

/** A legacy invalid email must not become a different mailto recipient. */
function directorist_contact_email_for_display( $value ) {
    $value = directorist_normalize_contact_value( $value );
    return directorist_is_contact_string( $value ) && is_email( $value ) ? $value : '';
}

function directorist_contact_url_for_display( $value ) {
    $value = directorist_normalize_contact_value( $value );
    return directorist_is_contact_string( $value ) ? $value : '';
}

add_action( 'admin_notices', 'directorist_contact_field_admin_notice' );

function directorist_contact_field_admin_notice() {
    $key = 'directorist_contact_errors_' . get_current_user_id();
    $errors = get_transient( $key );
    if ( ! $errors ) {
        return;
    }
    delete_transient( $key );
    echo '<div class="notice notice-error"><p>' . esc_html__( 'Some contact fields were not saved. Their previous values were kept:', 'directorist' ) . '</p><ul>';
    foreach ( $errors as $error ) {
        echo '<li>' . esc_html( $error ) . '</li>';
    }
    echo '</ul></div>';
}
