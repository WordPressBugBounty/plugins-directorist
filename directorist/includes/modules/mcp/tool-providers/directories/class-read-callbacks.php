<?php
/**
 * Read Callbacks for directories.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Directories;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Read Callbacks. */
class Read_Callbacks {
    /**
     * Read.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function read( string $tool, array $args ): array {
        Access_Control::admin();
        if ( 'list-directories' === $tool ) {
            list( $page, $size ) = Pagination::paging( $args );
            $query               = [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'search' => $args['search'] ?? '', 'orderby' => 'term_id', 'order' => 'ASC' ];
            if ( isset( $args['is_default'] ) ) {
                $query[ $args['is_default'] ? 'include' : 'exclude' ] = [ directorist_get_default_directory() ];
            }
            $total = Validation::unwrap( wp_count_terms( $query ) );
            $terms = Validation::unwrap( get_terms( $query + [ 'number' => $size, 'offset' => ( $page - 1 ) * $size ] ) );
            return Pagination::collection( array_map( [ Directory_Configuration::class, 'summary' ], $terms ), $total, $page, $size );
        }
        $id     = Validation::directory( $args['directory_id'] )->term_id;
        $config = Directory_Configuration::configuration( $id );
        $area   = $args['area'] ?? null;
        if ( $area ) {
            Validation::check( isset( Directory_Configuration::AREAS[ $area ] ), __( 'Unsupported builder area.', 'directorist' ) );
            $config = array_intersect_key( $config, array_flip( Directory_Configuration::AREAS[ $area ] ) );
        }
        $result = Directory_Configuration::summary( get_term( $id, ATBDP_DIRECTORY_TYPE ) ) + [ 'configuration' => $config, 'version' => Directory_Configuration::version( $id ) ];
        if ( ! $area || 'general' === $area ) {
            $result['default_preview_image_url'] = (string) \Directorist\Helper::default_preview_image_src( $id );
        }
        if ( 'get-builder-schema' === $tool ) {
            $result['schemas'] = [];
            foreach ( $config as $key => $value ) {
                $definition                = Directory_Configuration::definitions()[ $key ];
                $result['schemas'][ $key ] = [
                    'options'   => Builder_Schema::option_schema( $key, $definition ),
                    'widgets'   => Builder_Schema::widgets( $definition ),
                    'sections'  => Builder_Schema::sections( (array) $value ),
                    'slots'     => Builder_Schema::slots( (array) $value, $definition ),
                    'templates' => array_keys( $definition['card_templates'] ?? [] )
                ];
                if ( 'preview_image' === $key ) {
                    $result['schemas'][ $key ]['options'] = [ 'type' => 'integer', 'minimum' => 0, 'description' => __( 'Set an authorized image attachment ID through update-directory; 0 clears it. Reads return the native stored URL.', 'directorist' ) ];
                }
            }
            $result['operation_contract'] = [
                'configuration' => 'Native configuration key in the selected area.',
                'entity'        => [ 'field', 'section', 'widget', 'options', 'template' ],
                'action'        => [ 'add', 'update', 'move', 'reorder', 'remove' ],
                'id'            => 'For field/section additions, supply a unique stable ID (1–100 letters, numbers, hyphens or underscores) before previewing. Existing objects use their discovered IDs. Layout widgets default to their native widget name.',
                'slot'          => 'Discovered slot path; never a term-meta path.'
            ];
        }
        return $result;
    }

    /**
     * Handle list-directories through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_directories( array $args = [] ): array {
        return self::read( 'list-directories', $args );
    }

    /**
     * Handle get-directory through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_directory( array $args = [] ): array {
        return self::read( 'get-directory', $args );
    }

    /**
     * Handle get-builder-schema through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_builder_schema( array $args = [] ): array {
        return self::read( 'get-builder-schema', $args );
    }
}
