<?php
declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\ID;
use Tests\Support\ReflectionHelper;

final class TestConfig
{
    public static function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        static $env = null;
        if ($env === null) {
            $env = [];
            $paths = [];
            if (defined('ATOMIC_ENV') && is_string(ATOMIC_ENV) && ATOMIC_ENV !== '') {
                $paths[] = ATOMIC_ENV;
            }
            $paths[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

            foreach (array_unique($paths) as $path) {
                if (!is_file($path)) {
                    continue;
                }

                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$env_key, $env_value] = explode('=', $line, 2);
                    $env[trim($env_key)] = trim(explode('#', $env_value, 2)[0]);
                }

                break;
            }
        }

        return array_key_exists($key, $env) && $env[$key] !== '' ? $env[$key] : $default;
    }

    public static function db(array $overrides = []): array
    {
        return $overrides + [
            'driver' => (string)self::env('DB_DRIVER', 'mysql'),
            'host' => (string)self::env('DB_HOST', '127.0.0.1'),
            'port' => (string)self::env('DB_PORT', '3306'),
            'db' => (string)self::env('DB_DB', 'atomic_test'),
            'username' => (string)self::env('DB_USERNAME', 'atomic_test_user'),
            'password' => (string)self::env('DB_PASSWORD', 'atomic_test_pass'),
            'unix_socket' => (string)self::env('DB_SOCKET', ''),
            'charset' => (string)self::env('DB_CHARSET', 'utf8mb4'),
            'collation' => (string)self::env('DB_COLLATION', 'utf8mb4_general_ci'),
            'prefix' => (string)self::env('DB_PREFIX', 'atomic_'),
        ];
    }

    public static function redis(array $overrides = []): array
    {
        return $overrides + [
            'host' => (string)self::env('REDIS_HOST', '127.0.0.1'),
            'port' => (int)self::env('REDIS_PORT', 6379),
            'password' => (string)self::env('REDIS_PASSWORD', ''),
            'db' => (int)self::env('REDIS_DB', 0),
            'prefix' => (string)self::env('REDIS_PREFIX', 'atomic_test:'),
        ];
    }

    public static function memcached(array $overrides = []): array
    {
        return $overrides + [
            'host' => (string)self::env('MEMCACHED_HOST', '127.0.0.1'),
            'port' => (int)self::env('MEMCACHED_PORT', 11211),
            'username' => (string)self::env('MEMCACHED_USERNAME', ''),
            'password' => (string)self::env('MEMCACHED_PASSWORD', ''),
            'prefix' => (string)self::env('MEMCACHED_PREFIX', 'atomic_test:'),
        ];
    }

    public static function cache(array $overrides = []): array
    {
        return $overrides + [
            'default' => (string)self::env('CACHE_DRIVER', 'folder'),
            'path' => (string)self::env('CACHE_PATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_cache'),
            'prefix' => (string)self::env('CACHE_PREFIX', 'atomic_test.'),
        ];
    }

    public static function apply(\Base $base, array $overrides = []): void
    {
        $base->set('DB_CONFIG', self::db($overrides['db'] ?? []));
        $base->set('REDIS', self::redis($overrides['redis'] ?? []));
        $base->set('MEMCACHED', self::memcached($overrides['memcached'] ?? []));
        $base->set('CACHE_CONFIG', self::cache($overrides['cache'] ?? []));

        if (($overrides['app_uuid'] ?? true) !== false) {
            $base->set('APP_UUID', is_string($overrides['app_uuid'] ?? null) ? $overrides['app_uuid'] : ID::uuid_v4());
        }

        App::instance($base);
        self::reset_managers();
    }

    public static function reset_managers(): void
    {
        ConnectionManager::instance()->close();
        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);
    }

    public static function open_configured_db(\Base $base): ?\DB\SQL
    {
        $cfg = (array)$base->get('DB_CONFIG');
        if (($cfg['driver'] ?? '') !== 'mysql' || !extension_loaded('pdo_mysql')) {
            return null;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['db'],
            $cfg['charset'],
        );
        $options = [];
        if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
            $options[\Pdo\Mysql::ATTR_INIT_COMMAND] = "SET NAMES '{$cfg['charset']}' COLLATE '{$cfg['collation']}'";
        }

        return new \DB\SQL($dsn, $cfg['username'], $cfg['password'], $options);
    }

    public static function ensure_options_table(\Base $base): void
    {
        $db = $base->get('DB');
        if (!$db instanceof \DB\SQL) {
            return;
        }

        $cfg = (array)$base->get('DB_CONFIG');
        $prefix = str_replace('`', '``', (string)($cfg['prefix'] ?? 'atomic_'));
        $charset = (string)($cfg['charset'] ?? 'utf8mb4');
        $collation = (string)($cfg['collation'] ?? 'utf8mb4_general_ci');

        $db->exec("
            CREATE TABLE IF NOT EXISTS `{$prefix}options` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` VARCHAR(128) NOT NULL,
                `key` VARCHAR(128) NOT NULL,
                `value` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `expired_at` DATETIME NULL,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ");
    }
}
