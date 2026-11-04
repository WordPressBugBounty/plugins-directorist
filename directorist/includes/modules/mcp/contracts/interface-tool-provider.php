<?php
/**
 * Feature-owned tool registration contract.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Contracts;

defined( 'ABSPATH' ) || exit;

/** Tool metadata and executable bindings are deliberately separate. */
interface Tool_Provider {
    /** Return public, fingerprinted tool descriptors. */
    public static function definitions(): array;

    /**
     * Return internal callbacks indexed by action name.
     *
     * @return array<string, callable(array): array> Structured reads or mutation-plan factories.
     */
    public static function callbacks(): array;

    /**
     * Enforce feature gates before or after restrictive extension policies.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     * @param string $phase Authorization phase around restrictive extension policies.
     */
    public static function authorize( string $tool, array $args, string $phase ): void;

    /**
     * Check feature discovery eligibility without granting execution access.
     *
     * @param string $tool Unprefixed tool action name.
     */
    public static function discover( string $tool ): bool;
}
