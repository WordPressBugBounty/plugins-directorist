<?php
/**
 * Pagination for support.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Support;

defined( 'ABSPATH' ) || exit;

/** Pagination. */
class Pagination {
    /**
     * Paging.
     *
     * @param array $args Validated tool input.
     */
    public static function paging( array $args ): array {
        $page = $args['page'] ?? 1;
        $size = $args['per_page'] ?? 20;
        Validation::check( is_int( $page ) && $page > 0 && is_int( $size ) && $size >= 1 && $size <= 100, __( 'Use a positive page and a page size between 1 and 100.', 'directorist' ) );
        return [ $page, $size ];
    }

    /**
     * Page.
     *
     * @param array $items Items.
     * @param array $args Validated tool input.
     */
    public static function page( array $items, array $args ): array {
        list( $page, $size ) = self::paging( $args );
        return self::collection( array_slice( array_values( $items ), ( $page - 1 ) * $size, $size ), count( $items ), $page, $size );
    }

    /**
     * Collection.
     *
     * @param array $items Items.
     * @param mixed $total Total.
     * @param mixed $page Page.
     * @param mixed $size Size.
     */
    public static function collection( array $items, $total, $page, $size ): array {
        return [
            'items'       => array_values( $items ),
            'page'        => (int) $page,
            'per_page'    => (int) $size,
            'total'       => (int) $total,
            'total_pages' => (int) ceil( $total / $size ),
            'has_more'    => (bool) ( $page * $size < $total )
        ];
    }
}
