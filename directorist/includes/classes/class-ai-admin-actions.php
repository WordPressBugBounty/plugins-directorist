<?php
/**
 * Locked Directorist AI actions on native Directorist screens.
 */

namespace Directorist;

defined( 'ABSPATH' ) || exit;

final class AI_Admin_Actions {
    private const PLUGIN_FILES = [
        'directorist-ai/directorist-ai.php',
        'directorist-ai-pro/directorist-ai-pro.php',
    ];
    private const PRODUCT_ID         = 372875;
    private const PURCHASE_URL       = 'https://directorist.com/directorist-ai/#dir-ai-offer';
    private const MANAGE_LICENSE_ARG = 'directorist_ai_manage_license';

    private static $destination;

    public static function register() {
        add_action( 'current_screen', [ __CLASS__, 'setup_screen' ] );
    }

    public static function setup_screen( $screen ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ATBDP_POST_TYPE . '_page_atbdp-extension' === $screen->id && self::is_license_request() ) {
            self::open_license_route();
            return;
        }

        $is_listings    = 'edit-' . ATBDP_POST_TYPE === $screen->id;
        $is_editor      = 'post' === $screen->base && ATBDP_POST_TYPE === $screen->post_type;
        $is_directories = in_array(
            $screen->id,
            [
                ATBDP_POST_TYPE . '_page_atbdp-directory-types',
                ATBDP_POST_TYPE . '_page_atbdp-layout-builder',
            ],
            true
        );

        if ( ( ! $is_listings && ! $is_editor && ! $is_directories ) || self::is_plugin_active() ) {
            return;
        }

        if ( $is_listings ) {
            add_filter( 'post_row_actions', [ __CLASS__, 'listing_row_actions' ], 10, 2 );

            $post_type = get_post_type_object( ATBDP_POST_TYPE );

            if ( $post_type && current_user_can( $post_type->cap->create_posts ) ) {
                add_action( 'admin_footer', [ __CLASS__, 'render_listing_buttons' ] );
            }
        }

        if ( $is_editor ) {
            add_action( 'edit_form_top', [ __CLASS__, 'render_listing_editor_buttons' ] );
        }

        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function enqueue_assets() {
        wp_enqueue_style( 'directorist-ai-admin-actions', DIRECTORIST_ASSETS . 'css/directorist-ai-admin-actions.css', [], ATBDP_VERSION );
        self::enqueue_script();
    }

    public static function enqueue_script() {
        wp_enqueue_script( 'directorist-ai-admin-actions', DIRECTORIST_ASSETS . 'js/directorist-ai-admin-actions.js', [], ATBDP_VERSION, true );
    }

    /**
     * Provide the existing lock action to independently loaded editor integrations.
     *
     * @param int|null $post_id Document being edited, or null for the Site Editor.
     * @param string   $label   Native launcher label.
     * @param string   $class   Integration-specific button class.
     * @return string Escaped lock markup, or an empty string.
     */
    public static function get_editor_lock( $post_id, $label, $class = '' ): string {
        if ( ! current_user_can( 'manage_options' ) || self::is_plugin_active() ) {
            return '';
        }

        $can_edit = null === $post_id ? current_user_can( 'edit_theme_options' ) : $post_id && current_user_can( 'edit_post', $post_id );
        if ( ! $can_edit ) {
            return '';
        }

        $destination = self::get_destination();
        if ( ! $destination ) {
            return '';
        }

        self::enqueue_assets();

        return self::get_button( $label, $destination, $class );
    }

    public static function render_listing_buttons() {
        $destination = self::get_destination();

        if ( ! $destination ) {
            return;
        }
        ?>
        <template id="directorist-ai-listing-actions">
            <?php self::render_button( __( 'Generate listing with AI', 'directorist' ), $destination, 'page-title-action' ); ?>
            <?php self::render_button( __( 'Import listings with AI', 'directorist' ), $destination, 'page-title-action' ); ?>
        </template>
        <?php
    }

    public static function render_directory_button() {
        if ( ! current_user_can( 'manage_options' ) || self::is_plugin_active() ) {
            return;
        }

        $destination = self::get_destination();

        if ( $destination ) {
            self::render_button( __( 'Create directory with AI', 'directorist' ), $destination, 'directorist-ai-admin-action--directory' );
        }
    }

