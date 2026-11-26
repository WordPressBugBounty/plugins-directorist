<?php
defined( 'ABSPATH' ) || exit;

\Directorist\Order\Mutation::register_hooks();
\Directorist\Order\Actor::register_hooks();
\Directorist\Order\ActivityMigration::register_hooks();
