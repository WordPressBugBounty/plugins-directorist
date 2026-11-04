<?php
/**
 * Tool Provider for dashboard.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Dashboard;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Support\Read_Support;

defined( 'ABSPATH' ) || exit;

/** Tool Provider. */
class Tool_Provider implements Contracts_Tool_Provider {
    /** Definitions. */
    public static function definitions(): array {
        $scope     = [ 'scope' => Read_Support::enum_schema( [ 'site', 'own' ] ) ];
        $period    = [ 'period' => Read_Support::enum_schema( [ 'last_7_days', 'last_30_days', 'last_90_days' ] ), 'start_date' => Read_Support::string_schema( 10 ), 'end_date' => Read_Support::string_schema( 10 ) ];
        $directory = [ 'directory_id' => [ 'type' => 'integer', 'minimum' => 1 ] ];
        $filters   = $scope + $directory + $period;
        return [
            'get-dashboard-schema'    => Read_Support::definition( 'dashboard', 'Discover authorized dashboard metrics, sources, sections, scopes and constraints.', $scope ),
            'get-dashboard-overview'  => Read_Support::definition( 'dashboard', 'Read authorized listing health and purchase summaries with source and range semantics.', $filters + [ 'sections' => Read_Support::list_schema( 8 ) ] ),
            'get-dashboard-series'    => Read_Support::definition( 'dashboard', 'Read measured daily trends or an explicitly identified current distribution.', $filters + [ 'metric' => Read_Support::string_schema(), 'currency' => [ 'type' => 'string', 'pattern' => '^[A-Z]{3,4}$', 'maxLength' => 4 ], 'compare_previous' => [ 'type' => 'boolean' ], 'series_kind' => Read_Support::enum_schema( [ 'auto', 'daily' ] ) ], [ 'metric' ] ),
            'list-dashboard-activity' => Read_Support::definition( 'dashboard', 'Read a bounded permission-filtered recent feed, with separate upcoming reminders.', $filters + Read_Support::paging_schema() + [ 'type' => Read_Support::enum_schema( [ 'all', 'listing', 'review', 'payment', 'user' ] ) ] ),
            'get-dashboard-guidance'  => Read_Support::definition( 'dashboard', 'Read native setup progress and safe next actions without executing them.', $scope + $directory + Read_Support::paging_schema() + [ 'sections' => Read_Support::list_schema( 8 ) ] ),
        ];
    }

    /** Return internal callbacks; these are not part of the persisted fingerprint. */
    public static function callbacks(): array {
        return [
            'get-dashboard-schema'    => [ Read_Callbacks::class, 'get_dashboard_schema' ],
            'get-dashboard-overview'  => [ Read_Callbacks::class, 'get_dashboard_overview' ],
            'get-dashboard-series'    => [ Read_Callbacks::class, 'get_dashboard_series' ],
            'list-dashboard-activity' => [ Read_Callbacks::class, 'list_dashboard_activity' ],
            'get-dashboard-guidance'  => [ Read_Callbacks::class, 'get_dashboard_guidance' ],
        ];
    }

    /**
     * Enforce this feature's existing gates in their original policy order.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param string $phase Authorization phase around restrictive extension policies.
     */
    public static function authorize( string $tool, array $args, string $phase ): void {
        if ( 'before' === $phase ) {
            Read_Callbacks::permission( $tool, $args ); }
    }

    /**
     * Publish only the tools visible to the current account.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool {
        Read_Callbacks::permission( $tool );
        return true;
    }
}
