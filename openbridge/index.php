<?php

declare(strict_types=1);

/**
 * Stable PHP include entry point for OpenBridge.
 *
 * Usage:
 *   require_once __DIR__ . '/../OpenBridge/index.php';
 *   $bridge = new \restCWP\restCWP($host, $database, $layout, $user, $password);
 */
require_once dirname(__DIR__) . '/restCWP/restCWP.php';
require_once dirname(__DIR__) . '/restCWP/odataCWP.php';
