<?php
/**
 * Submission Adapter for submission adapter.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Listings;

use Directorist\Fields\Fields;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Submission Adapter. */
class Submission_Adapter extends \Directorist\AddListingForm\SubmissionController {
    /**
     * Validate only.
     *
     * @param array $data Data.
     * @param mixed $directory Directory.
     */
    public static function validate_only( array $data, $directory ) {
        // MCP resolves attachment IDs itself. The API mode moves/deletes temporary
        // files, whereas the ordinary native mode stores the validated URL.
        self::$from                = 'mcp';
        self::$selected_categories = null;
        Validation::unwrap( parent::validate_terms_and_conditions( $directory, $data ) );
        parent::cache_selected_categories( $directory, $data );
        $listing_id  = (int) ( $data['listing_id'] ?? 0 );
        $form_fields = Validation::unwrap( parent::get_submission_form_fields( $directory, $listing_id, $data ) );
        $errors      = [];
        foreach ( $form_fields as $props ) {
            $field = Fields::create( $props );
            if ( $field->is_admin_only() ) {
                continue;
            }
            $result = parent::validate_field( $field, $data );
            if ( ! $result['is_valid'] ) {
                $errors[ $field->get_key() ] = $result['message'];
            }
        }
        if ( $errors ) {
            Validation::fail( 'validation_failed', __( 'Some listing fields are invalid.', 'directorist' ), [ 'field_errors' => $errors ] );
        }
    }

    /**
     * Apply.
     *
     * @param array $data Data.
     */
    public static function apply( array $data ) {
        self::$selected_categories = null;
        return parent::submit( $data, 'mcp' );
    }
}
