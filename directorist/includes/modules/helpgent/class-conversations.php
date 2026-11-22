<?php
namespace Directorist\Integrations\HelpGent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Throwable;

/** Listing conversation policy and owner actions. */
defined( 'ABSPATH' ) || exit;

final class Conversations {
    public const STATUSES = [ 'new', 'contacted', 'qualified', 'booked', 'won', 'lost' ];

    public function owner_id( object $relation ): int {
        return absint( get_post_field( 'post_author', absint( $relation->listing_id ) ) );
    }

    public function can_manage( object $relation, int $user_id ): bool {
        return $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || $this->owner_id( $relation ) === $user_id );
    }

    public function authorize( int $response_id ) {
        $relation = Database::get_by_response( $response_id );
        if ( ! $relation ) {
            return new WP_Error( 'dhgi_not_found', __( 'Conversation not found.', 'directorist' ), [ 'status' => 404 ] );
        }
        if ( ! $this->can_manage( $relation, get_current_user_id() ) ) {
            return new WP_Error( 'dhgi_forbidden', __( 'You cannot access this listing enquiry.', 'directorist' ), [ 'status' => 403 ] );
        }
        return $relation;
    }

    public function allow_ai( bool $allowed, int $response_id ): bool {
        return $allowed && ! Database::get_by_response( $response_id );
    }

    public function live_chat_state( object $response, object $form, bool $include_closer = false ): array {
        $repository = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class );
        $answer = $repository->get_response_answer( (int) $response->id );
        $is_chat = (bool) $answer || helpgent_singleton( \HelpGent\App\Repositories\LiveChatRepository::class )->contains_live_chat( $form->content ?? [] );
        if ( ! $is_chat ) {
            global $wpdb;
            $is_chat = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}helpgent_chat_messages WHERE response_id = %d LIMIT 1", $response->id ) );
        }
        $state = [
            'isLiveChat' => $is_chat,
            'closed' => $is_chat && ( $repository->is_live_chat_closed( (int) $response->id ) || '1' === (string) $response->is_completed ),
            'closedAt' => $answer && '1' === (string) $answer->value ? (string) ( $answer->updated_at ?: $answer->created_at ) : '',
            'aiEnabled' => false,
        ];
        if ( $include_closer ) {
            $state['closedBy'] = $answer && '1' === (string) $answer->value ? (string) ( $answer->closed_by ?? '' ) : '';
            $state['closedByName'] = 'agent' === $state['closedBy'] ? (string) ( $answer->closed_by_name ?? '' ) : '';
        }
        return $state;
    }

    public function follow_up_state( object $response ): array {
        $allowed = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class )->can_receive_staff_message( $response );
        return [
            'canReply' => $allowed,
            'notice' => $allowed ? '' : __( 'Follow-up replies are available after the form is submitted and an email address is provided.', 'directorist' ),
        ];
    }

    public function close( object $response, object $form ) {
        $state = $this->live_chat_state( $response, $form, true );
        if ( ! $state['isLiveChat'] ) {
            return new WP_Error( 'dhgi_not_live_chat', __( 'This enquiry does not contain Live Chat.', 'directorist' ), [ 'status' => 422 ] );
        }
        if ( $state['closed'] ) {
            return $state;
        }

        $live_chat = helpgent_singleton( \HelpGent\App\Repositories\LiveChatRepository::class );
        if ( method_exists( $live_chat, 'close_response' ) ) {
            do_action( 'helpgent_maybe_create_user' );
            $staff = helpgent_get_current_user();
            if ( ! $staff ) {
                return new WP_Error( 'dhgi_user_missing', __( 'The HelpGent user profile could not be prepared.', 'directorist' ), [ 'status' => 500 ] );
            }
            try {
                $live_chat->close_response( $response, $staff );
            } catch ( Throwable $error ) {
                if ( 404 === $error->getCode() ) {
                    return new WP_Error( 'dhgi_missing_chat', __( 'The Live Chat element could not be found.', 'directorist' ), [ 'status' => 409 ] );
                }
                return new WP_Error( 'dhgi_close_failed', __( 'The chat could not be ended.', 'directorist' ), [ 'status' => 500 ] );
            }
            return $this->live_chat_state( $response, $form, true );
        }

        // Older HelpGent releases save the closure answer without attribution.
        $repository = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class );
        $answer = $repository->get_response_answer( (int) $response->id );
        $content = is_string( $form->content ) ? json_decode( $form->content, true ) : (array) $form->content;
        $element = null;
        foreach ( $content['elements'] ?? [] as $item ) {
            if ( 'live_chat' === ( $item['element_type'] ?? '' ) ) {
                $element = $item;
                break;
            }
        }
        if ( $answer ) {
            $element = [ 'id' => $answer->element_id, 'element_type' => 'live_chat' ];
        }
        if ( ! $element ) {
            return new WP_Error( 'dhgi_missing_chat', __( 'The Live Chat element could not be found.', 'directorist' ), [ 'status' => 409 ] );
        }
        $request = new WP_REST_Request( 'POST' );
        $request->set_param( 'element_id', $element['id'] );
        $request->set_param( 'live_chat', '1' );
        if ( ! helpgent_element_handler( 'live_chat' )->save_answer( $request, $element, (int) $response->id, $form ) ) {
            return new WP_Error( 'dhgi_close_failed', __( 'The chat could not be ended.', 'directorist' ), [ 'status' => 500 ] );
        }
        $repository->update_updated_at( (int) $response->id );
        $token = helpgent_get_response_token( (int) $response->id, (int) $form->id );
        do_action(
            'helpgent_wax_live_publish_signal', (string) $token, 'live_chat_answer_submitted', [
                'responseToken' => $token,
                'data' => [ 'value' => '1', 'created_at' => current_time( 'mysql', true ) ],
            ]
        );
        return $this->live_chat_state( $response, $form, true );
    }

    public function mark_read( int $response_id, array $cursors ): void {
        global $wpdb;
        foreach ( [ 'submission' => 'helpgent_responses', 'chat' => 'helpgent_chat_messages', 'message' => 'helpgent_messages' ] as $type => $table ) {
            $last_id = absint( $cursors[$type] ?? 0 );
            if ( ! $last_id ) {
                continue;
            }
            $maximum = 'submission' === $type
                ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT is_completed FROM {$wpdb->prefix}helpgent_responses WHERE id = %d", $response_id ) ) )
                : absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT MAX(m.id) FROM {$wpdb->prefix}{$table} m INNER JOIN {$wpdb->prefix}helpgent_responses r ON r.id = m.response_id WHERE m.response_id = %d AND m.created_by = r.created_by AND m.status = 'publish'",
                            $response_id
                        )
                    )
                );
            $value = min( $last_id, $maximum );
            $key = 'dhgi_read_' . $type . '_' . $response_id;
            for ( $attempt = 0; $attempt < 3; ++$attempt ) {
                $previous = get_user_meta( get_current_user_id(), $key, true );
                if ( $value <= absint( $previous ) ) {
                    break;
                }
                $saved = '' === $previous
                    ? add_user_meta( get_current_user_id(), $key, $value, true )
                    : update_user_meta( get_current_user_id(), $key, $value, $previous );
                if ( $saved ) {
                    break;
                }
            }
        }
    }

    public function prepare_chat_message( object $message ): object {
        $relation = Database::get_by_response( absint( $message->response_id ?? 0 ) );
        if ( ! $relation || empty( $message->created_by ) ) {
            return $message;
        }
        $repository = helpgent_singleton( \HelpGent\App\Repositories\ResponseRepository::class );
        if ( '1' === $repository->get_meta_value( (int) $message->response_id, 'directorist_owner_chat_message_' . absint( $message->id ) ) ) {
            $message->is_agent_message = true;
            return $message;
        }
        $response = $repository->get_by_id( (int) $message->response_id );
        if ( ! $response || absint( $response->created_by ) === absint( $message->created_by ) ) {
            return $message;
        }
        global $wpdb;
        $registered_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT registered_id FROM {$wpdb->prefix}helpgent_users WHERE id = %d", $message->created_by ) ) );
        if ( $registered_id && $registered_id === $this->owner_id( $relation ) ) {
            $message->is_agent_message = true;
        }
        return $message;
    }
}
