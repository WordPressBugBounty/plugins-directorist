<?php
/**
 * Apply extension availability, admission and restrictive authorization policies.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Extensions;

use Directorist\MCP\Registration\Registry_State;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Tool_Providers\Dashboard\Read_Callbacks;
use Directorist\MCP\Tool_Providers\Orders\Order_Policy;
use Directorist\MCP\Tool_Providers\Site_Health\Section_Catalog;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Safety\Read_Guard;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Apply extension availability, admission and restrictive authorization policies. */
class Extension_Lifecycle {
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
     * Read adapters.
     *
     * @param string $domain Domain.
     * @param array $args Validated tool input.
     */
    public function read_adapters( string $domain, array $args ): array {
        $out = [];
        foreach ( $this->state->read_adapters[ $domain ] ?? [] as $key => $definition ) {
            try {
                $scope = $args['scope'] ?? 'site';
                if ( $this->admitted( $definition['owner'] ) && in_array( $scope, $definition['scopes'], true ) && true === Callback_Invoker::extension_callback( $definition['permission'], $args, get_current_user_id() ) ) {
                    $out[ $key ] = $definition;
                }
            } catch ( \Throwable $error ) {
                continue;
            }
        }
        return $out;
    }

    /**
     * Active.
     *
     * @param string $owner Owner.
     */
    public function active( string $owner ): bool {
        if ( ! isset( $this->state->extensions[ $owner ] ) ) {
            return false;
        }
        $definition = $this->state->extensions[ $owner ];
        $active     = in_array( $definition['plugin'], (array) get_option( 'active_plugins', [] ), true ) || array_key_exists( $definition['plugin'], (array) get_site_option( 'active_sitewide_plugins', [] ) );
        foreach ( $definition['requires'] ?? [] as $plugin ) {
            $active = $active && ( in_array( $plugin, (array) get_option( 'active_plugins', [] ), true ) || array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }
        try {
            return $active && true === Callback_Invoker::extension_callback( $definition['availability'] );
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    /**
     * Admitted.
     *
     * @param string $owner Owner.
     */
    public function admitted( string $owner ): bool {
        if ( ! $this->active( $owner ) ) {
            return false;
        }
        $definition = $this->state->extensions[ $owner ];
        try {
            return current_user_can( $definition['capability'] ) && true === Callback_Invoker::extension_callback( $definition['admission'], get_current_user_id() );
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    /** Admission. */
    public function admission(): bool {
        foreach ( array_keys( $this->state->extensions ) as $owner ) {
            if ( $this->admitted( $owner ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fields.
     *
     * @param string $domain Domain.
     * @param mixed $context Context.
     */
    public function fields( string $domain, $context = null ): array {
        $out = [];
        foreach ( $this->state->fields[ $domain ] ?? [] as $key => $definition ) {
            try {
                if ( $this->admitted( $definition['owner'] ) && true === Callback_Invoker::extension_callback( $definition['permission'], $context, 'read' ) ) {
                    $out[ $key ] = $definition;
                }
            } catch ( \Throwable $error ) {
                continue;
            }
        }
        return $out;
    }

    /**
     * Permission.
     *
     * @param string $name Registered action or object name.
     * @param array $args Validated tool input.
     * @param bool $discover Discover.
     */
    public function permission( string $name, array $args, bool $discover = false ): bool {
        $definition = $this->state->tools[ $name ];
        if ( isset( $definition['owner'] ) ) {
            Validation::check( $this->admitted( $definition['owner'] ), __( 'This extension is unavailable. Rediscover tools.', 'directorist' ), 'extension_unavailable' );
            if ( isset( $definition['bulk_of'] ) ) {
                Validation::check( true === Callback_Invoker::extension_callback( $definition['discover'], [], get_current_user_id() ), __( 'This batch is not permitted.', 'directorist' ), 'forbidden' );
            }
            $domain = isset( $definition['bulk_of'] ) ? 'extension' : $definition['domain'];
            if ( in_array( $domain, [ 'settings', 'directories' ], true ) ) {
                Access_Control::admin();
            } elseif ( 'listings' === $domain ) {
                Access_Control::listing_domain();
                if ( isset( $args['listing_id'] ) ) {
                    Access_Control::listing( $args['listing_id'], $definition['write'] ? 'edit' : 'read' );
                }
            } elseif ( 'taxonomies' === $domain ) {
                Validation::check( isset( $args['taxonomy'] ) || $discover, __( 'A taxonomy is required.', 'directorist' ) );
                if ( isset( $args['taxonomy'] ) ) {
                    Access_Control::term_cap( $args['taxonomy'], $definition['write'] ? 'edit' : 'read' );
                }
            } elseif ( 'orders' === $domain ) {
                Order_Policy::permission( $definition['write'] ? 'extension-order-write' : 'get-order', $args );
            } elseif ( 'dashboard' === $domain ) {
                Read_Callbacks::permission( $name, $args );
                Read_Contributions::object_permission( $args );
            } elseif ( 'site_health_info' === $domain ) {
                Section_Catalog::permission();
            }
            Validation::check( true === Callback_Invoker::extension_callback( $definition[ $discover || isset( $definition['bulk_of'] ) ? 'discover' : 'permission' ], $args, get_current_user_id() ), __( 'This MCP operation is not permitted.', 'directorist' ), 'forbidden' );
        }
        foreach ( $this->state->policies as $policy ) {
            if ( empty( $policy['tools'] ) || in_array( $name, $policy['tools'], true ) ) {
                Validation::check( $this->active( $policy['owner'] ), __( 'A required policy integration is unavailable.', 'directorist' ), 'extension_unavailable' );
                Validation::check( true === Callback_Invoker::extension_callback( $policy['check'], $name, $args, $discover ), __( 'An extension policy restricts this operation.', 'directorist' ), 'forbidden' );
            }
        }
        return true;
    }

    /**
     * Available.
     *
     * @param string $name Registered action or object name.
     */
    public function available( string $name ): bool {
        try {
            return Read_Guard::run(
                function () use ( $name ) { return $this->permission( $name, [], true );
                }, false, in_array( $this->state->tools[ $name ]['domain'] ?? '', [ 'dashboard', 'site_health_info' ], true ) || in_array( $this->state->tools[ $name ]['group'] ?? '', [ 'dashboard', 'site-health-info' ], true )
            );
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    /** Lifecycle. */
    public static function lifecycle(): void {
        update_option( 'directorist_mcp_extensions_epoch', wp_generate_uuid4(), false );
        update_site_option( 'directorist_mcp_extensions_epoch', wp_generate_uuid4() );
    }
}
