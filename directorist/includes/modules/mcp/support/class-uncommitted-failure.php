<?php
/**
 * A writer failure proven to precede product persistence.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Support;

defined( 'ABSPATH' ) || exit;

/** Trusted writers may release their reservation only before any product write. */
class Uncommitted_Failure extends Failure {}
