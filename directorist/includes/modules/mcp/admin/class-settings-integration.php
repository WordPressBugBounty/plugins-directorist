<?php
/**
 * Integrate the MCP field with native settings filters and persistence.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Admin;

use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Safety\Write_Guard;
use Directorist\MCP\Safety\Write_Lock;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Integrate the MCP field with native settings filters and persistence. */
class Settings_Integration {
    /** Attach the existing field, layout and persistence hooks. */
    public static function register_hooks(): void {
        add_filter( 'atbdp_listing_type_settings_field_list', [ self::class, 'fields' ], 100 );
        add_filter( 'atbdp_listing_type_settings_layout', [ self::class, 'layout' ], 100 );
        add_filter( 'pre_update_option_atbdp_option', [ self::class, 'option_change' ], 10, 2 );
        add_action( 'wp_ajax_save_settings_data', [ self::class, 'before_settings_save' ], 0 );
    }

    /**
     * Fields.
     *
     * @param array $fields Fields.
     */
    public static function fields( array $fields ): array {
        $fields['mcp_enabled'] = [
            'type'        => 'mcp-configuration',
            'label'       => __( 'MCP for AI Agents', 'directorist' ),
            'description' => __( 'Connect an AI assistant to Directorist using your WordPress account and permissions.', 'directorist' ),
            'value'       => false,
            'instantSave' => true
        ];
        return $fields;
    }

    /**
     * Layout.
     *
     * @param array $layouts Layouts.
     */
    public static function layout( array $layouts ): array {
        $layouts['other_features'] = [
            'label'   => __( 'Other Features', 'directorist' ),
            'icon'    => 'las la-puzzle-piece',
            'submenu' => [ 'mcp' => [ 'label' => __( 'MCP', 'directorist' ), 'sections' => [ 'mcp_connection' => [ 'title' => '', 'fields' => [ 'mcp_enabled' ] ] ] ] ]
        ];
        return $layouts;
    }

    /** Before settings save. */
    public static function before_settings_save(): void {
        if ( ! current_user_can( 'manage_options' ) || ! directorist_verify_nonce() ) {
            return;
            // Existing handler provides its established error response.
        }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decode JSON intact; the exact registered key is checked below and the native handler validates the field allowlist.
        $keys = isset( $_POST['field_list'] ) ? json_decode( wp_unslash( $_POST['field_list'] ), true ) : [];
        if ( in_array( 'mcp_enabled', (array) $keys, true ) ) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict boolean validation below rejects any non-boolean JSON value.
            $value = json_decode( wp_unslash( $_POST['mcp_enabled'] ?? 'false' ), true );
            if ( is_array( $value ) && count( $value ) === 1 ) {
                $value = reset( $value );
            }
            if ( ! is_bool( $value ) || ( $value && ! Request_Context::dependencies() ) ) {
                wp_send_json( [ 'status' => [ 'success' => false, 'status_log' => [ 'type' => 'error', 'message' => __( 'A compatible WordPress Abilities API and MCP Adapter are required before enabling MCP.', 'directorist' ) ] ] ] );
            }
            $_POST['mcp_enabled'] = $value ? 'true' : 'false';
        }
        Write_Lock::native_lock();
    }

    /**
     * Option change.
     *
     * @param mixed $value Value.
     * @param mixed $old Old.
     */
    public static function option_change( $value, $old ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( array_key_exists( 'mcp_enabled', $value ) ) {
            $value['mcp_enabled'] = in_array( $value['mcp_enabled'], [ true, 1, '1', 'true' ], true );
            if ( $value['mcp_enabled'] !== ( $old['mcp_enabled'] ?? false ) ) {
                Validation::check( ! Write_Guard::is_mutating(), __( 'MCP tools cannot change MCP access policy.', 'directorist' ), 'sensitive_field' );
                update_option( 'directorist_mcp_epoch', wp_generate_uuid4(), false );
                if ( $value['mcp_enabled'] && ! wp_next_scheduled( 'directorist_mcp_cleanup' ) ) {
                    wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'directorist_mcp_cleanup' );
                }
            }
        }
        return $value;
    }
}
