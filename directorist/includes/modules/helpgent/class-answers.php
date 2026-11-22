<?php
namespace Directorist\Integrations\HelpGent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Throwable;

/** Safe presentation data for HelpGent's existing answer components. */
defined( 'ABSPATH' ) || exit;

final class Answers {
    public static function standalone_contact( array $answers, string $form_content ): array {
        $contact = [];
        foreach ( $answers as $answer ) {
            $value = trim( $answer['value'] );
            if ( 'email' === $answer['type'] && ! isset( $contact['email'] ) && is_email( $value ) ) {
                $contact['email'] = $value;
            } elseif ( 'phone_number' === $answer['type'] && ! isset( $contact['phone'] ) && preg_match( '/^(?=.*[0-9])[0-9+(). -]+$/D', $value ) ) {
                $contact['phone'] = $value;
            }
        }

        if ( ! $contact ) {
            return [];
        }

        $form = helpgent_migrate_old_form( (object) [ 'content' => $form_content ], false );
        foreach ( $form->content['elements'] ?? [] as $element ) {
            if ( '1' === (string) ( $element['attributes']['collect_contact_details'] ?? '0' ) ) {
                return [];
            }
        }

        return $contact;
    }

    public static function prepare( object $answer, bool $unlocked ): array {
        $type = sanitize_key( (string) ( $answer->element_type ?? '' ) );
        $attachments = array_filter( array_merge( [ $answer->attachment ?? null, $answer->open_ended_text_inners->attachment ?? null ], (array) ( $answer->attachments ?? [] ) ) );
        $protected = ! $unlocked && ( $attachments || in_array( $type, [ 'address', 'contact_info', 'email', 'phone_number', 'open_ended_text', 'map' ], true ) );
        $value = $answer->value ?? '';
        $result = [
            'label' => sanitize_text_field( (string) ( $answer->element_title ?? __( 'Answer', 'directorist' ) ) ),
            'type' => $type,
            'value' => $protected ? __( 'Protected until this lead is unlocked', 'directorist' ) : wp_strip_all_tags( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ),
            'media' => [],
            'display' => null,
        ];
        if ( $protected ) {
            return $result;
        }
        $display = [
            'id' => absint( $answer->id ?? 0 ),
            'element_type' => $type,
            'value' => in_array( $type, [ 'multi_select', 'picture_select' ], true ) ? array_map( 'sanitize_text_field', (array) $value ) : (string) $value,
        ];
        if ( ! in_array( $type, [ 'multi_select', 'picture_select', 'time', 'map' ], true ) ) {
            $display['value'] = esc_html( $display['value'] );
        }
        foreach ( [ 'rating_icon', 'rating_amount', 'max_value', 'min_value' ] as $key ) {
            if ( isset( $answer->$key ) ) {
                $display[$key] = sanitize_text_field( (string) $answer->$key );
            }
        }
        if ( isset( $answer->options ) ) {
            $display['options'] = array_map(
                static function( $option ) {
                    $option = (array) $option;
                    $item = [];
                    foreach ( [ 'id', 'label', 'value' ] as $key ) {
                        if ( isset( $option[$key] ) ) {
                            $item[$key] = sanitize_text_field( (string) $option[$key] );
                        }
                    }
                    if ( isset( $option['url'] ) ) {
                        $item['url'] = esc_url_raw( (string) $option['url'] );
                    }
                    return $item;
                }, (array) $answer->options
            );
        }
        if ( isset( $answer->labels ) ) {
            $display['labels'] = array_map( 'sanitize_text_field', (array) $answer->labels );
        }
        foreach ( [ 'address', 'contact_info', 'open_ended_text_inners' ] as $key ) {
            if ( empty( $answer->$key ) ) {
                continue;
            }
            $nested = (array) $answer->$key;
            $display[$key] = [];
            foreach ( array_keys( $display['labels'] ?? [] ) as $field ) {
                if ( isset( $nested[$field] ) && is_scalar( $nested[$field] ) ) {
                    $display[$key][$field] = sanitize_textarea_field( (string) $nested[$field] );
                }
            }
            if ( ! empty( $nested['attachment'] ) ) {
                $display[$key]['attachment'] = self::attachment( $nested['attachment'] );
            }
        }
        if ( ! empty( $answer->attachment ) ) {
            $display['attachment'] = self::attachment( $answer->attachment );
        }
        if ( isset( $answer->attachments ) ) {
            $display['attachments'] = array_map( [ self::class, 'attachment' ], (array) $answer->attachments );
        }
        foreach ( $attachments as $attachment ) {
            $file = self::attachment( $attachment );
            $result['media'][] = [ 'id' => $file['id'], 'title' => $file['title'], 'mimeType' => $file['mime_type'], 'url' => $file['url'] ];
        }
        $result['display'] = $display;
        return $result;
    }

    private static function attachment( $attachment ): array {
        $attachment = (object) $attachment;
        return [
            'id' => absint( $attachment->id ?? 0 ),
            'title' => sanitize_text_field( (string) ( $attachment->title ?? '' ) ),
            'mime_type' => sanitize_mime_type( (string) ( $attachment->mime_type ?? '' ) ),
            'url' => esc_url_raw( (string) ( $attachment->url ?? $attachment->file_url ?? '' ) ),
            'file_size' => absint( $attachment->file_size ?? 0 ),
        ];
    }
}
