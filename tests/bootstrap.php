<?php
declare(strict_types=1);

/**
 * PHPUnit Bootstrap for Atomic Framework
 */

$frameworkRoot = realpath(__DIR__ . '/..');
if ($frameworkRoot === false) {
    throw new RuntimeException('Cannot resolve framework root from tests/bootstrap.php');
}

defined('ATOMIC_START') || define('ATOMIC_START', microtime(true));

defined('ATOMIC_VERSION') || define('ATOMIC_VERSION', '0.1.0-test');
defined('ATOMIC_NAME') || define('ATOMIC_NAME', 'Atomic Framework');

defined('ATOMIC_DIR') || define('ATOMIC_DIR', $frameworkRoot);
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
$logsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_logs' . DIRECTORY_SEPARATOR;
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_temp' . DIRECTORY_SEPARATOR;
if (!is_dir($logsDir)) { @mkdir($logsDir, 0777, true); }
if (!is_dir($tempDir)) { @mkdir($tempDir, 0777, true); }
$atomic->set('LOGS', $logsDir);
$atomic->set('TEMP', $tempDir);
$atomic->set('REDIS', [
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    'password' => (string) (getenv('REDIS_PASSWORD') ?: ''),
    'db' => (int) (getenv('REDIS_DB') ?: 0),
    'prefix' => getenv('REDIS_PREFIX') ?: 'atomic_test:',
]);
$atomic->set('MEMCACHED', [
    'host' => getenv('MEMCACHED_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('MEMCACHED_PORT') ?: 11211),
    'prefix' => getenv('MEMCACHED_PREFIX') ?: 'atomic_test:',
]);
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_DB') ?: 'atomic_test';
$dbUser = getenv('DB_USERNAME') ?: 'atomic_test_user';
$dbPassword = getenv('DB_PASSWORD') ?: 'atomic_test_pass';
$dbCharset = getenv('DB_CHARSET') ?: 'utf8mb4';
$dbCollation = getenv('DB_COLLATION') ?: 'utf8mb4_general_ci';
$dbPrefix = getenv('DB_PREFIX') ?: 'atomic_';

$atomic->set('DB_CONFIG', [
    'driver' => getenv('DB_DRIVER') ?: 'mysql',
    'host' => $dbHost,
    'port' => $dbPort,
    'db' => $dbName,
    'username' => $dbUser,
    'password' => $dbPassword,
    'unix_socket' => '',
    'charset' => $dbCharset,
    'collation' => $dbCollation,
    'prefix' => $dbPrefix,
]);

Engine\Atomic\Core\App::instance($atomic);

if (extension_loaded('pdo_mysql')) {
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);
        $options = [];
        if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
            $options[Pdo\Mysql::ATTR_INIT_COMMAND] = "SET NAMES '{$dbCharset}' COLLATE '{$dbCollation}'";
        }

        $db = new \DB\SQL($dsn, $dbUser, $dbPassword, $options);
        $atomic->set('DB', $db);

        $quotedPrefix = str_replace('`', '``', $dbPrefix);
        $db->exec("
            CREATE TABLE IF NOT EXISTS `{$quotedPrefix}meta` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` VARCHAR(128) NOT NULL,
                `key` VARCHAR(128) NOT NULL,
                `value` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$dbCharset} COLLATE={$dbCollation}
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `{$quotedPrefix}options` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` VARCHAR(128) NOT NULL,
                `key` VARCHAR(128) NOT NULL,
                `value` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `expired_at` DATETIME NULL,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$dbCharset} COLLATE={$dbCollation}
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
