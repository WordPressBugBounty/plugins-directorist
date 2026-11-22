<?php
/**
 * Directorist File Field class.
 *
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class File_Field extends Base_Field {
    public $type = 'file';

    public function validate( $posted_data ) {
        if ( false === $this->sanitize_files( $this->get_value( $posted_data ), $posted_data ) ) {
            $this->add_error( __( 'Invalid file data. Please upload the file again.', 'directorist' ) );
            return false;
        }

        return true;
    }

    public function sanitize( $posted_data ) {
        $value = $this->sanitize_files( $this->get_value( $posted_data ), $posted_data );
        return false === $value ? '' : $value;
    }

    /**
     * Support URL-only uploads and legacy URL|ID|title|caption records.
     */
    private function sanitize_files( $value, $posted_data ) {
        if ( null === $value || '' === $value ) {
            return '';
        }

        if ( ! is_string( $value ) ) {
            return false;
        }

        $stored_files = [];
        $listing_id   = isset( $posted_data['listing_id'] ) && is_scalar( $posted_data['listing_id'] )
            ? absint( $posted_data['listing_id'] ) : 0;
        $post_type    = get_post_type_object( ATBDP_POST_TYPE );

        // Retaining an existing file does not grant permission to edit its attachment.
        if ( $listing_id && $post_type && get_post_type( $listing_id ) === ATBDP_POST_TYPE
            && current_user_can( $post_type->cap->edit_post, $listing_id ) ) {
            $stored_value = get_post_meta( $listing_id, '_' . $this->get_key(), true );
            if ( is_string( $stored_value ) ) {
                $stored_files = explode( '::', $stored_value );
            }
        }

        $files = [];
        foreach ( explode( '::', $value ) as $file ) {
            $parts = explode( '|', $file );
            $url   = $parts[0];
            // esc_url_raw alone can repair malformed input; reject it instead.
            if ( count( $parts ) > 4 || preg_match( '/[\x00-\x20<>"\'\\\\]/', $url )
                || ! preg_match( '#^https?://#i', $url )
                || ! wp_parse_url( $url, PHP_URL_HOST )
                || esc_url_raw( $url, [ 'http', 'https' ] ) !== $url ) {
                return false;
            }

            if ( isset( $parts[1] ) && '' !== $parts[1] ) {
                if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] < 1 ) {
                    return false;
                }

                $attachment = get_post( (int) $parts[1] );
                if ( ! $attachment || 'attachment' !== $attachment->post_type
                    || ! get_current_user_id()
                    || ( (int) $attachment->post_author !== get_current_user_id()
                        && ! current_user_can( 'edit_post', $attachment->ID )
                        && ! in_array( $file, $stored_files, true ) ) ) {
                    return false;
                }
                $parts[1] = (string) $attachment->ID;
            }

            foreach ( [ 2, 3 ] as $index ) {
                if ( isset( $parts[ $index ] ) ) {
                    $parts[ $index ] = sanitize_text_field( $parts[ $index ] );
                }
            }
            $files[] = implode( '|', $parts );
        }

        return implode( '::', $files );
    }

    public function get_file_types() {
        return $this->file_type;
    }

    public function get_file_size() {
        return wp_convert_hr_to_bytes( $this->file_size );
    }
}

Fields::register( new File_Field() );
