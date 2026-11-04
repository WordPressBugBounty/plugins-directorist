<?php
/**
 * Read permitted integration metadata and administrator-only diagnostics.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Registration\Registry_State;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Tool_Providers\Site_Health\Section_Catalog;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Pagination;

defined( 'ABSPATH' ) || exit;

/** Read permitted integration metadata and administrator-only diagnostics. */
class Integration_Callbacks {
    const API_VERSION          = 1;
    const READ_ADAPTER_VERSION = 1;

    /** @var \Directorist\MCP\Registration\Registry_State Shared registration state. */
    private Registry_State $state;

    /** @var \Directorist\MCP\Registration\Tool_Registry Registry coordinator. */
    private Tool_Registry $registry;

    /**
     * Bind request-local registration collaborators.
     *
     * @param Registry_State $state Shared request-local registration state.
     * @param Tool_Registry $registry Common tool registry.
     */
    public function __construct( Registry_State $state, Tool_Registry $registry ) {
        $this->state    = $state;
        $this->registry = $registry;
    }

    /**
     * Integrations.
     *
     * @param array $args Validated tool input.
     */
    public function integrations( array $args ): array {
        if ( isset( $args['operation_id'] ) ) {
            return External_Operations::read_any( $args );
        }
        if ( ! empty( $args['diagnostics'] ) ) {
            Access_Control::admin();
        }
        $items = [];
        foreach ( $this->state->extensions as $owner => $definition ) {
            $tools = [];
            foreach ( $this->state->tools as $name => $tool ) {
                if ( ( $tool['owner'] ?? '' ) === $owner && $this->registry->available( $name ) ) {
                    $tools[] = 'directorist-' . $name;
                }
            }
            $has_fields = false;
            foreach ( [ 'dashboard', 'site_health_info' ] as $domain ) {
                foreach ( $this->registry->read_adapters( $domain, [ 'scope' => current_user_can( 'manage_options' ) ? 'site' : 'own' ] ) as $adapter ) {
                    if ( 'site_health_info' === $domain && ! Section_Catalog::available() ) {
                        continue;
                    }
                    $has_fields = $has_fields || $adapter['owner'] === $owner;
                }
            }
            foreach ( $this->state->fields as $domain => $fields ) {
                foreach ( $this->registry->fields( $domain ) as $field ) {
                    $has_fields = $has_fields || $field['owner'] === $owner;
                }
            }
            if ( ! $tools && ! $has_fields && empty( $args['diagnostics'] ) ) {
                continue;
            }
            if ( ! empty( $args['search'] ) && false === stripos( $definition['label'], $args['search'] ) && false === stripos( $owner, $args['search'] ) ) {
                continue;
            }
            if ( isset( $args['group'] ) && ! array_filter(
                $this->state->tools, static function ( $tool ) use ( $owner, $args ) { return ( $tool['owner'] ?? '' ) === $owner && $tool['group'] === $args['group'];
                }
            ) ) {
                continue;
            }
            $items[] = [ 'owner' => $owner, 'label' => sanitize_text_field( $definition['label'] ), 'version' => $definition['version'], 'tools' => $tools ] + ( ! empty( $args['diagnostics'] ) ? [ 'available' => $this->registry->active( $owner ), 'reason' => $this->registry->active( $owner ) ? '' : 'dependency_unavailable' ] : [] );
        }
        $result = Pagination::page( $items, $args );
        if ( ! empty( $args['diagnostics'] ) ) {
            $result['registration_errors'] = $this->state->errors;
        }
        return $result;
    }
}