    public static function listing_row_actions( $actions, $post ) {
        if ( ATBDP_POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }

        $destination = self::get_destination();

        if ( $destination ) {
            $actions['directorist_ai'] = self::get_button( __( 'Improve with AI', 'directorist' ), $destination, 'directorist-ai-admin-action--row' );
        }

        return $actions;
    }

    public static function render_listing_editor_buttons( $post ) {
        $post_type = get_post_type_object( ATBDP_POST_TYPE );
        $is_new    = 'auto-draft' === $post->post_status;

        if ( ! $post_type || ( $is_new ? ! current_user_can( $post_type->cap->create_posts ) : ! self::can_edit_listing( $post ) ) ) {
            return;
        }

        $destination = self::get_destination();

        if ( ! $destination ) {
            return;
        }

        self::render_editor_templates( $is_new, $destination );
    }

    public static function render_frontend_listing_buttons( $listing_id ) {
        // Older theme overrides may still call this method.
    }

    public static function render_owner_dashboard_button( $listing_id ) {
        // Older theme overrides may still call this method.
    }

    private static function can_edit_listing( $post ) {
        return $post && ATBDP_POST_TYPE === $post->post_type && current_user_can( 'edit_post', $post->ID )
            && ( current_user_can( 'manage_options' ) || get_current_user_id() === (int) $post->post_author );
    }

    private static function render_editor_templates( $is_new, $destination ) {
        $label = $is_new ? __( 'Generate listing with AI', 'directorist' ) : __( 'Improve listing with AI', 'directorist' );
        ?>
        <template id="directorist-ai-listing-editor-action">
            <?php self::render_button( $label, $destination, 'page-title-action' ); ?>
        </template>
        <template id="directorist-ai-field-generate"
            data-preview-label="<?php esc_attr_e( 'Preview image / thumbnail', 'directorist' ); ?>"
            data-gallery-label="<?php esc_attr_e( 'Gallery', 'directorist' ); ?>">
            <?php self::render_button( __( 'Generate with AI', 'directorist' ), $destination, 'directorist-ai-admin-action--field' ); ?>
        </template>
        <template id="directorist-ai-field-edit">
            <?php self::render_button( __( 'Edit with AI', 'directorist' ), $destination, 'directorist-ai-admin-action--field' ); ?>
        </template>
        <template id="directorist-ai-choose-terms">
            <?php self::render_button( __( 'Choose with AI', 'directorist' ), $destination, 'directorist-ai-admin-action--field' ); ?>
        </template>
        <template id="directorist-ai-media-replace">
            <?php self::render_button( __( 'Replace with AI', 'directorist' ), $destination, 'directorist-ai-admin-action--field' ); ?>
        </template>
        <?php
    }

    public static function render_directory_builder_button( $directory_id ) {
        if ( ! current_user_can( 'manage_options' ) || self::is_plugin_active() ) {
            return;
        }

        $destination = self::get_destination();

        if ( ! $destination ) {
            return;
        }

        $label = $directory_id
            ? __( 'Edit directory with AI', 'directorist' )
            : __( 'Create directory with AI', 'directorist' );
        ?>
        <template id="directorist-ai-directory-builder-action">
            <?php self::render_button( $label, $destination, 'directorist-ai-admin-action--directory' ); ?>
        </template>
        <?php
    }

    private static function render_button( $label, $destination, $class ) {
        echo self::get_button( $label, $destination, $class ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_button().
    }

    private static function get_button( $label, $destination, $class ) {
        $tooltip_id = wp_unique_id( 'directorist-ai-admin-tooltip-' );
        $attributes = ' class="directorist-ai-admin-action ' . esc_attr( $class ) . '" aria-describedby="' . esc_attr( $tooltip_id ) . '"';
        $content = sprintf(
            '<span class="directorist-ai-admin-action__icon" aria-hidden="true"></span><span class="directorist-ai-admin-action__label">%s</span><span id="%s" class="directorist-ai-admin-action__tooltip" role="tooltip" aria-hidden="true">%s</span>',
            esc_html( $label ),
            esc_attr( $tooltip_id ),
            esc_html( $destination['label'] )
        );

        $new_tab = self::PURCHASE_URL === $destination['url'] ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . esc_url( $destination['url'] ) . '"' . $attributes . $new_tab . '>' . $content . '</a>';
    }

    private static function is_plugin_active() {
        if ( defined( 'DIRECTORIST_AI_FILE' ) || defined( 'DIRECTORIST_AI_PRO_FILE' ) ) {
            return true;
        }

        foreach ( self::PLUGIN_FILES as $file ) {
            if ( directorist_is_plugin_active( $file ) ) {
                return true;
            }
        }

        return false;
    }

    private static function get_installed_plugin() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ( self::PLUGIN_FILES as $file ) {
            if ( isset( $plugins[ $file ] ) ) {
                return $file;
            }
        }

        return '';
    }

