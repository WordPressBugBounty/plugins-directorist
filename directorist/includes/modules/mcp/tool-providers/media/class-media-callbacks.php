<?php
/**
 * Native image media operations behind the MCP guards.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Media;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Safety\Transaction;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Result;
use Directorist\MCP\Support\Uncommitted_Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Read attachments and prepare confirmed native uploads/imports. */
class Media_Callbacks {
    /** Inspect only an editable image, without exposing filesystem paths. */
    public static function get_media( array $args = [] ): array {
        $id   = Access_Control::attachment( $args['attachment_id'], true );
        $post = get_post( $id );
        $meta = wp_get_attachment_metadata( $id );
        $file = get_attached_file( $id );
        $size = $file && is_file( $file ) ? filesize( $file ) : false;
        return [
            'attachment_id' => $id,
            'title'         => (string) $post->post_title,
            'alt_text'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'caption'       => (string) $post->post_excerpt,
            'url'           => wp_get_attachment_url( $id ),
            'mime_type'     => (string) get_post_mime_type( $id ),
            'width'         => (int) ( $meta['width'] ?? 0 ),
            'height'        => (int) ( $meta['height'] ?? 0 ),
            'bytes'         => false === $size ? null : (int) $size,
            'file_exists'   => false !== $size,
            'sha256'        => false !== $size && $size <= Image_Validation::IMPORT_BYTES ? hash_file( 'sha256', $file ) : null,
            'source_url'    => (string) get_post_meta( $id, '_source_url', true ),
            'source_page'   => (string) get_post_meta( $id, '_directorist_mcp_source_page', true ),
            'credit'        => (string) get_post_meta( $id, '_directorist_mcp_credit', true ),
        ];
    }

    /** Prepare a base64 upload, validating its content entirely in memory. */
    public static function upload_media( array $args = [] ): array {
        return self::prepare( $args, false );
    }

    /** Prepare a URL import without downloading or creating files during preview. */
    public static function import_media( array $args = [] ): array {
        return self::prepare( $args, true );
    }

    /** Build a preview bound to current format/size policy and the exact input. */
    private static function prepare( array $args, bool $import ): array {
        $limit = Image_Validation::limit( $import );
        Validation::check( $limit > 0 && Image_Validation::mimes(), __( 'Image uploads are unavailable under the current WordPress policy.', 'directorist' ), 'unsupported_operation' );
        $metadata = [];
        foreach ( [ 'title', 'alt_text', 'caption', 'credit', 'source_page' ] as $key ) {
            $metadata[ $key ] = sanitize_text_field( $args[ $key ] ?? '' );
        }
        if ( $metadata['source_page'] ) {
            Validation::check( 'https' === wp_parse_url( $metadata['source_page'], PHP_URL_SCHEME ) && ! wp_parse_url( $metadata['source_page'], PHP_URL_USER ) && ! wp_parse_url( $metadata['source_page'], PHP_URL_PASS ), __( 'Source attribution must use a public HTTPS page without credentials.', 'directorist' ) );
            $metadata['source_page'] = esc_url_raw( $metadata['source_page'], [ 'https' ] );
        }
        $filename = isset( $args['file_name'] ) ? Image_Validation::filename( $args['file_name'] ) : '';
        $data     = '';
        $url      = '';
        $info     = [];
        if ( $import ) {
            $url = Image_Validation::source_url( $args['source_url'] );
        } else {
            $data = Image_Validation::decode( $args['data_base64'] );
            $info = Image_Validation::image( $data, $limit );
            Image_Validation::filename( $filename, $info['mime_type'] );
        }
        $state = Canonical_Data::hash( [ $metadata, $filename, $url, $info, $limit, Image_Validation::mimes() ] );
        return [
            'state'        => $state,
            'confirm'      => true,
            // Files are not transactional: coordinate the DB transaction and file
            // cleanup together inside execute, including rollback of metadata.
            'orchestrated' => true,
            'preview'      => [ 'source' => $import ? 'https' : 'base64', 'source_url' => $url, 'file_name' => $filename, 'metadata' => $metadata, 'image' => $info, 'max_bytes' => $limit, 'version' => $state ],
            'warnings'     => $import ? [ __( 'Remote content, size and image format are checked at execution; redirects are rejected.', 'directorist' ) ] : [],
            'execute'      => static function () use ( $metadata, $filename, $url, $data, $limit ) {
                return self::create( $metadata, $filename, $url, $data, $limit );
            },
        ];
    }

