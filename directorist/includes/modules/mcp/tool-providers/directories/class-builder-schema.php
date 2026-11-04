<?php
/**
 * Builder Schema for directories.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Directories;

use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Tool_Providers\Settings\Settings_Schema;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Builder Schema. */
class Builder_Schema {
    /**
     * Schema for a top-level builder option.
     *
     * @param string $key Configuration key.
     * @param array $definition Native field definition.
     */
    public static function option_schema( string $key, array $definition ): ?array {
        if ( 'single_listing_page' === $key && 'select-api' === ( $definition['type'] ?? '' ) ) {
            return [ 'type' => 'integer', 'minimum' => 0 ];
        }
        return Settings_Schema::field_schema( $definition );
    }

    /**
     * Sections.
     *
     * @param array $config Config.
     */
    public static function sections( array $config ): array {
        $sections = [];
        foreach ( $config['groups'] ?? [] as $index => $group ) {
            $sections[] = [ 'id' => self::section_id( $group, $index ), 'label' => $group['label'] ?? '', 'fields' => $group['fields'] ?? [] ];
        }
        return $sections;
    }

    /**
     * Section id.
     *
     * @param array $group Group.
     * @param mixed $index Index.
     */
    public static function section_id( array $group, $index ): string {
        return (string) ( $group['id'] ?? $group['section_id'] ?? 'section-' . $index );
    }

    /**
     * Slots.
     *
     * @param array $config Config.
     * @param array $definition Registered descriptor.
     */
    public static function slots( array $config, array $definition ): array {
        $slots    = [];
        $header   = empty( $definition['card_templates'] );
        $template = $config['active_template'] ?? '';
        $layout   = $header ? ( $definition['layout'] ?? [] ) : ( $definition['card_templates'][ $template ]['layout'] ?? [] );
        $walk     = static function ( $node, $path ) use ( &$walk, &$slots, $config, $header ) {
            if ( isset( $node['acceptedWidgets'] ) ) {
                if ( $header ) {
                    $path[] = 'selectedWidgets';
                }
                $value = $config;
                foreach ( $path as $segment ) {
                    $value = $value[ $segment ] ?? [];
                }
                $slots[] = [ 'path' => $path, 'widgets' => array_column( $value, 'widget_key' ), 'accepted_widgets' => $node['acceptedWidgets'], 'max_widgets' => (int) ( $node['maxWidget'] ?? 0 ) ];
                return;
            }
            foreach ( $node as $key => $value ) {
                if ( is_array( $value ) ) {
                    $walk( $value, array_merge( $path, [ $key ] ) );
                }
            }
        };
        $walk( $layout, $header ? [] : [ 'template_data', $template ] );
        return $slots;
    }

    /**
     * Placement.
     *
     * @param array $slot Slot.
     * @param array $constraint Constraint.
     * @param array $widget Widget.
     */
    public static function placement( array $slot, array $constraint, array $widget ): void {
        Validation::check( ! $constraint['accepted_widgets'] || in_array( $widget['widget_name'], $constraint['accepted_widgets'], true ), __( 'This widget is not allowed in the selected slot.', 'directorist' ) );
        Validation::check( ! $constraint['max_widgets'] || count( $slot ) < $constraint['max_widgets'], __( 'This widget slot is full.', 'directorist' ) );
        Validation::check( ! in_array( $widget['widget_key'], array_column( $slot, 'widget_key' ), true ), __( 'Duplicate widget in this slot.', 'directorist' ) );
    }

    /**
     * Widgets.
     *
     * @param array $definition Registered descriptor.
     */
    public static function widgets( array $definition ): array {
        $widgets = [];
        $walk    = static function ( $node, $group = '' ) use ( &$walk, &$widgets ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['widgets'] ) && is_array( $node['widgets'] ) ) {
                foreach ( $node['widgets'] as $name => $widget ) {
                    if ( ! is_array( $widget ) ) {
                        continue;
                    }
                    if ( isset( $widget['widgets'] ) ) {
                        $walk( $widget, $name );
                    } else {
                        $widgets[ $name ] = $widget + [ 'widget_group' => $group, 'canTrash' => true ];
                    }
                }
            }
            foreach ( $node['card_templates'] ?? [] as $template ) {
                $walk( $template, $group );
            }
        };
        $walk( $definition );
        $adapters = Tool_Registry::instance()->fields( 'builder', $definition );
        foreach ( $widgets as $name => &$widget ) {
            $widget['writable'] = in_array( $name, self::core_widgets(), true );
            if ( isset( $adapters[ $name ] ) && ! $widget['writable'] ) {
                $definition                     = $adapters[ $name ];
                $widget['writable']             = ! empty( $definition['writable'] ) && true === call_user_func( $definition['permission'], $definition, 'write' );
                $widget['mcp_owner']            = $definition['owner'];
                $widget['mcp_schema']           = $definition['schema'];
                $widget['mcp_contract_version'] = $definition['contract_version'];
            }
        }
        return $widgets;
    }

    /** Core widgets. */
    public static function core_widgets(): array {
        return apply_filters( 'directorist_mcp_builder_widget_allowlist', [ 'title', 'description', 'excerpt', 'tagline', 'address', 'map', 'phone', 'phone2', 'fax', 'email', 'website', 'social_info', 'video', 'pricing', 'category', 'location', 'tag', 'image_upload', 'hide_contact_owner', 'text', 'textarea', 'number', 'url', 'date', 'time', 'color', 'select', 'checkbox', 'radio', 'file', 'switch', 'back', 'slider', 'bookmark', 'share', 'report', 'price', 'badges', 'ratings_count', 'button', 'listing_title', 'rating', 'featured_badge', 'new_badge', 'popular_badge', 'favorite_badge', 'listings_location', 'posted_date', 'view_count', 'user_avatar', 'search', 'search_button', 'reset_button', 'submit_button', 'listing_content', 'contact_info', 'contact_owner', 'related_listings', 'review', 'author_info' ] );
    }

    /** Conditional schema. */
    public static function conditional_schema(): array {
        $condition = [
            'type'                 => 'object',
            'required'             => [ 'field', 'operator' ],
            'additionalProperties' => false,
            'properties'           => [
                'field'    => [ 'type' => 'string', 'minLength' => 1 ],
                'operator' => [ 'type' => 'string' ],
                'value'    => [ 'type' => [ 'string', 'number', 'boolean', 'array', 'null' ] ],
            ],
        ];
        $group     = [
            'type'                 => 'object',
            'required'             => [ 'conditions' ],
            'additionalProperties' => false,
            'properties'           => [
                'operator'   => [ 'type' => 'string', 'enum' => [ 'AND', 'OR' ] ],
                'conditions' => [ 'type' => 'array', 'maxItems' => 50, 'items' => $condition ],
            ],
        ];
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => [ 'enabled', 'action', 'groups' ],
            'properties'           => [
                'enabled'        => [ 'type' => 'boolean' ],
                'action'         => [ 'type' => 'string', 'enum' => [ 'show', 'hide' ] ],
                'globalOperator' => [ 'type' => 'string', 'enum' => [ 'AND', 'OR' ] ],
                'groups'         => [ 'type' => 'array', 'maxItems' => 50, 'items' => $group ],
            ],
        ];
    }
}
