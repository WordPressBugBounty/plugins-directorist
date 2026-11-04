<?php
/**
 * Represent a safe MCP failure without exposing internal exception details.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Support;

defined( 'ABSPATH' ) || exit;

/** Represent a safe MCP failure without exposing internal exception details. */
class Failure extends \RuntimeException {
    public $reason;

    public $details;

    /**
     * Create a structured failure for conversion into a safe WordPress error.
     *
     * @param mixed $reason Reason.
     * @param mixed $message Message.
     * @param array $details Details.
     */
    public function __construct( $reason, $message, array $details = [] ) {
        parent::__construct( $message );
        $this->reason  = $reason;
        $this->details = $details;
    }

    /** As error. */
    public function as_error(): \WP_Error {
        return new \WP_Error(
            'directorist_mcp_' . $this->reason, $this->getMessage(), array_merge(
                [
                    'code'      => $this->reason,
                    'retryable' => false,
                    'committed' => false,
                ], $this->details
            )
        );
    }
}
