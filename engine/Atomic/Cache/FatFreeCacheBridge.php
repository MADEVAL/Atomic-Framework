<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;

class FatFreeCacheBridge extends \Cache
{
    private bool $enabled = false;
    private ?CacheStoreInterface $store = null;

    public static function install(): self
    {
        $cache = new self();
        \Registry::set(\Cache::class, $cache);

        return $cache;
    }

    public function exists($key, &$val = null): array|false
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->store->exists((string)$key, $val);
    }

    public function set($key, $val, $ttl = 0): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $key = (string)$key;
        $cached = $this->store->exists($key);

        return $this->store->set($key, $val, $cached !== false ? (int)$cached[1] : (int)$ttl);
    }

    public function get($key): mixed
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->store->get((string)$key);
    }

    public function clear($key): ?bool
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->store->clear((string)$key);
    }

    public function reset($suffix = null, $ttl = 0): bool
    {
        if (!$this->enabled) {
            return true;
        }

        /*
         * F3's native cache can delete only keys ending in a suffix, for example
         * reset('.@') for F3's cache-backed Session GC. Atomic cache adapters do
         * not expose suffix/prefix deletion because it cannot be implemented with
         * the same guarantees across Redis, Memcached, DB, and folder stores.
         *
         * Atomic's own sessions use Engine\Atomic\Session drivers instead of F3's
         * cache-backed Session class, so ignoring suffix resets preserves F3
         * compatibility without letting an F3-specific cleanup remove unrelated
         * Atomic cache entries. A reset without suffix still invalidates the whole
         * configured Atomic cache namespace.
         */
        if ($suffix !== null) {
            return true;
        }

        return $this->store->reset();
    }

    public function load($dsn, $seed = null): bool|string
    {
        /*
         * F3's native Cache::load($dsn, $seed) treats $seed as the cache key
         * prefix, falling back to Base::SEED when no seed is passed. Atomic must
         * not let that runtime argument change the configured application cache
         * namespace. This bridge only enables for Atomic's sentinel value; any
         * native F3 DSN such as "folder=..." or "redis=..." disables the bridge
         * instead of being interpreted or redirected. When enabled, the bridge
         * resolves one Atomic store for this cache instance from CacheManager's
         * configured driver first, where CACHE_PREFIX is applied by the selected
         * Atomic adapter.
         */
        $this->dsn = is_string($dsn) ? trim($dsn) : (bool)$dsn;
        $this->enabled = $this->dsn === CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL;
        $this->store = $this->enabled ? CacheManager::instance()->store() : null;

        return $this->enabled ? $this->dsn : false;
    }

    public function __construct($dsn = false)
    {
        if ($dsn !== false) {
            $this->load($dsn);
        }
    }

}