    /** Download/write the temporary file only after confirmation and reservation. */
    private static function create( array $metadata, string $filename, string $url, string $data, int $limit ): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $temp = wp_tempnam( 'directorist-mcp-image' );
        Validation::check( (bool) $temp, __( 'Could not create a temporary image file.', 'directorist' ), 'operation_failed' );
        $file      = '';
        $id        = 0;
        $started   = false;
        $generated = [];
        $capture   = static function ( $value, $attachment_id ) use ( &$generated, &$id ) {
            if ( $id && $id === (int) $attachment_id && is_array( $value ) ) {
                $generated = $value;
            }
            return $value;
        };
        add_filter( 'wp_update_attachment_metadata', $capture, PHP_INT_MAX, 2 );
        try {
            if ( $url ) {
                Image_Validation::source_url( $url );
                $accept   = $filename ? wp_check_filetype( $filename, Image_Validation::mimes() )['type'] : implode( ',', array_unique( Image_Validation::mimes() ) );
                $response = wp_safe_remote_get( $url, [ 'timeout' => 30, 'redirection' => 0, 'stream' => true, 'filename' => $temp, 'limit_response_size' => $limit + 1, 'cookies' => [], 'headers' => [ 'Accept' => $accept ] ] );
                Validation::check( ! is_wp_error( $response ), __( 'The remote image could not be downloaded.', 'directorist' ), 'source_unavailable' );
                Validation::check( 200 === wp_remote_retrieve_response_code( $response ), __( 'The image source must return HTTP 200 directly; redirects are not allowed.', 'directorist' ), 'source_unavailable' );
                $data = file_get_contents( $temp );
                Validation::check( is_string( $data ), __( 'The downloaded image could not be read.', 'directorist' ), 'operation_failed' );
            } else {
                Validation::check( strlen( $data ) === file_put_contents( $temp, $data ), __( 'The temporary image could not be written.', 'directorist' ), 'operation_failed' );
            }
            $info = Image_Validation::image( $data, $limit );
            if ( ! $filename ) {
                $extensions = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
                $filename   = ( sanitize_title( $metadata['title'] ) ?: 'imported-image' ) . '.' . $extensions[ $info['mime_type'] ];
            }
            $filename = Image_Validation::filename( $filename, $info['mime_type'] );
            $checked  = wp_check_filetype_and_ext( $temp, $filename, Image_Validation::mimes() );
            Validation::check( ( $checked['type'] ?? '' ) === $info['mime_type'], __( 'The filename and image content do not match.', 'directorist' ) );
            if ( is_multisite() && ! get_site_option( 'upload_space_check_disabled' ) ) {
                Validation::check( strlen( $data ) <= get_upload_space_available(), __( 'The image exceeds the available site upload quota.', 'directorist' ) );
            }

            $started = true;
            return Transaction::atomic(
                static function () use ( $temp, $filename, $metadata, $url, &$file, &$id, &$generated ) {
                    $upload = [ 'name' => $filename, 'tmp_name' => $temp, 'error' => 0, 'size' => filesize( $temp ) ];
                    $moved  = wp_handle_sideload( $upload, [ 'test_form' => false, 'mimes' => Image_Validation::mimes() ] );
                    Validation::check( empty( $moved['error'] ), __( 'WordPress could not store the uploaded image.', 'directorist' ), 'operation_failed' );
                    $file = $moved['file'];
                    $id   = Validation::unwrap(
                        wp_insert_attachment(
                            wp_slash( [ 'post_mime_type' => $moved['type'], 'post_title' => $metadata['title'] ?: pathinfo( $filename, PATHINFO_FILENAME ), 'post_excerpt' => $metadata['caption'], 'post_content' => '', 'post_status' => 'inherit', 'post_author' => get_current_user_id(), 'post_parent' => 0 ] ), $file, 0, true
                        )
                    );
                    update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $metadata['alt_text'] ) );
                    update_post_meta( $id, '_directorist_mcp_credit', wp_slash( $metadata['credit'] ) );
                    update_post_meta( $id, '_directorist_mcp_source_page', wp_slash( $metadata['source_page'] ) );
                    if ( $url ) {
                        update_post_meta( $id, '_source_url', wp_slash( $url ) );
                    }
                    $generated = wp_generate_attachment_metadata( $id, $file );
                    Validation::check( ! empty( $generated['width'] ) && ! empty( $generated['height'] ), __( 'WordPress could not generate image metadata.', 'directorist' ), 'operation_failed' );
                    wp_update_attachment_metadata( $id, $generated );
                    return Result::result( [ 'attachment_id' => (int) $id, 'media' => self::get_media( [ 'attachment_id' => (int) $id ] ) ] );
                }
            );
        } catch ( \Throwable $error ) {
            if ( ! $started ) {
                throw new Uncommitted_Failure( $error instanceof Failure ? $error->reason : 'operation_failed', $error instanceof Failure ? $error->getMessage() : __( 'The image could not be prepared for storage.', 'directorist' ) );
            }
            if ( $file && ! ( $error instanceof Failure && 'outcome_unknown' === $error->reason ) ) {
                wp_delete_attachment_files( $id, $generated, [], $file );
            }
            throw $error;
        } finally {
            remove_filter( 'wp_update_attachment_metadata', $capture, PHP_INT_MAX );
            if ( is_file( $temp ) ) {
                wp_delete_file( $temp );
            }
        }
    }
}
