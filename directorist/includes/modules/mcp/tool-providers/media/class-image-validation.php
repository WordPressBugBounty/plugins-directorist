<?php
/**
 * Bounded raster image and public import URL validation.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Media;

use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Validation shared by previews, imports and uploads. */
class Image_Validation {
    const UPLOAD_BYTES = 524288;
    const IMPORT_BYTES = 10485760;
    const MAX_PIXELS   = 25000000;

    /** Restrict formats to raster images also allowed by WordPress for this user. */
    public static function mimes(): array {
        return array_intersect( get_allowed_mime_types(), [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ] );
    }

    /** Respect the site's lower upload limit. */
    public static function limit( bool $import ): int {
        return min( $import ? self::IMPORT_BYTES : self::UPLOAD_BYTES, (int) wp_max_upload_size() );
    }

    /** Reject paths, executable extensions and filenames inconsistent with the image. */
    public static function filename( string $name, string $mime = '' ): string {
        Validation::check( ! preg_match( '#[/\\\\\x00]#', $name ), __( 'Supply an image filename without a path.', 'directorist' ) );
        $name = sanitize_file_name( $name );
        $type = wp_check_filetype( $name, self::mimes() );
        Validation::check( ! empty( $type['type'] ) && ( ! $mime || $mime === $type['type'] ), __( 'Use an allowed image extension matching the actual image format.', 'directorist' ) );
        return $name;
    }

    /** Validate real image headers, dimensions and byte bounds. */
    public static function image( string $data, int $limit ): array {
        Validation::check( strlen( $data ) > 0 && strlen( $data ) <= $limit, __( 'The image exceeds the permitted byte limit or is empty.', 'directorist' ) );
        $info = @getimagesizefromstring( $data );
        Validation::check( $info && in_array( $info['mime'] ?? '', self::mimes(), true ), __( 'Supply a valid JPEG, PNG, GIF or WebP image allowed by WordPress.', 'directorist' ) );
        Validation::check( $info[0] > 0 && $info[1] > 0 && $info[0] <= 10000 && $info[1] <= 10000 && $info[0] * $info[1] <= self::MAX_PIXELS, __( 'The image dimensions exceed the supported limits.', 'directorist' ) );
        return [ 'mime_type' => $info['mime'], 'width' => $info[0], 'height' => $info[1], 'bytes' => strlen( $data ), 'sha256' => hash( 'sha256', $data ) ];
    }

    /** Strictly decode the bounded upload without touching the filesystem. */
    public static function decode( string $encoded ): string {
        Validation::check( strlen( $encoded ) <= 699052 && 0 === strlen( $encoded ) % 4 && preg_match( '#^[A-Za-z0-9+/]+={0,2}$#D', $encoded ), __( 'Supply canonical base64 image data without a data URI prefix.', 'directorist' ) );
        $data = base64_decode( $encoded, true );
        Validation::check( false !== $data && base64_encode( $data ) === $encoded, __( 'The image base64 data is invalid.', 'directorist' ) );
        return $data;
    }

    /** Only public HTTPS destinations, with no credentials, fragments or custom ports. */
    public static function source_url( string $url ): string {
        $parts = wp_parse_url( $url );
        Validation::check( $parts && 'https' === ( $parts['scheme'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] ) && 443 === ( $parts['port'] ?? 443 ) && ! empty( $parts['host'] ), __( 'Import requires a public HTTPS URL without credentials, fragments or custom ports.', 'directorist' ) );
        $host = $parts['host'];
        Validation::check( preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i', $host ) && ! preg_match( '/\.(?:local|localhost|test|internal|invalid)$/i', $host ), __( 'Private or local image sources are not allowed.', 'directorist' ) );
        $addresses = gethostbynamel( $host );
        Validation::check( ! empty( $addresses ), __( 'The public image source could not be resolved.', 'directorist' ) );
        foreach ( $addresses as $address ) {
            $octets = array_map( 'intval', explode( '.', $address ) );
            $public = filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
            Validation::check( $public && $octets[0] < 224 && ! ( 100 === $octets[0] && $octets[1] >= 64 && $octets[1] <= 127 ) && ! ( 198 === $octets[0] && in_array( $octets[1], [ 18, 19 ], true ) ), __( 'Private or reserved image source addresses are not allowed.', 'directorist' ) );
        }
        Validation::check( false !== wp_http_validate_url( $url ), __( 'The image source URL is not safe.', 'directorist' ) );
        return $url;
    }
}
