<?php
/** Registers integration assets only while HelpGent is active. */
namespace Directorist\Integrations\HelpGent;

defined( 'ABSPATH' ) || exit;

final class Assets {
    public static function register_hooks(): void {
        add_filter( 'directorist_scripts', [ self::class, 'scripts' ] );
        add_action( 'directorist_builder_edit_assets_enqueued', [ self::class, 'builder' ] );
    }

    public static function builder(): void {
        $file = 'css/helpgent-enquiry-settings.css';
        wp_enqueue_style( 'directorist-helpgent-enquiry-settings', DIRECTORIST_ASSETS . $file, [ 'directorist-admin-style' ], (string) filemtime( ATBDP_DIR . 'assets/' . $file ) );
    }

    public static function scripts( array $scripts ): array {
        $manifest = ATBDP_DIR . 'assets/build/js/public/helpgent-integration.asset.php';
        $asset = file_exists( $manifest ) ? require $manifest : [ 'dependencies' => [], 'version' => ATBDP_VERSION ];
        $dependencies = array_merge( $asset['dependencies'], [ 'wp-i18n', 'helpgent/response-answers' ] );
        if ( function_exists( 'helpgent_pro' ) ) {
            $dependencies[] = 'helpgent-pro/frontend';
        }
        $scripts['directorist-helpgent-dashboard'] = [
            'type' => 'js',
            'ext' => add_query_arg( 'build', $asset['version'], DIRECTORIST_BUILD_ASSETS . 'js/public/helpgent-integration.js' ),
            'dep' => array_values( array_unique( $dependencies ) ),
        ];
        foreach ( [ 'dashboard', 'listing' ] as $name ) {
            $file = 'css/public/helpgent-' . $name . ( is_rtl() ? '-rtl' : '' ) . '.css';
            $scripts['directorist-helpgent-' . $name . '-style'] = [
                'type' => 'css',
                'ext' => add_query_arg( 'build', file_exists( ATBDP_DIR . 'assets/build/' . $file ) ? (string) filemtime( ATBDP_DIR . 'assets/build/' . $file ) : ATBDP_VERSION, DIRECTORIST_BUILD_ASSETS . $file ),
                'dep' => 'dashboard' === $name ? [ 'helpgent/style' ] : [],
            ];
        }
        return $scripts;
    }

    public static function dashboard(): void {
        self::register();
        if ( function_exists( 'helpgent_pro' ) && ! wp_script_is( 'helpgent-pro/frontend', 'registered' ) ) {
            do_action( 'helpgent_after_enqueued_common_scripts' );
        }
        wp_enqueue_style( 'directorist-helpgent-dashboard-style' );
        wp_enqueue_script( 'directorist-helpgent-dashboard' );
        wp_set_script_translations( 'directorist-helpgent-dashboard', 'directorist', ATBDP_DIR . 'languages' );
    }

    public static function listing(): void {
        self::register();
        wp_enqueue_style( 'directorist-helpgent-listing-style' );
    }

    private static function register(): void {
        // Block templates can render before wp_enqueue_scripts registers the inventory.
        foreach ( self::scripts( [] ) as $handle => $script ) {
            $registered = 'css' === $script['type'] ? wp_style_is( $handle, 'registered' ) : wp_script_is( $handle, 'registered' );
            if ( ! $registered ) {
                \Directorist\Asset_Loader\Helper::register_single_script( $handle, $script );
            }
        }
    }
}
