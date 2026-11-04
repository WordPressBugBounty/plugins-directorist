<?php
/**
 * Builder Validator for directories.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Directories;

use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Builder Validator. */
class Builder_Validator {
    /**
     * Validate configuration.
     *
     * @param array $before Before.
     * @param array $after After.
     */
    public static function validate_configuration( array $before, array $after ): void {
        $form   = $after['submission_form_fields'];
        $fields = $form['fields'] ?? [];
        $keys   = array_column( $fields, 'field_key' );
        Validation::check( count( $keys ) === count( array_unique( $keys ) ), __( 'Field storage keys must be unique.', 'directorist' ) );
        foreach ( $fields as $field ) {
            if ( 'title' === ( $field['widget_name'] ?? '' ) ) {
                Validation::check( ! empty( $field['required'] ), __( 'The listing title must remain required.', 'directorist' ) );
            }
        }
        $references = [];
        foreach ( $form['groups'] ?? [] as $group ) {
            $references = array_merge( $references, $group['fields'] ?? [] );
        }
        Validation::check( ! array_diff( array_keys( $fields ), $references ) && ! array_diff( $references, array_keys( $fields ) ) && count( $references ) === count( array_unique( $references ) ), __( 'Every submission field must belong to exactly one section.', 'directorist' ) );
        $graph = [];
        foreach ( $fields as $id => $field ) {
            $rules = $field['conditional_logic']['groups'] ?? [];
            foreach ( $rules as $group ) {
                foreach ( $group['conditions'] ?? [] as $condition ) {
                    $ref      = $condition['field'] ?? '';
                    $target   = array_search( $ref, array_column( $fields, 'field_key' ), true );
                    $field_id = isset( $fields[ $ref ] ) ? $ref : ( false === $target ? null : array_keys( $fields )[ $target ] );
                    Validation::check( null !== $field_id, __( 'Conditional rules must reference an existing field.', 'directorist' ) );
                    Validation::check( in_array( $condition['operator'] ?? '', [ 'is', '==', '=', 'is not', '!=', 'not', 'contains', 'does not contain', 'greater than', '>', 'less than', '<', 'greater than or equal', '>=', 'less than or equal', '<=', 'starts with', 'ends with', 'empty', 'not empty', 'is empty', 'is not empty' ], true ), __( 'Unsupported conditional operator.', 'directorist' ) );
                    $graph[ $id ][] = $field_id;
                }
            }
        }
        $visit = static function ( $id, $path ) use ( &$visit, $graph ) {
            Validation::check( ! in_array( $id, $path, true ), __( 'Conditional rules contain a cycle.', 'directorist' ) );
            foreach ( $graph[ $id ] ?? [] as $child ) {
                $visit( $child, array_merge( $path, [ $id ] ) );
            }
        };
        foreach ( array_keys( $graph ) as $id ) {
            $visit( $id, [] );
        }
        $before_fields = $before['submission_form_fields']['fields'] ?? [];
        $removed       = array_diff( array_merge( array_keys( $before_fields ), array_column( $before_fields, 'field_key' ) ), array_merge( array_keys( $fields ), $keys ) );
        $legacy        = [];
        $collect       = static function ( $node ) use ( &$collect, &$legacy ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['original_widget_key'] ) ) {
                $legacy[] = $node['original_widget_key'];
            }
            foreach ( $node as $child ) {
                $collect( $child );
            }
        };
        $collect( $before );
        $available = array_merge( array_keys( $fields ), $keys, $legacy );
        $walk      = static function ( $node ) use ( &$walk, $removed, $available ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['original_widget_key'] ) ) {
                Validation::check( ! in_array( $node['original_widget_key'], $removed, true ), __( 'A search or layout widget still references a removed field.', 'directorist' ), 'dependency_blocked' );
            }
            if ( isset( $node['original_widget_key'] ) ) {
                Validation::check( in_array( $node['original_widget_key'], $available, true ), __( 'Widget references an unknown submission field.', 'directorist' ) );
            }
            foreach ( $node as $child ) {
                $walk( $child );
            }
        };
        foreach ( array_diff_key( $after, [ 'submission_form_fields' => true ] ) as $value ) {
            $walk( $value );
        }
    }
}
