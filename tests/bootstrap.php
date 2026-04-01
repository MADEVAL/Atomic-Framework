<?php
declare(strict_types=1);

/**
 * PHPUnit Bootstrap for Atomic Framework
 *
 * Mirrors bootstrap/app.php but only chains the methods
 * safe for a test environment (no session, no DB, no routes).
 */

define('ATOMIC_START', microtime(true));
define('ATOMIC_ROOT', __DIR__ . '/../bootstrap');

// ── Load framework core exactly like bootstrap/app.php ──
require_once ATOMIC_ROOT . DIRECTORY_SEPARATOR . 'const.php';
require_once ATOMIC_ROOT . DIRECTORY_SEPARATOR . 'error.php';
require_once ATOMIC_VENDOR . 'autoload.php';
require_once ATOMIC_SUPPORT . 'helpers.php';

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Config\ConfigLoader;

$atomic = \Base::instance();

// Ensure LOGS / TEMP dirs exist before config (ConfigLoader may reference them)
$logsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_logs' . DIRECTORY_SEPARATOR;
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_temp' . DIRECTORY_SEPARATOR;
if (!is_dir($logsDir)) { @mkdir($logsDir, 0777, true); }
if (!is_dir($tempDir)) { @mkdir($tempDir, 0777, true); }
$atomic->set('LOGS', $logsDir);
$atomic->set('TEMP', $tempDir);

// Load .env - same as app.php default loader
ConfigLoader::init($atomic, ATOMIC_ENV);

// ── Boot Atomic through its own App class ──
// Safe chain: prefly → logger → locales → middleware
// Skipped: registerExceptionHandler (die on error),
//          registerRoutes, registerPlugins, initSession, setDB
$application = App::instance($atomic)
    ->prefly()
    ->registerLogger()
    ->registerLocales()
    ->registerMiddleware();

// ── Test-specific overrides ──
$atomic->set('DEBUG_MODE', 'true');
$atomic->set('DEBUG_LEVEL', 'debug');
$atomic->set('DEBUG', 3);
$atomic->set('LOGS', $logsDir);   // restore after ConfigLoader may have overwritten
$atomic->set('HALT', false);      // don't die() on errors during tests
$atomic->set('QUIET', true);      // suppress F3 error output
