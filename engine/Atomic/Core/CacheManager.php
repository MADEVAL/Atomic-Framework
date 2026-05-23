<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Cache\Drivers\DB;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Drivers\Folder;
use Engine\Atomic\Cache\Drivers\Memcached;
use Engine\Atomic\Cache\Drivers\Redis as RedisCache;

class CacheManager extends \Prefab
{
    public const FAT_FREE_CACHE_BRIDGE_SENTINEL = 'atomic';

    private const DRIVER_REDIS = 'redis';
    private const DRIVER_MEMCACHED = 'memcached';
    private const DRIVER_FOLDER = 'folder';
    private const DRIVER_DB = 'db';
    private const SUPPORTED_DRIVERS = [
        self::DRIVER_REDIS,
        self::DRIVER_MEMCACHED,
        self::DRIVER_FOLDER,
        self::DRIVER_DB,
    ];
    private const DRIVER_PRIORITY = [
        self::DRIVER_REDIS,
        self::DRIVER_MEMCACHED,
        self::DRIVER_FOLDER,
    ];
    
    public static function supports_driver(string $driver): bool
    {
        return in_array(strtolower(trim($driver)), self::SUPPORTED_DRIVERS, true);
    }

    protected array $hive = [];
    protected ?CacheStoreInterface $store = null;

    public function redis(): RedisCache
    {
        if (isset($this->hive[self::DRIVER_REDIS])) {
            return $this->hive[self::DRIVER_REDIS];
        }

        if (!extension_loaded(self::DRIVER_REDIS)) {
            throw new \RuntimeException('The redis PHP extension is not loaded.');
        }

        $atomic = App::instance();
        try {
            $redis = ConnectionManager::instance()->get_redis();
            $namespace = (string)$atomic->get('REDIS.prefix');
            $cache = new RedisCache($redis, $namespace);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Redis cache unavailable: ' . $e->getMessage(), 0, $e);
        }

        $this->hive[self::DRIVER_REDIS] = $cache;

        return $cache;
    }


    public function db(): DB {
        if (isset($this->hive[self::DRIVER_DB])) {
            return $this->hive[self::DRIVER_DB];
        }
        $this->hive[self::DRIVER_DB] = new DB();
        return $this->hive[self::DRIVER_DB];
    }

    public function folder(): Folder
    {
        if (isset($this->hive[self::DRIVER_FOLDER])) {
            return $this->hive[self::DRIVER_FOLDER];
        }

        $config = $this->cache_config(App::instance());
        $path = (string)($config['path'] ?? '');
        $namespace = (string)($config['prefix'] ?? '');

        $this->hive[self::DRIVER_FOLDER] = new Folder($path, $namespace);
        return $this->hive[self::DRIVER_FOLDER];
    }

    public function memcached(): Memcached
    {
        if (isset($this->hive[self::DRIVER_MEMCACHED])) {
            return $this->hive[self::DRIVER_MEMCACHED];
        }

        if (!extension_loaded(self::DRIVER_MEMCACHED)) {
            throw new \RuntimeException('The memcached PHP extension is not loaded.');
        }

        $atomic = App::instance();

        try {
            $mc = ConnectionManager::instance()->get_memcached();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Memcached cache unavailable: ' . $e->getMessage(), 0, $e);
        }

        $namespace = (string)$atomic->get('MEMCACHED.prefix');
        $cache = new Memcached($mc, $namespace);

        $this->hive[self::DRIVER_MEMCACHED] = $cache;

        return $cache;
    }

    public function cascade(): CacheStoreInterface
    {
        $drivers = $this->cascade_drivers();
        foreach ($drivers as $driver) {
            try {
                $cache = $this->driver($driver);

                if ($cache !== null) {
                    $testKey = '_atomic_healthcheck';
                    $cache->set($testKey, '1', 3);
                    $val = $cache->get($testKey);
                    if ($val == '1') {
                        return $cache;
                    }
                }
            } catch (\Throwable $e) {
                Log::error(ucfirst($driver) . ' health check error: ' . $e->getMessage());
            }
        }

        return $this->folder();
    }

    public function resolve(): CacheStoreInterface
    {
        return $this->store = $this->cascade();
    }

    public function store(): CacheStoreInterface
    {
        return $this->store ?? $this->resolve();
    }

    public function configured(): ?CacheStoreInterface
    {
        $driver = $this->configured_driver(App::instance());

        return $driver !== null ? $this->driver($driver) : null;
    }

    protected function driver(string $driver): ?CacheStoreInterface
    {
        return match ($driver) {
            self::DRIVER_REDIS => extension_loaded(self::DRIVER_REDIS) ? $this->redis() : null,
            self::DRIVER_MEMCACHED => extension_loaded(self::DRIVER_MEMCACHED) ? $this->memcached() : null,
            self::DRIVER_FOLDER => $this->folder(),
            self::DRIVER_DB => $this->db(),
            default => null,
        };
    }

    protected function cascade_drivers(): array
    {
        $configured = $this->configured_driver(App::instance());
        $drivers = $configured !== null ? [$configured] : [];

        foreach (self::DRIVER_PRIORITY as $driver) {
            if (!in_array($driver, $drivers, true)) {
                $drivers[] = $driver;
            }
        }

        return $drivers;
    }

    private function configured_driver(object $atomic): ?string
    {
        $config = $this->cache_config($atomic);
        $raw_driver = $config['default'] ?? null;
        $driver = is_scalar($raw_driver) ? strtolower(trim((string)$raw_driver)) : '';

        return in_array($driver, self::SUPPORTED_DRIVERS, true) ? $driver : null;
    }

    private function cache_config(object $atomic): array
    {
        $config = $atomic->get('CACHE_CONFIG');

        return is_array($config) ? $config : [];
    }
}
