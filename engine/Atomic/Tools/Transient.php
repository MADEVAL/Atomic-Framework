<?php
declare(strict_types=1);
namespace Engine\Atomic\Tools;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Core\CacheManager;

class Transient 
{
    public const DRIVER_REDIS = 'redis';
    public const DRIVER_MEMCACHED = 'memcached';
    public const DRIVER_DB = 'db';
    public const DRIVER_FOLDER = 'folder';
    private const DEFAULT_DRIVER_PRIORITY = [
        self::DRIVER_REDIS,
        self::DRIVER_MEMCACHED,
        self::DRIVER_DB,
        self::DRIVER_FOLDER,
    ];

    protected static function get_cache_driver(?string $driver = null): CacheStoreInterface {
        $cm = CacheManager::instance();
        
        if ($driver === null) {
            return $cm->store();
        }
        
        return match($driver) {
            self::DRIVER_REDIS     => $cm->redis(),
            self::DRIVER_MEMCACHED => $cm->memcached(),
            self::DRIVER_FOLDER    => $cm->folder(),
            self::DRIVER_DB        => $cm->db(),
            default                => $cm->store(),
        };
    }

    protected static function get_cache_prefix(CacheStoreInterface $cache, string $name): string {
        return 'transient.' . $name;
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
            return $cache->reset();
        }

        $cm = CacheManager::instance();
        $ok = false;

        try {
            $redis = $cm->redis();
            if ($redis->reset()) {
                $ok = true;
            }
        } catch (\RuntimeException) {
            // Redis extension unavailable - skip
        }

        try {
            $memcached = $cm->memcached();
            if ($memcached->reset()) {
                $ok = true;
            }
        } catch (\RuntimeException) {
            // Memcached extension unavailable - skip
        }

        try {
            $db = $cm->db();
            if ($db->reset()) {
                $ok = true;
            }
        } catch (\Throwable) {
            // DB unavailable - skip
        }

        try {
            $folder = $cm->folder();
            if ($folder->reset()) {
                $ok = true;
            }
        } catch (\Throwable) {
            // Folder cache unavailable - skip
        }

        return $ok;
    }
}
