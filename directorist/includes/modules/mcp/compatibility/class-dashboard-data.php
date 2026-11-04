<?php
/**
 * Compatibility facade for the original Dashboard_Data API.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP;

use Directorist\MCP\Tool_Providers\Dashboard\Activity_Data;
use Directorist\MCP\Tool_Providers\Dashboard\Financial_Data;
use Directorist\MCP\Tool_Providers\Dashboard\Listing_Data;



defined( 'ABSPATH' ) || exit;

/** Backward-compatible forwarding API. New code uses feature classes. */
class Dashboard_Data {
    const LISTING_BUDGET = Listing_Data::LISTING_BUDGET;

    /**
     * Forward listings to its implementation.
     *
     * @param array $args Validated tool input.
     * @param array $range Range.
     * @param mixed $activity Activity.
     */
    public static function listings( array $args, array $range, $activity = false ) {
        return Listing_Data::listings( $args, $range, $activity );
    }

    /**
     * Forward financial to its implementation.
     *
     * @param array $args Validated tool input.
     * @param array $range Range.
     * @param mixed $series Series.
     */
    public static function financial( array $args, array $range, $series = false ) {
        return Financial_Data::financial( $args, $range, $series );
    }

    /** Forward favorites to its implementation. */
    public static function favorites() {
        return Financial_Data::favorites();
    }

    /**
     * Forward activity types to its implementation.
     *
     * @param mixed $scope Scope.
     */
    public static function activity_types( $scope ) {
        return Activity_Data::activity_types( $scope );
    }

    /**
     * Forward event to its implementation.
     *
     * @param mixed $source Source.
     * @param mixed $id Id.
     * @param mixed $type Type.
     * @param mixed $date Date.
     * @param mixed $upcoming Upcoming.
     * @param mixed $tool Unprefixed tool action name.
     */
    public static function event( $source, $id, $type, $date, $upcoming = false, $tool = null ) {
        return Activity_Data::event( $source, $id, $type, $date, $upcoming, $tool );
    }

    /**
     * Forward activity to its implementation.
     *
     * @param array $args Validated tool input.
     * @param array $range Range.
     */
    public static function activity( array $args, array $range ) {
        return Activity_Data::activity( $args, $range );
    }
}
