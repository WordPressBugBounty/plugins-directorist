<?php
/**
 * Isolate registration failures and enforce the sealed registration window.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Registration\Registry_State;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Isolate registration failures and enforce the sealed registration window. */
class Registration_Api {
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
     * Register.
     *
     * @param callable $callback Operation to invoke within the guarded context.
     * @param mixed $owner Owner.
     * @return mixed Registration callback result, or WP_Error when registration fails.
     */
    public function register( callable $callback, $owner ) {
        try {
            Validation::check( ! $this->state->sealed, __( 'MCP registration has closed.', 'directorist' ), 'registration_failed' );
            return $callback();
        } catch ( \Throwable $error ) {
            $this->state->errors[] = [ 'owner' => is_string( $owner ) ? sanitize_key( $owner ) : '', 'code' => $error instanceof Failure ? $error->reason : 'registration_failed' ];
            return new \WP_Error( 'directorist_mcp_registration_failed', __( 'Invalid MCP registration. Check the published contract.', 'directorist' ) );
        }
    }
}
