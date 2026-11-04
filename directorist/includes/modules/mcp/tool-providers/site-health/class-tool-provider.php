<?php
/**
 * Tool Provider for site health.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Site_Health;

use Directorist\MCP\Contracts\Tool_Provider as Contracts_Tool_Provider;
use Directorist\MCP\Support\Read_Support;

defined( 'ABSPATH' ) || exit;

/** Tool Provider. */
class Tool_Provider implements Contracts_Tool_Provider {
    const POLICY_VERSION = Section_Catalog::POLICY_VERSION;

    /** Definitions. */
    public static function definitions(): array {
        return [
            'discover-site-health-info' => Read_Support::definition( 'site-health-info', 'Discover approved Site Health Info sections and fields without collecting their values.', [ 'section_id' => Read_Support::string_schema() ] + Read_Support::paging_schema() ),
            'get-site-health-info'      => Read_Support::definition( 'site-health-info', 'Read one approved local diagnostic section with strict privacy and current runtime identity.', [ 'section_id' => Read_Support::string_schema(), 'field_keys' => Read_Support::list_schema( 100 ) ] + Read_Support::paging_schema(), [ 'section_id' ] ),
            'get-site-health-report'    => Read_Support::definition( 'site-health-info', 'Return a redacted text or JSON support report without saving or transmitting it.', [ 'section_ids' => Read_Support::list_schema( 10 ), 'format' => Read_Support::enum_schema( [ 'json', 'text' ] ) ] ),
        ];
    }

    /** Return internal callbacks; these are not part of the persisted fingerprint. */
    public static function callbacks(): array {
        return [
            'discover-site-health-info' => [ Read_Callbacks::class, 'discover_site_health_info' ],
            'get-site-health-info'      => [ Read_Callbacks::class, 'get_site_health_info' ],
            'get-site-health-report'    => [ Read_Callbacks::class, 'get_site_health_report' ],
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
            Section_Catalog::permission(); }
    }

    /**
     * Publish only the tools visible to the current account.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool {
        return Section_Catalog::available();
    }
}
