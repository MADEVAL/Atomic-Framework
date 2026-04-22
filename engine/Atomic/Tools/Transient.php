<?php
declare(strict_types=1);
namespace Engine\Atomic\Tools;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Cache\DB;
use Engine\Atomic\Cache\Memcached;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\Log;

class Transient 
{
    public const DRIVER_REDIS = 'redis';
    public const DRIVER_MEMCACHED = 'memcached';
    public const DRIVER_DB = 'db';

    protected static function get_cache_driver(?string $driver = null): \Cache|DB|Memcached {
        $cm = CacheManager::instance();
        
        if ($driver === null) {
            return $cm->cascade();
        }
        
        return match($driver) {
            self::DRIVER_REDIS     => $cm->redis(),
            self::DRIVER_MEMCACHED => $cm->memcached(),
            self::DRIVER_DB        => $cm->db(),
            default                => $cm->cascade(),
        };
    }

    protected static function get_cache_prefix(\Cache|DB|Memcached $cache, string $name): string {
        $atomic = App::instance();
        if ($cache instanceof DB) {
            return 'transient_' . $name;
        }
        return $atomic->get('REDIS.prefix') . 'transient.' . $name;
    }

    public static function set(string $name, mixed $value, int $expiration, ?string $driver = null): bool {
        if ($expiration <= 0) {
            throw new \InvalidArgumentException(
                'Transient::set() requires TTL > 0 (got: ' . $expiration . '). '
                . 'Options should be used as non-tmp storage.'
            );
        }
        $cache = self::get_cache_driver($driver);
        return (bool) $cache->set(self::get_cache_prefix($cache, $name), $value, $expiration);
    }

    public static function get(string $name, ?string $driver = null): mixed {
        $cache = self::get_cache_driver($driver);
        return $cache->get(self::get_cache_prefix($cache, $name));
    }

    public static function delete(string $name, ?string $driver = null): bool {
        $cache = self::get_cache_driver($driver);
        $cache->clear(self::get_cache_prefix($cache, $name));
        return true;
    }

    public static function delete_all(?string $driver = null): bool {
        if ($driver !== null) {
            $cache = self::get_cache_driver($driver);
            $suffix = ($driver === self::DRIVER_REDIS) ? '*' : '';
            return $cache->reset(self::get_cache_prefix($cache, '') . $suffix);
        }

        $cm = CacheManager::instance();
        $ok = false;

        try {
            $redis = $cm->redis();
            if ($redis->reset(self::get_cache_prefix($redis, '') . '*')) {
                $ok = true;
            }
        } catch (\RuntimeException) {
            // Redis extension unavailable - skip
        }

        try {
            $memcached = $cm->memcached();
            if ($memcached->reset(self::get_cache_prefix($memcached, ''))) {
                $ok = true;
            }
        } catch (\RuntimeException) {
            // Memcached extension unavailable - skip
        }

        try {
            $db = $cm->db();
            if ($db->reset(self::get_cache_prefix($db, ''))) {
                $ok = true;
            }
        } catch (\Throwable) {
            // DB unavailable - skip
        }

        return $ok;
    }
}