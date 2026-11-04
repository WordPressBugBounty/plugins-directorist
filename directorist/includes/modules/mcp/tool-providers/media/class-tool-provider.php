<?php
/**
 * Image upload, import and inspection tools.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Media;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Register media operations with native upload and attachment permissions. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Describe the bounded image tools. */
    public static function definitions(): array {
        $text        = [ 'type' => 'string', 'maxLength' => 200 ];
        $metadata    = [
            'title'       => $text,
            'alt_text'    => [ 'type' => 'string', 'maxLength' => 500 ],
            'caption'     => [ 'type' => 'string', 'maxLength' => 1000 ],
            'credit'      => $text,
            'source_page' => [ 'type' => 'string', 'format' => 'uri', 'maxLength' => 2048 ],
            'file_name'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 160 ],
        ];
        $rows        = [
            'get-media'    => [ false, [ 'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], [ 'attachment_id' ], __( 'Inspect an authorized image attachment, including URL, dimensions, size, checksum and attribution.', 'directorist' ) ],
            'upload-media' => [ true, $metadata + [ 'data_base64' => [ 'type' => 'string', 'minLength' => 4, 'maxLength' => 699052 ] ], [ 'file_name', 'data_base64' ], __( 'Preview and upload a base64 JPEG, PNG, GIF or WebP image (up to 512 KiB). No data URI or local file paths. Requires upload_files.', 'directorist' ) ],
            'import-media' => [ true, $metadata + [ 'source_url' => [ 'type' => 'string', 'format' => 'uri', 'maxLength' => 2048 ] ], [ 'source_url' ], __( 'Preview and import a public HTTPS JPEG, PNG, GIF or WebP image (up to 10 MiB). No private hosts, credentials or redirects. Requires upload_files.', 'directorist' ) ],
        ];
        $definitions = [];
        foreach ( $rows as $name => $row ) {
            list( $write, $properties, $required, $description ) = $row;
            $definitions[ $name ]                                = [
                'group'       => 'media',
                'write'       => $write,
                'description' => $description,
                'schema'      => [ 'type' => 'object', 'properties' => $write ? $properties + Tool_Registry::mutation_controls() : $properties, 'required' => $required, 'additionalProperties' => false ],
            ];
        }
        return $definitions;
    }

    /** Bind named callbacks independently of the public schemas. */
    public static function callbacks(): array {
        return [
            'get-media'    => [ Media_Callbacks::class, 'get_media' ],
            'upload-media' => [ Media_Callbacks::class, 'upload_media' ],
            'import-media' => [ Media_Callbacks::class, 'import_media' ],
        ];
    }

    /** Enforce capabilities before policies and object access afterward. */
    public static function authorize( string $tool, array $args, string $phase ): void {
        Validation::check( current_user_can( 'upload_files' ), __( 'You cannot upload media.', 'directorist' ), 'forbidden' );
        if ( 'after' === $phase && 'get-media' === $tool ) {
            Access_Control::attachment( $args['attachment_id'], true );
        }
    }

    /** Publish only to connected users with native media upload access. */
    public static function discover( string $tool ): bool {
        return current_user_can( 'upload_files' );
    }
}
