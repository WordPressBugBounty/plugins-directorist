<?php
namespace Directorist\Integrations\HelpGent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Throwable;

/**
 * Main integration service.
 *
 * @package Directorist\Integrations\HelpGent
 */

defined( 'ABSPATH' ) || exit;

final class Integration {
    public const NEW_LEAD_LOCKS_ENABLED = false;

    private static ?self $instance = null;

    /** @var array<string,mixed> */
    private array $request_context = [];

    private bool $available = false;

    public Conversations $conversations;

    private array $render_context = [];

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void {
        if ( $this->available ) {
            return;
        }
        $this->available = true;
        Database::maybe_upgrade();
        add_action( 'directorist_installed', [ Database::class, 'maybe_upgrade' ] );
        add_action( 'directorist_updated', [ Database::class, 'maybe_upgrade' ] );
        Assets::register_hooks();

        $this->conversations = new Conversations();
        add_filter( 'helpgent_ai_allowed_for_response', [ $this->conversations, 'allow_ai' ], 20, 2 );
        add_filter( 'helpgent_chat_message', [ $this->conversations, 'prepare_chat_message' ] );
        add_filter( 'helpgent_form_with_builder_media_assets', [ $this, 'listing_form_context' ], 30 );
        add_filter( 'helpgent_response_item', [ $this, 'listing_response_context' ], 30 );

        add_filter( 'directorist_dashboard_tabs', [ $this, 'dashboard_tab' ], 30 );

        add_filter( 'rest_request_before_callbacks', [ $this, 'capture_request_context' ], 5, 3 );
        add_action( 'helpgent_after_response_created', [ $this, 'link_response' ], 50, 2 );
        add_action( 'helpgent_after_submit_form', [ $this, 'finalize_response' ], 20, 3 );
        add_action( 'helpgent_after_store_message', [ $this, 'track_reply' ], 30, 4 );
        add_action( 'helpgent_after_store_chat_message', [ $this, 'track_reply' ], 30, 4 );

        add_filter( 'helpgent_is_user_allowed_response_message', [ $this, 'allow_owner_message_access' ], 20, 6 );
        add_filter( 'helpgent_can_store_response_message', [ $this, 'allow_owner_reply' ], 20, 4 );
        add_filter( 'helpgent_is_user_allowed_response_chat_message', [ $this, 'allow_owner_chat_access' ], 20, 4 );
        add_filter( 'helpgent_can_user_access_attachment', [ $this, 'allow_owner_attachment_access' ], 20, 3 );
        add_filter( 'helpgent_new_response_mailer_for_admin', [ $this, 'route_new_enquiry_email' ], 20, 6 );
        add_filter( 'helpgent_new_message_mailer_for_user', [ $this, 'route_visitor_reply_email' ], 20, 7 );

        ( new Rest_Controller( $this ) )->register();
    }

