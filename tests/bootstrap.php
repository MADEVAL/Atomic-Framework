<?php
declare(strict_types=1);

/**
 * PHPUnit Bootstrap for Atomic Framework
 *
 * Defines the ATOMIC_START constant to allow engine files to load,
 * and requires the Composer autoloader.
 */

define('ATOMIC_START', microtime(true));

require_once __DIR__ . '/../vendor/autoload.php';
