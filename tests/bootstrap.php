<?php
declare(strict_types=1);

/**
 * PHPUnit Bootstrap for Atomic Framework
 */

$framework_root = realpath(__DIR__ . '/..');
if ($framework_root === false) {
    throw new RuntimeException('Cannot resolve framework root from tests/bootstrap.php');
}

defined('ATOMIC_START') || define('ATOMIC_START', microtime(true));

defined('ATOMIC_VERSION') || define('ATOMIC_VERSION', '0.1.0-test');
defined('ATOMIC_NAME') || define('ATOMIC_NAME', 'Atomic Framework');

defined('ATOMIC_DIR') || define('ATOMIC_DIR', $framework_root);
defined('ATOMIC_ROOT') || define('ATOMIC_ROOT', ATOMIC_DIR);
defined('ATOMIC_ENV') || define('ATOMIC_ENV', ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env');
defined('ATOMIC_APP_ROUTES') || define('ATOMIC_APP_ROUTES', ATOMIC_DIR . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR);
defined('ATOMIC_CONFIG') || define('ATOMIC_CONFIG', ATOMIC_DIR . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
defined('ATOMIC_VENDOR') || define('ATOMIC_VENDOR', ATOMIC_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
defined('ATOMIC_FRAMEWORK') || define('ATOMIC_FRAMEWORK', ATOMIC_DIR . DIRECTORY_SEPARATOR);
defined('ATOMIC_ENGINE') || define('ATOMIC_ENGINE', ATOMIC_FRAMEWORK . 'engine' . DIRECTORY_SEPARATOR);
defined('ATOMIC_SUPPORT') || define('ATOMIC_SUPPORT', ATOMIC_ENGINE . 'Atomic' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR);
defined('ATOMIC_UPLOADS') || define('ATOMIC_UPLOADS', ATOMIC_DIR . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);

defined('ATOMIC_CACHE_ALL_PAGES') || define('ATOMIC_CACHE_ALL_PAGES', true);
defined('ATOMIC_CACHE_EXPIRE_TIME') || define('ATOMIC_CACHE_EXPIRE_TIME', 3600);

require_once ATOMIC_VENDOR . 'autoload.php';
require_once ATOMIC_SUPPORT . 'helpers.php';

$atomic = \Base::instance();

// Ensure LOGS / TEMP dirs exist before config (ConfigLoader may reference them)
$logs_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_logs' . DIRECTORY_SEPARATOR;
$temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_temp' . DIRECTORY_SEPARATOR;
if (!is_dir($logs_dir)) { @mkdir($logs_dir, 0777, true); }
if (!is_dir($temp_dir)) { @mkdir($temp_dir, 0777, true); }
$atomic->set('LOGS', $logs_dir);
$atomic->set('TEMP', $temp_dir);

Tests\Support\TestConfig::apply($atomic, ['app_uuid' => false]);
$db_config = $atomic->get('DB_CONFIG');
$db_host = $db_config['host'];
$db_port = $db_config['port'];
$db_name = $db_config['db'];
$db_user = $db_config['username'];
$db_password = $db_config['password'];
$db_charset = $db_config['charset'];
$db_collation = $db_config['collation'];
$db_prefix = $db_config['prefix'];

if (extension_loaded('pdo_mysql')) {
    try {
        $db = Tests\Support\TestConfig::open_configured_db($atomic);
        $atomic->set('DB', $db);

        $quoted_prefix = str_replace('`', '``', $db_prefix);
        $db->exec("
            CREATE TABLE IF NOT EXISTS `{$quoted_prefix}meta` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` VARCHAR(128) NOT NULL,
                `key` VARCHAR(128) NOT NULL,
                `value` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$db_charset} COLLATE={$db_collation}
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `{$quoted_prefix}options` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` VARCHAR(128) NOT NULL,
                `key` VARCHAR(128) NOT NULL,
                `value` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `expired_at` DATETIME NULL,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$db_charset} COLLATE={$db_collation}
        ");
    } catch (Throwable) {
        // DB-backed tests decide whether to skip when MySQL is unavailable.
    }
}

$atomic->set('DEBUG_MODE', 'true');
$atomic->set('DEBUG_LEVEL', 'debug');
$atomic->set('DEBUG', 3);
$atomic->set('HALT', false);
$atomic->set('QUIET', true);
