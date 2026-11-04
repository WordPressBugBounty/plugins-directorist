<?php
/**
 * Format bounded Site Health reports after safe collection.
 *
 * @package Directorist\MCP
 */
namespace Directorist\MCP\Tool_Providers\Site_Health;

use Directorist\MCP\Support\Read_Support;

defined( 'ABSPATH' ) || exit;

/** Text and JSON share the same sanitized report payload. */
class Report_Formatter {
    /**
     * Format collected sections without initiating any collectors.
     *
     * @param array $base Base.
     * @param array $report Report.
     * @param array $selected Selected.
     * @param array $args Validated tool input.
     */
    public static function format( array $base, array $report, array $selected, array $args ): array {
        $payload = $base + [ 'sections' => $report,
            'coverage'                  => [ 'included' => $selected,
                'complete'                              => ! array_filter(
                    $report, static function ( $section ) {
                        if ( 'unavailable' === $section['state'] ) {
                            return true;
                        }
                        foreach ( $section['items'] as $item ) {
                            if ( isset( $item['state'] ) ? ! in_array( $item['state'], [ 'available', 'empty' ], true ) : in_array( null, $item, true ) ) {
                                return true;
                            }
                        }
                        return false;
                    }
                ),
                'omissions'                             => [ 'private_and_unapproved_fields_are_excluded' ]
            ]
        ];
        Read_Support::bounded( $payload, 262144 );
        if ( 'json' === ( $args['format'] ?? 'text' ) ) {
            return $payload;
        }
        $lines = [ 'Directorist Site Health Info', 'Generated: ' . $base['generated_at'], 'Redaction policy: ' . Read_Callbacks::POLICY_VERSION, 'Sources: ' . wp_json_encode( $base['source_versions'] ), 'Scope: ' . $base['scope'], 'Coverage complete: ' . ( $payload['coverage']['complete'] ? 'yes' : 'no' ) ];
        foreach ( $report as $section ) {
            $lines[] = "\n[" . $section['id'] . '] state=' . $section['state'] . ' as_of=' . $section['as_of'];
            foreach ( $section['items'] as $item ) {
                $lines[] = wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            }
            if ( $section['reason'] ) {
                $lines[] = 'Reason: ' . $section['reason'];
            }
        }
        return Read_Support::bounded( $base + [ 'format' => 'text', 'content' => implode( "\n", $lines ), 'coverage' => $payload['coverage'] ], 262144 );
    }
}
