<?php
namespace Directorist\Integrations\HelpGent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Throwable;

/**
 * Listing-owner dashboard API.
 *
 * @package Directorist\Integrations\HelpGent
 */

defined( 'ABSPATH' ) || exit;

final class Rest_Controller {
    private const INBOX_PAGE_SIZE = 30;
    private const MESSAGE_PAGE_SIZE = 100;

    private Integration $plugin;

    public function __construct( Integration $plugin ) {
        $this->plugin = $plugin;
    }

    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function routes(): void {
        register_rest_route(
            'directorist-helpgent/v1',
            '/conversations',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'index' ],
                'permission_callback' => [ $this, 'logged_in' ],
                'args' => [
                    'page'   => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                    'view'   => [ 'type' => 'string', 'enum' => [ 'all', 'unread' ], 'default' => 'all' ],
                    'directory_type' => [ 'type' => 'string', 'pattern' => '^(all|[0-9]+)$', 'default' => 'all' ],
                    'status' => [ 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,23}$', 'default' => 'all' ],
                    'score'  => [ 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,23}$', 'default' => 'any' ],
                ],
            ]
        );

        register_rest_route(
            'directorist-helpgent/v1', '/conversations/unread-count', [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'unread_count' ],
                'permission_callback' => [ $this, 'logged_in' ],
            ]
        );

        register_rest_route(
            'directorist-helpgent/v1', '/conversations/(?P<id>\d+)/follow-ups', [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'follow_up_history' ],
                'permission_callback' => [ $this, 'logged_in' ],
                'args'                => [
                    'page' => [ 'type' => 'integer', 'minimum' => 2, 'default' => 2 ],
                ],
            ]
        );

        register_rest_route(
            'directorist-helpgent/v1',
            '/conversations/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'show' ],
                'permission_callback' => [ $this, 'logged_in' ],
            ]
        );

        register_rest_route(
            'directorist-helpgent/v1',
            '/conversations/(?P<id>\d+)/reply',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'reply' ],
                'permission_callback' => [ $this, 'logged_in' ],
                'args'                => [
                    'channel' => [ 'type' => 'string', 'enum' => [ 'message', 'chat' ] ],
                    'message' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            'directorist-helpgent/v1',
            '/conversations/(?P<id>\d+)/status',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'status' ],
                'permission_callback' => [ $this, 'logged_in' ],
                'args'                => [
                    'status' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        register_rest_route(
            'directorist-helpgent/v1', '/visitor-state', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [ $this, 'visitor_state' ],
                'permission_callback' => '__return_true',
                'args' => [
                    'form_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                    'response_token' => [ 'required' => true, 'type' => 'string' ],
                ],
            ]
        );
        foreach ( [ 'read', 'close', 'score' ] as $action ) {
            register_rest_route(
                'directorist-helpgent/v1', '/conversations/(?P<id>\d+)/' . $action, [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [ $this, $action ],
                    'permission_callback' => [ $this, 'logged_in' ],
                ]
            );
        }
    }

    public function logged_in(): bool {
        return is_user_logged_in();
    }

    public function unread_count(): WP_REST_Response {
        return rest_ensure_response( [ 'unread' => Database::unread_for_user( get_current_user_id(), current_user_can( 'manage_options' ) ) ] );
    }

    public function index( WP_REST_Request $request ): WP_REST_Response {
        $user_id = get_current_user_id();
        $is_admin = current_user_can( 'manage_options' );
        $groups = Database::inbox_groups( $user_id, $is_admin );
        $directories = $this->directory_options( $groups );
        $requested_directory = $request->get_param( 'directory_type' ) ?? 'all';
        $selected_directory = 'all' !== $requested_directory && isset( $directories[ $requested_directory ] ) ? (int) $requested_directory : null;
        $directory_id = $selected_directory;
        if ( null === $directory_id && 1 === count( $directories ) ) {
            $directory_id = (int) key( $directories );
        }
        $settings = Enquiry_Settings::for_directory( $directory_id ?? 0 );
        $settings_by_type = null === $directory_id ? [] : [ $directory_id => $settings ];
        $show_fields = null !== $directory_id || ! $directories;
        $status_options = $show_fields && $settings['status_enabled'] ? $this->status_options( $settings ) : [];
        $score_options = $show_fields && $settings['score_enabled'] ? array_column( $settings['bands'], 'value', 'id' ) : [];
        $requested_score = $request->get_param( 'score' ) ?? 'any';
        $show_unbanded = $show_fields && $settings['score_enabled'] && $this->has_unbanded_scores( $groups, $directory_id, $settings );
        $requested_status = $request->get_param( 'status' ) ?? 'all';
        $filters = [
            'directory_id' => $directory_id,
            'view'   => $request->get_param( 'view' ) ?? 'all',
            'status' => isset( $status_options[ $requested_status ] ) ? $requested_status : 'all',
            'score'  => isset( $score_options[ $requested_score ] ) || ( $show_unbanded && Enquiry_Settings::UNBANDED === $requested_score ) ? $requested_score : 'any',
        ];
        $filters['score_ranges'] = Enquiry_Settings::UNBANDED === $filters['score'] ? $score_options : ( isset( $score_options[ $filters['score'] ] ) ? [ $score_options[ $filters['score'] ] ] : [] );
        $items  = [];
        $counts = array_fill_keys( array_merge( [ 'all', 'unread' ], Conversations::STATUSES, array_keys( $status_options ) ), 0 );
        $facets = [
            'view'   => [ 'all' => 0, 'unread' => 0 ],
            'status' => array_fill_keys( array_merge( [ 'all' ], array_keys( $status_options ) ), 0 ),
            'score'  => array_fill_keys( array_merge( [ 'any', Enquiry_Settings::UNBANDED ], array_keys( $score_options ) ), 0 ),
        ];
        $total = 0;
        foreach ( $groups as $group ) {
            $count = (int) $group->total;
            $is_unread = (bool) $group->is_unread;
            $counts['all'] += $count;
            $counts['unread'] += $is_unread ? $count : 0;
            if ( isset( $counts[ $group->status ] ) && ! in_array( $group->status, [ 'all', 'unread' ], true ) ) {
                $counts[ $group->status ] += $count;
            }
            if ( null !== $directory_id && $directory_id !== (int) $group->effective_directory_id ) {
                continue;
            }
            $band = $show_fields ? Enquiry_Settings::band( $settings, (int) $group->effective_score ) : null;
            $band_id = $band['id'] ?? Enquiry_Settings::UNBANDED;
            $matches_view = 'all' === $filters['view'] || $is_unread;
            $matches_status = 'all' === $filters['status'] || $filters['status'] === $group->status;
            $matches_score = 'any' === $filters['score'] || $filters['score'] === $band_id;
            if ( $matches_view && $matches_score ) {
                $facets['status']['all'] += $count;
                if ( isset( $status_options[ $group->status ] ) ) {
                    $facets['status'][ $group->status ] += $count;
                }
            }
            if ( $matches_status && $matches_score ) {
                $facets['view']['all'] += $count;
                $facets['view']['unread'] += $is_unread ? $count : 0;
            }
            if ( $matches_view && $matches_status ) {
                $facets['score']['any'] += $count;
                if ( isset( $facets['score'][ $band_id ] ) ) {
                    $facets['score'][ $band_id ] += $count;
                }
            }
            if ( $matches_view && $matches_status && $matches_score ) {
                $total += $count;
            }
        }
        $pages = max( 1, (int) ceil( $total / self::INBOX_PAGE_SIZE ) );
        $page = min( $pages, max( 1, absint( $request->get_param( 'page' ) ) ) );

        $statuses_by_type = [];
        foreach ( Database::list_for_user( $user_id, $is_admin, $filters, self::INBOX_PAGE_SIZE, ( $page - 1 ) * self::INBOX_PAGE_SIZE ) as $row ) {
            $unlocked = $this->plugin->is_unlocked( $row, $user_id );
            $type_id = (int) $row->effective_directory_id;
            $row_settings = $settings_by_type[ $type_id ] ?? ( $settings_by_type[ $type_id ] = Enquiry_Settings::for_directory( $type_id ) );
            $row_statuses = $statuses_by_type[ $type_id ] ?? ( $statuses_by_type[ $type_id ] = $row_settings['status_enabled'] ? $this->status_options( $row_settings ) : [] );
            $row_status = $row_statuses[ $row->status ] ?? [];
            $items[]  = [
                'id'                  => absint( $row->response_id ),
                'unreadCount'         => absint( $row->unread_count ),
                'listingId'           => absint( $row->listing_id ),
                'listingTitle'        => (string) $row->listing_title,
                'listingUrl'          => get_permalink( absint( $row->listing_id ) ),
                'formTitle'           => (string) $row->form_title,
                'visitorName'         => (string) ( $row->contact_name ?: $row->visitor_name ?: __( 'Anonymous visitor', 'directorist' ) ),
                'visitorEmail'        => $unlocked ? (string) ( $row->contact_email ?: $row->visitor_email ) : '',
                'visitorAvatarUrl'    => $unlocked && $row->visitor_email ? esc_url_raw( (string) get_avatar_url( $row->visitor_email ) ) : '',
                'status'              => (string) $row->status,
                'statusLabel'         => $row_status['label'] ?? '',
                'statusColor'         => $row_status['color'] ?? '',
                'qualification'       => (string) $row->qualification,
                'qualificationScore'  => (int) $row->effective_score,
                'summary'             => $unlocked ? (string) $row->summary : '',
                'locked'              => ! $unlocked,
                'completed'           => (bool) $row->is_completed,
                'updatedAt'           => (string) ( $row->response_updated_at ?: $row->updated_at ),
            ];
        }

        $band_options = [];
        foreach ( $score_options as $id => $band ) {
            $band_options[] = [ 'id' => $id, 'label' => sprintf( '%s (%d–%d)', $band['name'], $band['min'], $band['max'] ) ];
        }
        if ( $show_unbanded ) {
            $band_options[] = [ 'id' => Enquiry_Settings::UNBANDED, 'label' => __( 'Outside score bands', 'directorist' ) ];
        }

        return rest_ensure_response(
            [
                'items'      => $items,
                'counts'     => $counts,
                'facets'     => $facets,
                'pagination' => [ 'page' => $page, 'pages' => $pages, 'total' => $total ],
                'filterOptions' => [
                    'directories' => array_values( $directories ),
                    'statuses'    => array_values( $status_options ),
                    'scores'      => $band_options,
                    'statusLabel' => $settings['status_label'],
                    'scoreLabel'  => $settings['score_label'],
                    'showStatus'  => $show_fields && $settings['status_enabled'],
                    'showScore'   => $show_fields && $settings['score_enabled'],
                ],
                'appliedFilters' => [
                    'directory' => null === $selected_directory ? 'all' : (string) $selected_directory,
                    'status'    => $filters['status'],
                    'score'     => $filters['score'],
                ],
            ]
        );
    }

    private function directory_options( array $groups ): array {
        $directories = [];
        foreach ( $groups as $group ) {
            $id = (int) $group->effective_directory_id;
            if ( isset( $directories[ $id ] ) ) {
                continue;
            }
            $type = $id ? get_term( $id, ATBDP_DIRECTORY_TYPE ) : null;
            $label = $type && ! is_wp_error( $type ) ? $type->name : __( 'Other listings', 'directorist' );
            $directories[ $id ] = [ 'id' => (string) $id, 'label' => $label ];
        }
        uasort( $directories, static function ( $first, $second ) {
            return strnatcasecmp( $first['label'], $second['label'] );
        } );

        return $directories;
    }

    private function status_options( array $settings ): array {
        $options = [];
        foreach ( $settings['statuses'] as $status ) {
            $id = $status['id'];
            $options[ $id ] = [ 'id' => $id, 'label' => $status['value']['name'], 'color' => $status['value']['color'] ];
        }

        return $options;
    }

    private function has_unbanded_scores( array $groups, ?int $directory_id, array $settings ): bool {
        foreach ( $groups as $group ) {
            if ( $directory_id === (int) $group->effective_directory_id && ! Enquiry_Settings::band( $settings, (int) $group->effective_score ) ) {
                return true;
            }
        }

        return false;
    }

    public function show( WP_REST_Request $request ) {
        $response_id = absint( $request['id'] );
        $relation    = $this->authorized_relation( $response_id );
        if ( is_wp_error( $relation ) ) {
            return $relation;
        }

        global $wpdb;
        $response = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, f.title AS form_title, f.content AS form_content, u.name AS visitor_name, u.email AS visitor_email
				FROM {$wpdb->prefix}helpgent_responses r
				LEFT JOIN {$wpdb->prefix}helpgent_forms f ON f.id = r.form_id
				LEFT JOIN {$wpdb->prefix}helpgent_users u ON u.id = r.created_by
				WHERE r.id = %d LIMIT 1",
                $response_id
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ( ! $response ) {
            return new WP_Error( 'dhgi_not_found', __( 'Conversation not found.', 'directorist' ), [ 'status' => 404 ] );
        }

        $unlocked = $this->plugin->is_unlocked( $relation, get_current_user_id() );
        $answers  = [];
        $messages = [];
        $cursors = [ 'chat' => 0, 'message' => 0, 'submission' => absint( $response->is_completed ) ];

        try {
            foreach ( (array) helpgent_singleton( \HelpGent\App\Repositories\AnswerRepository::class )->get( $response_id ) as $answer ) {
                $answers[] = Answers::prepare( $answer, $unlocked );
            }
        } catch ( Throwable $throwable ) {
            $answers = [];
        }

        $contact = $unlocked ? Answers::standalone_contact( $answers, (string) $response->form_content ) : [];

        $has_older_messages = false;
        try {
            $standard = helpgent_singleton( \HelpGent\App\Repositories\MessageRepository::class )->get( $response_id, self::MESSAGE_PAGE_SIZE, 1 );
            $has_older_messages = count( $standard ) === self::MESSAGE_PAGE_SIZE;
            $chat     = helpgent_singleton( \HelpGent\App\Repositories\ChatMessageRepository::class )->get( $response_id, -1, 1 );
            foreach ( [ 'message' => $standard, 'chat' => $chat ] as $source => $items ) {
                foreach ( (array) $items as $message ) {
                    $is_visitor = absint( $message->created_by ) === absint( $response->created_by );
                    $messages[] = array_merge( $this->normalize_message( $message, $unlocked, $is_visitor ), [ 'key' => $source . ':' . $message->id, 'channel' => $source, 'isVisitor' => $is_visitor ] );
                    if ( $is_visitor && 'publish' === $message->status ) {
                        $cursors[$source] = max( $cursors[$source], absint( $message->id ) );
                    }
                }
            }
            usort( $messages, static fn( array $a, array $b ): int => strcmp( $a['createdAt'], $b['createdAt'] ) );
        } catch ( Throwable $throwable ) {
            $messages = [];
        }

        $unlock_url = '';
        if ( ! $unlocked ) {
            $unlock_url = (string) apply_filters( 'directorist_helpgent_conversation_unlock_url', '', $relation, get_current_user_id() );
        }

        $settings = Enquiry_Settings::for_directory( (int) $relation->effective_directory_id );
        $score = min( 100, (int) ( $relation->manual_score ?? $relation->qualification_score ) );
        $band = Enquiry_Settings::band( $settings, $score );
        $status_options = $this->status_options( $settings );
        $status = isset( $status_options[ $relation->status ] ) ? (string) $relation->status : '';
        if ( '' === $status ) {
            $status_options = array_merge(
                [ [ 'id' => '', 'label' => __( 'Select status', 'directorist' ), 'disabled' => true ] ],
                $status_options
            );
        }

        return rest_ensure_response(
            [
                'id'             => $response_id,
                'listingId'      => absint( $relation->listing_id ),
                'listingTitle'   => get_the_title( absint( $relation->listing_id ) ),
                'listingUrl'     => get_permalink( absint( $relation->listing_id ) ),
                'formTitle'      => (string) $response->form_title,
                'visitorName'    => (string) ( $relation->contact_name ?: $response->visitor_name ?: __( 'Anonymous visitor', 'directorist' ) ),
                'visitorEmail'   => $unlocked ? ( $contact['email'] ?? (string) ( $relation->contact_email ?: $response->visitor_email ) ) : '',
                'visitorAvatarUrl' => $unlocked && $response->visitor_email ? esc_url_raw( (string) get_avatar_url( $response->visitor_email ) ) : '',
                'visitorPhone'   => $unlocked ? ( $contact['phone'] ?? (string) $relation->contact_phone ) : '',
                'status'         => $status,
                'qualification'  => (string) $relation->qualification,
                'score'          => $score,
                'scoreEnabled'   => $settings['score_enabled'],
                'scoreLabel'     => $settings['score_label'],
                'scoreBand'      => $band['value'] ?? null,
                'scoreManual'    => isset( $relation->manual_score ),
                'statusEnabled'  => $settings['status_enabled'],
                'statusLabel'    => $settings['status_label'],
                'statusOptions'  => array_values( $status_options ),
                'summary'        => $unlocked ? (string) $relation->summary : '',
                'locked'         => ! $unlocked,
                'unlockUrl'      => esc_url_raw( $unlock_url ),
                'answers'        => $answers,
                'messages'       => $messages,
                'hasOlderMessages' => $has_older_messages,
                'readCursors'    => $cursors,
                'followUp'       => $this->plugin->conversations->follow_up_state( $response ),
                'liveChat'       => $this->plugin->conversations->live_chat_state( $response, (object) [ 'content' => $response->form_content ], true ),
                'createdAt'      => (string) $response->created_at,
            ]
        );
    }

    public function follow_up_history( WP_REST_Request $request ) {
        $response_id = absint( $request['id'] );
        $relation = $this->authorized_relation( $response_id );
        if ( is_wp_error( $relation ) ) {
            return $relation;
        }
        $response = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class )->get_by_id( $response_id );
        if ( ! $response ) {
            return new WP_Error( 'dhgi_not_found', __( 'Conversation not found.', 'directorist' ), [ 'status' => 404 ] );
        }

        $unlocked = $this->plugin->is_unlocked( $relation, get_current_user_id() );
        $page = max( 2, absint( $request->get_param( 'page' ) ) );
        try {
            $rows = helpgent_singleton( \HelpGent\App\Repositories\MessageRepository::class )->get( $response_id, self::MESSAGE_PAGE_SIZE, $page );
            $messages = [];
            foreach ( $rows as $message ) {
                $is_visitor = absint( $message->created_by ) === absint( $response->created_by );
                $messages[] = array_merge( $this->normalize_message( $message, $unlocked, $is_visitor ), [ 'key' => 'message:' . $message->id, 'channel' => 'message', 'isVisitor' => $is_visitor ] );
            }
        } catch ( Throwable $error ) {
            return new WP_Error( 'dhgi_history_failed', __( 'Older follow-ups could not be loaded.', 'directorist' ), [ 'status' => 500 ] );
        }

        return rest_ensure_response(
            [
                'messages' => array_reverse( $messages ),
                'nextPage' => count( $rows ) === self::MESSAGE_PAGE_SIZE ? $page + 1 : null,
                'locked'   => ! $unlocked,
            ]
        );
    }

    public function reply( WP_REST_Request $request ) {
        $response_id = absint( $request['id'] );
        $relation    = $this->authorized_relation( $response_id );
        if ( is_wp_error( $relation ) ) {
            return $relation;
        }

        $message = trim( (string) $request->get_param( 'message' ) );
        if ( '' === $message ) {
            return new WP_Error( 'dhgi_empty_reply', __( 'Write a reply before sending.', 'directorist' ), [ 'status' => 422 ] );
        }

        do_action( 'helpgent_maybe_create_user' );
        $user = helpgent_get_current_user();
        if ( ! $user ) {
            return new WP_Error( 'dhgi_user_missing', __( 'The HelpGent user profile could not be prepared.', 'directorist' ), [ 'status' => 500 ] );
        }

        try {
            $response_repository = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class );
            $response            = $response_repository->get_by_id( $response_id );
            $controller          = helpgent_singleton( \HelpGent\App\Http\Controllers\MessageController::class );
            if ( ! $response ) {
                return new WP_Error( 'dhgi_not_found', __( 'Conversation not found.', 'directorist' ), [ 'status' => 404 ] );
            }
            $form                = $controller->get_form( absint( $response->form_id ) );
            $chat_state          = $this->plugin->conversations->live_chat_state( $response, $form );
            $channel = $request->get_param( 'channel' ) ?? ( $chat_state['isLiveChat'] ? 'chat' : 'message' );
            $is_chat = 'chat' === $channel;
            if ( $is_chat && $chat_state['closed'] ) {
                return new WP_Error( 'dhgi_chat_closed', __( 'This chat has ended.', 'directorist' ), [ 'status' => 409 ] );
            }
            $rest_request        = new WP_REST_Request( 'POST' );
            $rest_request->set_param( 'notify_via_email', '1' );

            if ( $is_chat && ! $chat_state['isLiveChat'] ) {
                return new WP_Error( 'dhgi_not_live_chat', __( 'This enquiry does not contain Live Chat.', 'directorist' ), [ 'status' => 422 ] );
            }
            $follow_up = $this->plugin->conversations->follow_up_state( $response );
            if ( ! $is_chat && ! $follow_up['canReply'] ) {
                return new WP_Error( 'dhgi_follow_up_unavailable', $follow_up['notice'], [ 'status' => 403 ] );
            }

            if ( $is_chat ) {
                $dto = ( new \HelpGent\App\DTO\ChatMessageDTO() )
                    ->set_form_id( absint( $response->form_id ) )
                    ->set_response_id( $response_id )
                    ->set_message( $message )
                    ->set_created_by( absint( $user->get_id() ) )
                    ->set_replied_by( 'text' )
                    ->set_attachment_ids( [] )
                    ->set_attachment_tokens( [] );
                do_action( 'helpgent_before_store_chat_message', $dto, $rest_request, $form, $response );
                $id = helpgent_singleton( \HelpGent\App\Repositories\ChatMessageRepository::class )->create( $dto );
                $dto->set_id( $id );
                do_action( 'helpgent_after_store_chat_message', $dto, $rest_request, $form, $response );
            } else {
                $dto = ( new \HelpGent\App\DTO\MessageDTO() )
                    ->set_form_id( absint( $response->form_id ) )
                    ->set_response_id( $response_id )
                    ->set_message( $message )
                    ->set_created_by( absint( $user->get_id() ) )
                    ->set_replied_by( 'text' );
                do_action( 'helpgent_before_store_message', $dto, $rest_request, $form, $response );
                $id = helpgent_singleton( \HelpGent\App\Repositories\MessageRepository::class )->create( $dto );
                $dto->set_id( $id );
                do_action( 'helpgent_after_store_message', $dto, $rest_request, $form, $response );
            }

            $response_repository->update_updated_at( $response_id );
        } catch ( Throwable $throwable ) {
            return new WP_Error( 'dhgi_reply_failed', $throwable->getMessage() ?: __( 'The reply could not be sent.', 'directorist' ), [ 'status' => in_array( $throwable->getCode(), [ 403, 409, 422, 429, 503 ], true ) ? $throwable->getCode() : 500 ] );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function status( WP_REST_Request $request ) {
        $response_id = absint( $request['id'] );
        $relation    = $this->authorized_relation( $response_id );
        if ( is_wp_error( $relation ) ) {
            return $relation;
        }

        $status  = sanitize_key( (string) $request->get_param( 'status' ) );
        $settings = Enquiry_Settings::for_directory( (int) $relation->effective_directory_id );
        if ( ! $settings['status_enabled'] ) {
            return new WP_Error( 'dhgi_status_disabled', __( 'Enquiry statuses are disabled for this directory type.', 'directorist' ), [ 'status' => 403 ] );
        }
        $statuses = array_column( $settings['statuses'], 'value', 'id' );
        if ( ! isset( $statuses[ $status ] ) ) {
            return new WP_Error( 'dhgi_invalid_status', __( 'Invalid conversation status.', 'directorist' ), [ 'status' => 422 ] );
        }

        global $wpdb;
        $data = [
            'status'     => $status,
            'updated_at' => current_time( 'mysql', true ),
        ];
        if ( 'won' === $status ) {
            $data['converted_at'] = current_time( 'mysql', true );
        }
        $updated = $wpdb->update( Database::table(), $data, [ 'id' => absint( $relation->id ) ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ( false === $updated ) {
            return new WP_Error( 'dhgi_status_write_failed', __( 'The enquiry status could not be saved.', 'directorist' ), [ 'status' => 500 ] );
        }

        do_action( 'directorist_helpgent_conversation_status_changed', $response_id, $status, $relation );

        return rest_ensure_response( [ 'success' => true, 'status' => $status ] );
    }

    public function score( WP_REST_Request $request ) {
        $relation = $this->authorized_relation( absint( $request['id'] ) );
        if ( is_wp_error( $relation ) ) {
            return $relation;
        }
        $settings = Enquiry_Settings::for_directory( (int) $relation->effective_directory_id );
        if ( ! $settings['score_enabled'] ) {
            return new WP_Error( 'dhgi_score_disabled', __( 'Lead scores are disabled for this directory type.', 'directorist' ), [ 'status' => 403 ] );
        }

        $payload = $request->get_json_params();
        $score = Enquiry_Settings::score_value( $payload['score'] ?? null );
        if ( ! is_array( $payload ) || ! array_key_exists( 'score', $payload ) || ( null !== $payload['score'] && null === $score ) ) {
            return new WP_Error( 'dhgi_invalid_score', __( 'Enter a whole number from 0 to 100.', 'directorist' ), [ 'status' => 422 ] );
        }

        global $wpdb;
        $updated = $wpdb->update( Database::table(), [ 'manual_score' => $score ], [ 'id' => (int) $relation->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ( false === $updated ) {
            return new WP_Error( 'dhgi_score_write_failed', __( 'The lead score could not be saved.', 'directorist' ), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function visitor_state( WP_REST_Request $request ) {
        $response = helpgent_get_response_by_token( (string) $request->get_param( 'response_token' ), absint( $request->get_param( 'form_id' ) ) );
        $relation = $response ? Database::get_by_response( (int) $response->id ) : null;
        $form = $response ? helpgent_singleton( \HelpGent\App\Repositories\FormRepository::class )->get_by_id( (int) $response->form_id ) : null;
        if ( ! $relation || ! $form ) {
            return new WP_Error( 'dhgi_not_found', __( 'Conversation not found.', 'directorist' ), [ 'status' => 404 ] );
        }
        $result = rest_ensure_response( $this->plugin->conversations->live_chat_state( $response, $form ) );
        $result->header( 'Cache-Control', 'private, no-store' );
        return $result;
    }

    public function read( WP_REST_Request $request ) {
        $response_id = absint( $request['id'] );
        $relation = $this->authorized_relation( $response_id );
        if ( is_wp_error( $relation ) ) {
            return $relation;
        }
        $this->plugin->conversations->mark_read( $response_id, (array) $request->get_param( 'cursors' ) );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function close( WP_REST_Request $request ) {
        $response_id = absint( $request['id'] );
        $relation = $this->authorized_relation( $response_id );
        if ( is_wp_error( $relation ) ) {
            return $relation;
        }
        $response = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class )->get_by_id( $response_id );
        $form = $response ? helpgent_singleton( \HelpGent\App\Repositories\FormRepository::class )->get_by_id( (int) $response->form_id ) : null;
        if ( ! $response || ! $form ) {
            return new WP_Error( 'dhgi_not_found', __( 'Conversation not found.', 'directorist' ), [ 'status' => 404 ] );
        }
        $result = $this->plugin->conversations->close( $response, $form );
        return is_wp_error( $result ) ? $result : rest_ensure_response( [ 'success' => true, 'liveChat' => $result ] );
    }

    private function authorized_relation( int $response_id ) {
        return $this->plugin->conversations->authorize( $response_id );
    }

    private function normalize_message( object $message, bool $unlocked, bool $is_visitor ): array {
        $media = [];
        if ( $unlocked ) {
            foreach ( array_filter( array_merge( [ $message->attachment ?? null ], (array) ( $message->attachments ?? [] ) ) ) as $attachment ) {
                $media[] = $this->normalize_attachment( $attachment );
            }
        }

        return [
            'id'        => absint( $message->id ?? 0 ),
            'message'   => wp_strip_all_tags( (string) ( $message->message ?? '' ) ),
            'repliedBy' => sanitize_key( (string) ( $message->replied_by ?? 'text' ) ),
            'userName'  => sanitize_text_field( (string) ( $message->user_name ?? __( 'Participant', 'directorist' ) ) ),
            'userAvatarUrl' => $unlocked || ! $is_visitor ? esc_url_raw( (string) ( $message->user_avatar_url ?? '' ) ) : '',
            'createdAt' => (string) ( $message->created_at ?? '' ),
            'media'     => $media,
        ];
    }

    private function normalize_attachment( $attachment ): array {
        if ( ! is_object( $attachment ) ) {
            return [];
        }

        return [
            'id'       => absint( $attachment->id ?? 0 ),
            'title'    => sanitize_text_field( (string) ( $attachment->title ?? __( 'Attachment', 'directorist' ) ) ),
            'mimeType' => sanitize_mime_type( (string) ( $attachment->mime_type ?? '' ) ),
            'url'      => esc_url_raw( (string) ( $attachment->url ?? $attachment->file_url ?? '' ) ),
        ];
    }
}
