<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Drivers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PurgeableCacheStoreInterface;
use Engine\Atomic\Cache\Helpers\Payload;

class Redis implements CacheStoreInterface, PurgeableCacheStoreInterface
{
    private const ENTRY_PREFIX = 'entry';
    private const META_PREFIX = 'meta';

    private \Redis $redis;
    private string $namespace;
    private string $gen_key;
    private bool $valid_namespace;
    private ?int $cached_gen = null;

    private const INIT_GENERATION_SCRIPT = 'init_generation.lua';
    private const RESET_GENERATION_SCRIPT = 'reset_generation.lua';

    public function __construct(\Redis $redis, string $namespace = 'atomic')
    {
        $this->redis = $redis;
        $this->namespace = $this->normalize_namespace($namespace);
        $this->valid_namespace = $this->namespace !== '';
        $this->gen_key = $this->namespace . '.' . self::META_PREFIX . '.gen';
    }

    private function normalize_namespace(string $namespace): string
    {
        $namespace = rtrim(trim($namespace), '.');
        return $namespace !== '' ? $namespace : 'atomic';
    }

    private function normalize_key(string $key): string|false
    {
        $key = ltrim($key, '.');
        return $key !== '' ? $key : false;
    }

    private function real_key(string $key): string|false
    {
        if (!$this->valid_namespace) {
            return false;
        }

        $key = $this->normalize_key($key);
        if ($key === false) {
            return false;
        }

        return $this->namespace . '.' . self::ENTRY_PREFIX . '.' . $this->get_generation() . '.' . $key;
    }

    private function lua_script(string $name): string
    {
        $script = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'lua' . DIRECTORY_SEPARATOR . $name);
        if ($script === false) {
            throw new \RuntimeException('Redis cache Lua script not found: ' . $name);
        }

        return $script;
    }

    public function get_generation(): int
    {
        if (!$this->valid_namespace) {
            return 0;
        }

        if ($this->cached_gen !== null) {
            return $this->cached_gen;
        }

        $generation = $this->redis->eval($this->lua_script(self::INIT_GENERATION_SCRIPT), [$this->gen_key], 1);
        if ($generation === false) {
            throw new \RuntimeException('Redis cache generation initialization failed.');
        }

        return $this->cached_gen = (int)$generation;
    }

    public function flush_local_cache(): void
    {
        $this->cached_gen = null;
    }

    public function exists(string $key, mixed &$val = null): array|false
    {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        $raw = $this->redis->get($real_key);
        if ($raw === false) {
            return false;
        }

        $payload = Payload::unpack($raw);
        if ($payload === false || Payload::is_expired($payload)) {
            $this->redis->del($real_key);
            $val = null;
            return false;
        }

        $val = $payload['value'];
        return Payload::meta($payload);
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        $payload = Payload::pack($value, $ttl);
        
        if ($ttl > 0) $written = (bool)$this->redis->setex($real_key, $ttl, $payload);
        else $written = (bool)$this->redis->set($real_key, $payload);

        if (!$written) {
            throw new \RuntimeException('Redis cache key write failed.');
        }

        return true;
    }

    public function get(string $key): mixed
    {
        return $this->exists($key, $value) ? $value : false;
    }

    public function clear(string $key): bool
    {
        $real_key = $this->real_key($key);
        return $real_key !== false && $this->redis->del($real_key) > 0;
    }

    public function reset(): bool
    {
        if (!$this->valid_namespace) {
            return false;
        }

        $new_gen = $this->redis->eval($this->lua_script(self::RESET_GENERATION_SCRIPT), [$this->gen_key], 1);
        if ($new_gen === false) {
            throw new \RuntimeException('Redis cache generation reset failed.');
        }

        $this->cached_gen = (int)$new_gen;

        return true;
    }

    public function purge(): int
    {
        if (!$this->valid_namespace) {
            return 0;
        }

        $deleted_total = 0;
        $iterator = null;
        $entry_prefix = $this->namespace . '.' . self::ENTRY_PREFIX . '.';
        $pattern = $entry_prefix . '*';
        $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        while (($keys = $this->redis->scan($iterator, $pattern, 500)) !== false) {
            $keys = array_values(array_filter(
                (array) $keys,
                fn (string $key): bool => str_starts_with($key, $entry_prefix)
            ));

            if ($keys === []) {
                continue;
            }

            $deleted = $this->redis->del($keys);
            if ($deleted === false) {
                throw new \RuntimeException('Redis cache physical purge failed.');
            }

            $deleted_total += (int) $deleted;
        }

        $this->cached_gen = null;

        return $deleted_total;
    }

}
