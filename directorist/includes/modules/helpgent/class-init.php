<?php
/** Optional HelpGent integration module. */
namespace Directorist\Integrations\HelpGent;

defined( 'ABSPATH' ) || exit;

final class Init {
    private const MIN_HELPGENT_VERSION = '3.1.5';

    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted || ! function_exists( 'helpgent' ) || self::standalone_loaded() ) {
            return;
        }
        self::$booted = true;
        if ( function_exists( 'helpgent_version' ) && version_compare( (string) helpgent_version(), self::MIN_HELPGENT_VERSION, '<' ) ) {
            add_action( 'admin_notices', [ self::class, 'render_update_notice' ] );
            add_action( 'network_admin_notices', [ self::class, 'render_update_notice' ] );
        }
        foreach ( [ 'enquiry-settings', 'database', 'conversations', 'answers', 'assets', 'integration', 'rest-controller' ] as $file ) {
            require_once __DIR__ . '/class-' . $file . '.php';
        }
        Integration::instance()->boot();
    }

    public static function render_update_notice(): void {
        $screen = get_current_screen();
        if ( ! current_user_can( 'update_plugins' ) || ! $screen || ( 'plugins' !== $screen->base && ATBDP_POST_TYPE !== $screen->post_type ) ) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <?php
                /* translators: %s: Minimum supported HelpGent plugin version. */
                printf( esc_html__( 'Update HelpGent to %s or later for compatibility with Directorist listing enquiries.', 'directorist' ), esc_html( self::MIN_HELPGENT_VERSION ) );
                ?>
                <a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Manage plugins', 'directorist' ); ?></a>
            </p>
        </div>
        <?php
    }

    private static function standalone_loaded(): bool {
        return class_exists( 'Directorist_HelpGent_Plugin', false )
            || class_exists( 'DirectoristHelpGentIntegration', false );
    }
}
