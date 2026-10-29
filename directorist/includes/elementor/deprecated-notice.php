<?php
/**
 * Elementor integration announcement.
 *
 * @package Directorist
 */

namespace AazzTech\Directorist\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DeprecatedNotice {
    const DISMISSED_META_KEY = 'directorist_elementor_integration_notice_dismissed';
    const DISMISS_ACTION     = 'directorist_dismiss_elementor_integration_notice';

    /**
     * Persist dismissal for the current administrator.
     */
    public function maybe_dismiss_notice() {
        if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        check_admin_referer( self::DISMISS_ACTION );
        update_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, 1 );

        wp_safe_redirect( remove_query_arg( [ self::DISMISS_ACTION, '_wpnonce' ] ) );
        exit;
    }

    /**
     * Show available integrations, including the paid option for free-addon users.
     */
    public function maybe_show_notice_for_required_plugins() {
        global $pagenow;

        if ( 'update.php' === $pagenow || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        if ( get_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, true ) ) {
            return;
        }

        $pro_slug = 'directorist-elementor/directorist-elementor.php';

        // This also detects network-active installations.
        if ( is_plugin_active( $pro_slug ) ) {
            return;
        }

        $free_slug      = 'addonskit-for-elementor/addonskit-for-elementor.php';
        $free_active    = is_plugin_active( $free_slug );
        $free_installed = is_file( WP_PLUGIN_DIR . '/' . $free_slug );
        $pro_installed  = is_file( WP_PLUGIN_DIR . '/' . $pro_slug );
        $dismiss_url    = wp_nonce_url( add_query_arg( self::DISMISS_ACTION, '1' ), self::DISMISS_ACTION );
        ?>
        <div class="notice notice-info directorist-elementor-integration-notice" style="position: relative; padding-right: 38px;">
            <p><strong>
                <?php if ( $pro_installed ) : ?>
                    <?php esc_html_e( 'Build your directory with Elementor', 'directorist' ); ?>
                <?php elseif ( $free_installed ) : ?>
                    <?php esc_html_e( 'Ready for more design flexibility with Directorist?', 'directorist' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Choose how you want to build with Directorist', 'directorist' ); ?>
                <?php endif; ?>
            </strong></p>
            <p>
                <?php if ( $pro_installed ) : ?>
                    <?php esc_html_e( 'Directorist Elementor Integration is installed but inactive. Activate it to use custom listing layouts, individual listing fields, and dynamic templates.', 'directorist' ); ?>
                <?php elseif ( $free_installed ) : ?>
                    <?php esc_html_e( 'Go beyond ready-made widgets with custom listing layouts, fields, and dynamic templates.', 'directorist' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Use free, ready-made Elementor widgets or create fully customized listing layouts with Elementor Integration.', 'directorist' ); ?>
                <?php endif; ?>
            </p>
            <p>
                <?php if ( ! $free_active ) : ?>
                    <?php if ( $free_installed ) : ?>
                        <?php if ( current_user_can( 'activate_plugin', $free_slug ) ) : ?>
                            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'activate', 'plugin' => $free_slug ], self_admin_url( 'plugins.php' ) ), 'activate-plugin_' . $free_slug ) ); ?>"><?php esc_html_e( 'Activate Free AddonsKit', 'directorist' ); ?></a>
                        <?php else : ?>
                            <a class="button button-secondary" href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Manage Free AddonsKit', 'directorist' ); ?></a>
                        <?php endif; ?>
                    <?php elseif ( current_user_can( 'install_plugins' ) && is_php_version_compatible( '7.4' ) && is_wp_version_compatible( '6.0' ) ) : ?>
                        <a class="button button-secondary" href="<?php echo esc_url( $this->get_plugin_installation_url( $free_slug ) ); ?>"><?php esc_html_e( 'Get Free AddonKit', 'directorist' ); ?></a>
                    <?php else : ?>
                        <a class="button button-secondary" href="<?php echo esc_url( 'https://wordpress.org/plugins/addonskit-for-elementor/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Free AddonKit', 'directorist' ); ?></a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ( $pro_installed ) : ?>
                    <?php if ( current_user_can( 'activate_plugin', $pro_slug ) ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'activate', 'plugin' => $pro_slug ], self_admin_url( 'plugins.php' ) ), 'activate-plugin_' . $pro_slug ) ); ?>"><?php esc_html_e( 'Activate Elementor Integration', 'directorist' ); ?></a>
                    <?php else : ?>
                        <a class="button button-primary" href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Manage Elementor Integration', 'directorist' ); ?></a>
                    <?php endif; ?>
                <?php else : ?>
                    <a class="button button-primary" href="<?php echo esc_url( 'https://directorist.com/product/directorist-elementor/' ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php if ( $free_installed ) : ?>
                            <?php esc_html_e( 'Explore Elementor Integration', 'directorist' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Create Custom Layouts', 'directorist' ); ?>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            </p>
            <a class="notice-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>" style="text-decoration: none;"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'directorist' ); ?></span></a>
        </div>
        <?php
    }

    /**
     * Get WordPress's nonce-protected installation URL for the free addon.
     *
     * @param string $path Plugin basename.
     * @return string
     */
    public function get_plugin_installation_url( $path ): string {
        $slug = preg_replace( '/\/.+$/', '', $path );

        return wp_nonce_url( self_admin_url( "update.php?action=install-plugin&plugin={$slug}" ), 'install-plugin_' . $slug );
    }
}
