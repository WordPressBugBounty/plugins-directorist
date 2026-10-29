<?php

if ( ! class_exists( 'ATBDP_Rewrite' ) ) :

    /**
     * Class ATBDP_Rewrite
     * It handle custom rewrite rules and actions etc.
     */
    class ATBDP_Rewrite {
        protected $pages = [];

        public function __construct() {
            // add the rewrite rules to the init hook
            add_action( 'init', [ $this, 'add_write_rules' ] );

            $flush_rewrite_rules_on_demand = apply_filters( 'directorist_flush_rewrite_rules_on_demand', false );

            if ( $flush_rewrite_rules_on_demand ) {
                add_action( 'wp_loaded', [ $this, 'flush_rewrite_rules_on_demand' ] );
            }
            add_action( 'directorist_setup_wizard_page_created', [ $this, 'flush_rewrite_rules_on_demand' ] );
            add_action( 'directorist_setup_wizard_payment_page_created', [ $this, 'flush_rewrite_rules_on_demand' ] );
            add_action( 'directorist_setup_wizard_completed', [ $this, 'flush_rewrite_rules_on_demand' ] );
            add_action( 'directorist_options_updated', [ $this, 'flush_rewrite_rules_on_demand' ] );
        }

        protected function get_pages() {
            if ( ! empty( $this->pages ) ) {
                return $this->pages;
            }

            $pages = [
                'all_listing_page',
                'author_profile_page',
                'checkout_page',
                'payment_receipt_page',
                'add_listing_page',
                'single_category_page',
                'single_location_page',
                'single_tag_page',
            ];

            foreach ( $pages as $page_option_key ) {
                $this->pages[ $page_option_key ] = (int) get_directorist_option( $page_option_key );
            }

            return $this->pages;
        }

        protected function get_page_ids( $option_key = null ) {
            $page_ids = null === $option_key ? array_values( $this->get_pages() ) : [ $this->get_page_id( $option_key ) ];

            foreach ( $page_ids as $page_id ) {
                if ( ! $page_id ) {
                    continue;
                }

                $trid = apply_filters( 'wpml_element_trid', null, $page_id, 'post_page' );
                if ( ! $trid ) {
                    continue;
                }

                $translations = apply_filters( 'wpml_get_element_translations', [], $trid, 'post_page' );
                foreach ( (array) $translations as $translation ) {
                    if ( ! empty( $translation->element_id ) && 'publish' === get_post_status( $translation->element_id ) ) {
                        $page_ids[] = (int) $translation->element_id;
                    }
                }
            }

            return array_values( array_unique( array_filter( $page_ids ) ) );
        }

        protected function get_page_id( $option_key ) {
            $pages = $this->get_pages();
            return array_key_exists( $option_key, $pages ) ? $pages[ $option_key ] : 0;
        }

        protected function get_page_slug( $page_id, $default_slug ) {
            $home_url = home_url( '/' );
            $slug     = str_replace( $home_url, '', get_permalink( $page_id ) );
            $slug     = rtrim( $slug, '/' );
            $slug     = preg_match( '/([?])/', $slug ) ? $default_slug : $slug;

            // WPML may adjust get_permalink() to the request language. Rewrite
            // paths must instead use each translated page's own URI.
            if ( has_filter( 'wpml_element_trid' ) && get_option( 'permalink_structure' ) ) {
                global $wp_rewrite;
                $slug = trim( str_replace( '%pagename%', get_page_uri( $page_id ), $wp_rewrite->get_page_permastruct() ), '/' );
            }

            return apply_filters( 'directorist_rewrite_get_page_slug', $slug, $page_id, $default_slug );
        }

        public function add_write_rules() {
            $cached_pages = get_pages(
                [
                    'include' => $this->get_page_ids()
                ]
            );

            foreach ( $this->get_page_ids( 'all_listing_page' ) as $page_id ) {
                $link = $this->get_page_slug( $page_id, 'directory-all-listing' );

                add_rewrite_rule( "$link/page/?([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&paged=$matches[1]', 'top' );
            }

            // Author profile page URL Rewrite
            foreach ( $this->get_page_ids( 'author_profile_page' ) as $page_id ) {
                $link = $this->get_page_slug( $page_id, 'directory-profile' );

                // Link > Page
                add_rewrite_rule( "$link/page/(\d+)/?$", 'index.php?page_id=' . $page_id . '&paged=$matches[1]', 'top' );

                // Link > Author > Page
                add_rewrite_rule( "$link/([^/]+)/?$", 'index.php?page_id=' . $page_id . '&author_id=$matches[1]', 'top' );
                add_rewrite_rule( "$link/([^/]+)/page/(\d)/?$", 'index.php?page_id=' . $page_id . '&author_id=$matches[1]&paged=$matches[2]', 'top' );

                // Link > Author > Directory > Page
                add_rewrite_rule( "$link/([^/]+)/directory/([^/]+)/?$", 'index.php?page_id=' . $page_id . '&author_id=$matches[1]&directory-type=$matches[2]', 'top' );
                add_rewrite_rule( "$link/([^/]+)/directory/([^/]+)/page/(\d)/?$", 'index.php?page_id=' . $page_id . '&author_id=$matches[1]&directory-type=$matches[2]&paged=$matches[3]', 'top' );

                // Link > Directory > Page
                add_rewrite_rule( "$link/directory/([^/]+)/?$", 'index.php?page_id=' . $page_id . '&directory-type=$matches[1]', 'top' );
                add_rewrite_rule( "$link/directory/([^/]+)/page/(\d)/?$", 'index.php?page_id=' . $page_id . '&directory-type=$matches[1]&paged=$matches[2]', 'top' );
            }

            // Checkout page URL Rewrite
            foreach ( $this->get_page_ids( 'checkout_page' ) as $page_id ) {
                $link = $this->get_page_slug( $page_id, 'directory-checkout' );

                add_rewrite_rule( "$link/submit/([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_action=submission&atbdp_listing_id=$matches[1]', 'top' );
                add_rewrite_rule( "$link/promote/([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_action=promotion&atbdp_listing_id=$matches[1]', 'top' );
                add_rewrite_rule( "$link/paypal-ipn/([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_action=paypal-ipn&atbdp_order_id=$matches[1]', 'top' );
                add_rewrite_rule( "$link/([^/]+)/([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_action=$matches[1]&atbdp_order_id=$matches[2]', 'top' ); // we can add listing_id instead of order_id if we want.
            }

            // Payment receipt page
            foreach ( $this->get_page_ids( 'payment_receipt_page' ) as $page_id ) {
                $link = $this->get_page_slug( $page_id, 'directory-payment-receipt' );

                add_rewrite_rule( "$link/order/([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_action=order&atbdp_order_id=$matches[1]', 'top' );
            }


            // Edit Listing/Renew Listing/Delete listings etc
            foreach ( $this->get_page_ids( 'add_listing_page' ) as $page_id ) {
                $link = $this->get_page_slug( $page_id, 'directory-add-listing' );

                add_rewrite_rule( "$link/([^/]+)/([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_action=$matches[1]&atbdp_listing_id=$matches[2]', 'top' );
            }

            if ( ! directorist_is_archive_template_enabled() ) {
                // Single Category page
                foreach ( $this->get_page_ids( 'single_category_page' ) as $page_id ) {
                    $link = $this->get_page_slug( $page_id, 'directory-single-category' );
                    add_rewrite_rule( "$link/([^/]+)/page/?([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_category=$matches[1]&paged=$matches[2]', 'top' );
                    add_rewrite_rule( "$link/([^/]+)/?$", 'index.php?page_id=' . $page_id . '&atbdp_category=$matches[1]', 'top' );
                }

                // Single Location page
                foreach ( $this->get_page_ids( 'single_location_page' ) as $page_id ) {
                    $link = $this->get_page_slug( $page_id, 'directory-single-location' );

                    add_rewrite_rule( "$link/([^/]+)/page/?([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_location=$matches[1]&paged=$matches[2]', 'top' );
                    add_rewrite_rule( "$link/([^/]+)/?$", 'index.php?page_id=' . $page_id . '&atbdp_location=$matches[1]', 'top' );
                }

                // Single Tag page
                foreach ( $this->get_page_ids( 'single_tag_page' ) as $page_id ) {
                    $link = $this->get_page_slug( $page_id, 'directory-single-tag' );

                    add_rewrite_rule( "$link/([^/]+)/page/?([0-9]{1,})/?$", 'index.php?page_id=' . $page_id . '&atbdp_tag=$matches[1]&paged=$matches[2]', 'top' );
                    add_rewrite_rule( "$link/([^/]+)/?$", 'index.php?page_id=' . $page_id . '&atbdp_tag=$matches[1]', 'top' );
                }
            }

            unset( $cached_pages );

            // Rewrite tags (Making custom query var available throughout the application
            // WordPress by default does not understand the unknown query vars. It needs to be registered with WP for using it.
            // by using add_rewrite_tag() or add_query_arg() on init hook or other earlier hook, we can register custom query var eg. atbdp_action and  we can access it later on any other page
            // by using get_query_var( 'atbdp_action' );  anywhere in the page.
            // otherwise, get_query_var() would return and empty string even if the 'atbdp_action' var is available in the query string.
            //
            add_rewrite_tag( '%atbdp_action%', '([^/]+)' );
            add_rewrite_tag( '%atbdp_order_id%', '([0-9]{1,})' );
            add_rewrite_tag( '%atbdp_listing_id%', '([0-9]{1,})' );
            add_rewrite_tag( '%author_id%', '([^/]+)' );
            add_rewrite_tag( '%directory-type%', '([^/]+)' );
            add_rewrite_tag( '%atbdp_category%', '([^/]+)' );
            add_rewrite_tag( '%atbdp_location%', '([^/]+)' );
            add_rewrite_tag( '%atbdp_tag%', '([^/]+)' );
        }

        /**
         * Flush the rewrite rules if needed as we have added new rewrite rules
         *
         * @since    3.1.2
         * @access   public
         */
        public function flush_rewrite_rules_on_demand() {

            if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
                return;
            }

            $rewrite_rules = get_option( 'rewrite_rules' );

            if ( $rewrite_rules ) {

                global $wp_rewrite;
                $rewrite_rules_array = [];
                foreach ( $rewrite_rules as $rule => $rewrite ) {
                    $rewrite_rules_array[$rule]['rewrite'] = $rewrite;
                }
                $rewrite_rules_array = array_reverse( $rewrite_rules_array, true );

                $maybe_missing = $wp_rewrite->rewrite_rules();
                $missing_rules = false;

                foreach ( $maybe_missing as $rule => $rewrite ) {
                    if ( ! array_key_exists( $rule, $rewrite_rules_array ) ) {
                        $missing_rules = true;
                        break;
                    }
                }

                if ( true === $missing_rules ) {
                    flush_rewrite_rules();
                }

            }
        }
    } // ends ATBDP_Rewrite

endif;