    public function dashboard_tab( array $tabs ): array {
        do_action( 'helpgent_maybe_create_user' );
        Assets::dashboard();
        wp_localize_script(
            'directorist-helpgent-dashboard',
            'DirectoristHelpGentDashboard',
            [
                'root'  => esc_url_raw( rest_url( 'directorist-helpgent/v1/' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'pollInterval' => 5000,
                'icons' => [
                    'inbox' => directorist_icon( 'uil uil-inbox', false ),
                    'chat' => directorist_icon( 'uil uil-comment-message', false ),
                    'mail' => directorist_icon( 'uil uil-envelope', false ),
                    'phone' => directorist_icon( 'uil uil-phone', false ),
                    'send' => directorist_icon( 'uil uil-message', false ),
                    'external' => directorist_icon( 'uil uil-arrow-up-right', false ),
                    'chevron' => directorist_icon( 'uil uil-angle-down', false ),
                    'lock' => directorist_icon( 'uil uil-lock-alt', false ),
                ],
                'i18n'  => [
                    'loading' => __( 'Loading enquiries…', 'directorist' ),
                    'error'   => __( 'The enquiries could not be loaded.', 'directorist' ),
                ],
            ]
        );

        $content = \Directorist\Helper::get_template_contents( 'dashboard/tab-helpgent' );

        $tabs['dashboard_helpgent'] = [
            'title'   => __( 'HelpGent Enquiries', 'directorist' ),
            'icon'    => 'las la-comments',
            'content' => $content,
        ];
        $formgent_position = array_search( 'dashboard_formgent', array_keys( $tabs ), true );
        if ( false !== $formgent_position ) {
            $tabs = array_slice( $tabs, 0, $formgent_position + 1, true )
                + [ 'dashboard_helpgent' => $tabs['dashboard_helpgent'] ]
                + array_slice( $tabs, $formgent_position + 1, null, true );
        }

        return $tabs;
    }

    /**
     * Capture the listing context submitted by the front-end bridge.
     */
    public function capture_request_context( $response, array $handler, WP_REST_Request $request ) {
        $this->request_context = [];
        if ( $response || ! preg_match( '#^/helpgent(?:-pro)?/#', $request->get_route() ) ) {
            return $response;
        }
        $context = (array) $request->get_param( 'directorist_context' );
        if ( ! $context ) {
            return $response;
        }
        $listing_id = absint( $context['listing_id'] ?? 0 );
        $form_id = absint( $request->get_param( 'form_id' ) );
        $protect = ! empty( $context['protect_details'] );
        $signature = (string) ( $context['signature'] ?? '' );
        if ( ! $listing_id || ! $form_id || ! directorist_is_listing_post_type( $listing_id ) ||
            'publish' !== get_post_status( $listing_id ) ||
            ! hash_equals( $this->context_signature( $listing_id, $form_id, $protect ), $signature ) ) {
            return new WP_Error( 'dhgi_invalid_context', __( 'Please reload the listing before starting an enquiry.', 'directorist' ), [ 'status' => 400 ] );
        }
        $this->request_context = [ 'listing_id' => $listing_id, 'form_id' => $form_id, 'protect_details' => $protect ];
        $token = (string) $request->get_param( 'response_token' );
        $existing = $token ? helpgent_get_response_by_token( $token, $form_id ) : null;
        if ( $existing ) {
            $relation = Database::get_by_response( (int) $existing->id );
            if ( $relation && absint( $relation->listing_id ) !== $listing_id ) {
                $this->request_context = [];
                return new WP_Error( 'dhgi_wrong_listing', __( 'Please start a new enquiry for this listing.', 'directorist' ), [ 'status' => 409 ] );
            }
            if ( ! $relation ) {
                $this->link_response( (int) $existing->id, [ 'form_id' => $form_id ] );
            }
        }
        return $response;
    }

    public function context_signature( int $listing_id, int $form_id, bool $protect ): string {
        return wp_hash( implode( ':', [ $listing_id, $form_id, (int) $protect ] ), 'nonce' );
    }

    public function render_form( int $form_id, int $listing_id, bool $protect = false ): string {
        $previous             = $this->render_context;
        $this->render_context = [ 'listing_id' => $listing_id, 'form_id' => $form_id, 'protect_details' => self::NEW_LEAD_LOCKS_ENABLED && $protect ];
        try {
            return do_shortcode( '[helpgent-form id="' . $form_id . '"]' );
        } finally {
            $this->render_context = $previous;
        }
    }

    public function live_chat_context( int $listing_id ): array {
        $name = wp_strip_all_tags( get_the_title( $listing_id ) );
        return [
            'ai_enabled' => false,
            'status_path' => '/directorist-helpgent/v1/visitor-state',
            'agents' => [],
            'attributes' => [
                'chat_team_name' => $name,
                'chat_header_text' => __( 'Chat with the listing owner', 'directorist' ),
                'chat_offline_text' => __( 'The listing owner will reply here when available.', 'directorist' ),
                'chat_welcome_message' => sprintf( __( 'Welcome! How can we help you with %s?', 'directorist' ), $name ),
                'chat_company_logo' => '0',
                'chat_agents_logo' => '0',
                'chat_admin_online_status' => '0',
                'home_company_logo' => '0',
                'home_agents_logo' => '0',
                'home_admin_online_status' => '0',
                'home_offline_btn_text' => __( 'Message the listing owner', 'directorist' ),
                'home_online_btn_text' => __( 'Message the listing owner', 'directorist' ),
            ],
        ];
    }

    public function listing_form_context( $form ) {
        if ( ! $form || empty( $this->render_context ) || absint( $form->id ) !== $this->render_context['form_id'] ) {
            return $form;
        }
        $form = clone $form;
        $form->live_chat_context = $this->live_chat_context( $this->render_context['listing_id'] );
        $form->session_scope = 'directorist-listing-' . $this->render_context['listing_id'];
        $form->submission_context = [ 'directorist_context' => [
            'listing_id' => $this->render_context['listing_id'],
            'protect_details' => (int) $this->render_context['protect_details'],
            'signature' => $this->context_signature( $this->render_context['listing_id'], (int) $form->id, $this->render_context['protect_details'] ),
        ]
        ];
        return $form;
    }

    public function listing_response_context( $response ) {
        $relation = Database::get_by_response( absint( $response->id ) );
        if ( $relation ) {
            $response->live_chat_context = $this->live_chat_context( absint( $relation->listing_id ) );
        }
        return $response;
    }

    public function link_response( int $response_id, array $data ): void {
        if ( empty( $this->request_context ) ) {
            return;
        }

        $listing_id = absint( $this->request_context['listing_id'] );
        $form_id    = absint( $data['form_id'] ?? $this->request_context['form_id'] );

        if ( $form_id !== absint( $this->request_context['form_id'] ) ) {
            return;
        }

        $relation = [
            'response_id'       => $response_id,
            'form_id'           => $form_id,
            'listing_id'        => $listing_id,
            'owner_id'          => absint( get_post_field( 'post_author', $listing_id ) ),
            'directory_type_id' => function_exists( 'directorist_get_listings_directory_type' ) ? absint( directorist_get_listings_directory_type( $listing_id ) ) : absint( get_post_meta( $listing_id, '_directory_type', true ) ),
        ];
        if ( self::NEW_LEAD_LOCKS_ENABLED ) {
            $relation['is_locked'] = ! empty( $this->request_context['protect_details'] ) ? 1 : 0;
        }
        Database::upsert( $relation );

        $this->store_response_meta( $response_id, 'directorist_listing_id', $listing_id );
        $this->store_response_meta( $response_id, 'directorist_listing_owner_id', absint( get_post_field( 'post_author', $listing_id ) ) );
    }

    public function finalize_response( object $response, object $form, WP_REST_Request $request ): void {
        if ( ! Database::get_by_response( absint( $response->id ) ) && ! empty( $this->request_context ) ) {
            $this->link_response( absint( $response->id ), [ 'form_id' => absint( $response->form_id ) ] );
        }

        $relation = Database::get_by_response( absint( $response->id ) );
        if ( ! $relation ) {
            return;
        }

        $insight = $this->build_insight( absint( $response->id ), $relation );
        Database::upsert( array_merge( [ 'response_id' => absint( $response->id ) ], $insight ) );
    }

    /**
     * Produce an explainable MVP summary and qualification score.
     */
    private function build_insight( int $response_id, object $relation ): array {
        global $wpdb;

        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, first_name, last_name, email, mobile_number FROM {$wpdb->prefix}helpgent_contact_info WHERE response_id = %d ORDER BY id DESC LIMIT 1",
                $response_id
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $visitor = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT u.name, u.email FROM {$wpdb->prefix}helpgent_responses r LEFT JOIN {$wpdb->prefix}helpgent_users u ON u.id = r.created_by WHERE r.id = %d",
                $response_id
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $name  = trim( (string) ( $contact->name ?? '' ) );
        $name  = $name ?: trim( (string) ( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) ) );
        $name  = $name ?: (string) ( $visitor->name ?? '' );
        $email = sanitize_email( (string) ( $contact->email ?? $visitor->email ?? '' ) );
        $phone = sanitize_text_field( (string) ( $contact->mobile_number ?? '' ) );

        $parts       = [];
        $answer_count = 0;
        $has_media   = false;

        try {
            $answers = helpgent_singleton( \HelpGent\App\Repositories\AnswerRepository::class )->get( $response_id );
            foreach ( (array) $answers as $answer ) {
                ++$answer_count;
                $has_media = $has_media || ! empty( $answer->attachment ) || ! empty( $answer->attachments );
                $text      = $this->answer_text( $answer );
                if ( $text && count( $parts ) < 3 ) {
                    $parts[] = sanitize_text_field( (string) ( $answer->element_title ?? __( 'Answer', 'directorist' ) ) ) . ': ' . $text;
                }
            }
        } catch ( Throwable $throwable ) {
            $answers = [];
        }

        $score  = min( 40, $answer_count * 10 );
        $score += ( $email || $phone ) ? 30 : 0;
        $score += $has_media ? 10 : 0;
        $score += 20;

        $qualification = $score >= 70 ? 'hot' : ( $score >= 40 ? 'warm' : 'new' );
        $summary       = $name ? sprintf( __( '%s enquired about %s.', 'directorist' ), $name, get_the_title( absint( $relation->listing_id ) ) ) : sprintf( __( 'A visitor enquired about %s.', 'directorist' ), get_the_title( absint( $relation->listing_id ) ) );
        if ( $parts ) {
            $summary .= ' ' . implode( ' · ', $parts );
        }

        /**
         * Filter deterministic insight data to plug in an external AI summarizer later.
         */
        return apply_filters(
            'directorist_helpgent_response_insight',
            [
                'summary'             => wp_strip_all_tags( $summary ),
                'qualification'       => $qualification,
                'qualification_score' => $score,
                'contact_name'        => sanitize_text_field( $name ),
                'contact_email'       => $email,
                'contact_phone'       => $phone,
            ],
            $response_id,
            $answers,
            $relation
        );
    }

    private function answer_text( object $answer ): string {
        $value = $answer->value ?? '';
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value   = JSON_ERROR_NONE === json_last_error() ? $decoded : $value;
        }
        if ( is_array( $value ) ) {
            $value = implode( ', ', array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) ) );
        }

        return mb_substr( sanitize_text_field( (string) $value ), 0, 180 );
    }

    private function store_response_meta( int $response_id, string $key, $value ): void {
        try {
            $repository = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class );
            $repository->update_meta( $response_id, $key, (string) $value );
        } catch ( Throwable $throwable ) {
            // The integration table remains the source of truth if HelpGent meta is unavailable.
        }
    }

    public function is_owner( int $response_id, $helpgent_user = null ): bool {
        $relation = Database::get_by_response( $response_id );
        if ( ! $relation || ! is_user_logged_in() ) {
            return false;
        }

        $wp_user_id = get_current_user_id();
        if ( $helpgent_user && method_exists( $helpgent_user, 'get_registered_id' ) ) {
            $wp_user_id = absint( $helpgent_user->get_registered_id() );
        }

        return $this->conversations->can_manage( $relation, $wp_user_id );
    }

    public function is_unlocked( object $relation, int $user_id = 0 ): bool {
        if ( ! absint( $relation->is_locked ) ) {
            return true;
        }

        return (bool) apply_filters( 'directorist_helpgent_is_conversation_unlocked', false, $relation, $user_id ?: get_current_user_id() );
    }

    public function allow_owner_message_access( bool $allowed, int $response_id, object $response, $user, string $context ): bool {
        return $allowed || $this->is_owner( $response_id, $user );
    }

    public function allow_owner_reply( bool $allowed, object $response, $user, string $context ): bool {
        return $allowed || $this->is_owner( absint( $response->id ), $user );
    }

    public function allow_owner_chat_access( bool $allowed, object $response, $user, string $context ): bool {
        return $allowed || $this->is_owner( absint( $response->id ), $user );
    }

    public function allow_owner_attachment_access( bool $allowed, $attachment, $user ): bool {
        if ( $allowed || ! $user || ! method_exists( $attachment, 'get_id' ) ) {
            return $allowed;
        }

        global $wpdb;
        $response_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT response_id FROM {$wpdb->prefix}helpgent_response_attachment WHERE attachment_id = %d LIMIT 1",
                    absint( $attachment->get_id() )
                )
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $relation = Database::get_by_response( $response_id );

        return $relation && $this->is_owner( $response_id, $user ) && $this->is_unlocked( $relation );
    }

    public function track_reply( $message_dto, WP_REST_Request $request, object $form, object $response ): void {
        $relation = Database::get_by_response( absint( $response->id ) );
        if ( ! $relation ) {
            return;
        }

        $user = helpgent_get_current_user();
        if ( ! $this->is_owner( absint( $response->id ), $user ) ||
            ! $user || $message_dto->get_created_by() !== $user->get_id() ||
            $message_dto->get_created_by() === absint( $response->created_by ) ) {
            return;
        }
        if ( $message_dto instanceof \HelpGent\App\DTO\ChatMessageDTO ) {
            $this->store_response_meta( absint( $response->id ), 'directorist_owner_chat_message_' . $message_dto->get_id(), '1' );
        }

        $settings = Enquiry_Settings::for_directory( (int) $relation->effective_directory_id );
        $statuses = array_column( $settings['statuses'], 'value', 'id' );
        $auto_contacted = $settings['status_enabled'] && isset( $statuses['new'], $statuses['contacted'] );
        global $wpdb;
        $table = Database::table();
        $now = current_time( 'mysql', true );
        // Check the status at write time so a simultaneous status edit is preserved.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = IF(%d AND status = 'new', 'contacted', status),
                    first_replied_at = COALESCE(first_replied_at, %s), updated_at = %s WHERE id = %d",
                (int) $auto_contacted, $now, $now, $relation->id
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL
    }

    public function route_new_enquiry_email( $mailer, object $response, object $form, string $response_token, ?array $admin, ?array $created_by_user ) {
        $relation = Database::get_by_response( absint( $response->id ) );
        if ( ! $relation ) {
            return $mailer;
        }

        $owner = get_userdata( $this->conversations->owner_id( $relation ) );
        if ( ! $owner || ! is_email( $owner->user_email ) ) {
            return $mailer;
        }

        $mailer->to                              = $owner->user_email;
        $mailer->to_user                         = [ 'name' => $owner->display_name, 'email' => $owner->user_email ];
        $mailer->placeholders['{{TO_NAME}}']      = $owner->display_name;
        $mailer->placeholders['{{TO_EMAIL}}']     = $owner->user_email;
        $mailer->placeholders['{{DASHBOARD_LINK}}'] = sprintf( '<a href="%s">%s</a>', esc_url( \ATBDP_Permalink::get_dashboard_page_link() . '#dashboard_helpgent' ), esc_html__( 'Open listing enquiries', 'directorist' ) );

        return $mailer;
    }

    /**
     * Route visitor follow-up notifications to the listing owner as well.
     */
    public function route_visitor_reply_email( $mailer, object $response, object $form, ?array $admin, ?array $response_user, ?array $messaged_by ) {
        if ( ! $mailer instanceof \HelpGent\App\Mail\Admin\NewMessageMailer ) {
            return $mailer;
        }

        $relation = Database::get_by_response( absint( $response->id ) );
        if ( ! $relation ) {
            return $mailer;
        }

        $owner = get_userdata( $this->conversations->owner_id( $relation ) );
        if ( ! $owner || ! is_email( $owner->user_email ) ) {
            return $mailer;
        }

        $mailer->to                               = $owner->user_email;
        $mailer->to_user                          = [ 'name' => $owner->display_name, 'email' => $owner->user_email ];
        $mailer->placeholders['{{TO_NAME}}']       = $owner->display_name;
        $mailer->placeholders['{{TO_EMAIL}}']      = $owner->user_email;
        $mailer->placeholders['{{DASHBOARD_LINK}}'] = sprintf( '<a href="%s">%s</a>', esc_url( \ATBDP_Permalink::get_dashboard_page_link() . '#dashboard_helpgent' ), esc_html__( 'Open listing enquiries', 'directorist' ) );

        return $mailer;
    }
}