    private static function get_destination() {
        if ( null === self::$destination ) {
            self::$destination = self::resolve_destination();
        }

        return self::$destination;
    }

    private static function resolve_destination() {
        $file = self::get_installed_plugin();

        if ( $file ) {
            if ( ! current_user_can( 'activate_plugin', $file ) ) {
                return [];
            }

            return [
                'url'   => add_query_arg( 's', 'Directorist+AI', admin_url( 'plugins.php' ) ),
                'label' => __( 'Activate Directorist AI', 'directorist' ),
            ];
        }

        if ( ! Extension_Account::has_session() ) {
            return self::purchase_destination();
        }

        $license_destination = [
            'url'   => self::manage_license_url(),
            'label' => __( 'Check your AI Pro license', 'directorist' ),
        ];
        $products            = Extension_Account::get_purchased_extensions();

        if ( ! is_array( $products ) ) {
            return $license_destination;
        }

        foreach ( $products as $key => $product ) {
            if ( empty( $product['item_id'] ) || self::PRODUCT_ID !== absint( $product['item_id'] ) ) {
                continue;
            }

            if ( empty( $product['license'] ) ) {
                return $license_destination;
            }

            if ( ! current_user_can( 'install_plugins' ) ) {
                return [];
            }

            // The Add-ons row requires the main download link, including in beta mode.
            if ( empty( $product['download_link'] ) ) {
                return $license_destination;
            }

            $package = ATBDP()->beta ? ( $product['beta_link'] ?? '' ) : $product['download_link'];
            $url     = is_string( $package ) ? wp_parse_url( $package ) : [];

            if ( 'directorist.com' !== ( $url['host'] ?? '' ) || 'https' !== ( $url['scheme'] ?? '' ) ) {
                return $license_destination;
            }

            return [
                'url'   => self::dashboard_url( [ 'te_view' => 'addons' ] ) . '#directorist-te-row-' . sanitize_html_class( 'extension-subscription-' . $key ),
                'label' => __( 'Install Directorist AI', 'directorist' ),
            ];
        }

        return self::purchase_destination();
    }

    private static function purchase_destination() {
        return [
            'url'   => self::PURCHASE_URL,
            'label' => __( 'View AI Pro plans · Opens in a new tab', 'directorist' ),
        ];
    }

    private static function dashboard_url( $args ) {
        return add_query_arg( array_merge( [ 'post_type' => ATBDP_POST_TYPE, 'page' => 'atbdp-extension' ], $args ), admin_url( 'edit.php' ) );
    }

    private static function manage_license_url() {
        return self::dashboard_url( [ self::MANAGE_LICENSE_ARG => 1 ] );
    }

    private static function is_license_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only destination.
        return isset( $_GET[ self::MANAGE_LICENSE_ARG ] ) && '1' === $_GET[ self::MANAGE_LICENSE_ARG ];
    }

    private static function open_license_route() {
        if ( self::is_plugin_active() || ! Extension_Account::has_session() ) {
            return;
        }

        // The existing Refresh purchases form reloads this page after a successful response.
        $destination = self::get_destination();
        if ( ! $destination ) {
            return;
        }

        if ( self::PURCHASE_URL === $destination['url'] ) {
            wp_redirect( self::PURCHASE_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed official offer.
            exit;
        }

        if ( self::manage_license_url() !== $destination['url'] ) {
            wp_safe_redirect( $destination['url'] );
            exit;
        }

        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_script' ] );
    }
}
