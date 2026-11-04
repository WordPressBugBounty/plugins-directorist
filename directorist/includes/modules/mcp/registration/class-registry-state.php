<?php
/**
 * Request-local registry storage, shared only by registration collaborators.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Registration;

defined( 'ABSPATH' ) || exit;

/** Internal storage; never persisted or exposed to MCP clients. */
class Registry_State {
    /** @var array Registered tools. */
    public array $tools = [];

    /** @var array Registered extensions. */
    public array $extensions = [];

    /** @var array Registered fields. */
    public array $fields = [];

    /** @var array Registered read adapters. */
    public array $read_adapters = [];

    /** @var array Registered policies. */
    public array $policies = [];

    /** @var array Registered errors. */
    public array $errors = [];

    /** @var bool Whether registration is closed. */
    public bool $sealed = false;
}
