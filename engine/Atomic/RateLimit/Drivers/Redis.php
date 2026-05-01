<?php
declare(strict_types=1);
namespace Engine\Atomic\RateLimit\Drivers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\RateLimit\RateLimitStoreInterface;

final class Redis implements RateLimitStoreInterface
{
    private const CONFIG_PREFIX = 'REDIS.prefix';
    private const DEFAULT_PREFIX = 'atomic:';
    private const KEY_PREFIX = 'rate_limit:';
    private const LUA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'lua';

    private \Redis $redis;
    private string $prefix;
    /** @var array<string, string> */
    private array $scripts = [];

    public function __construct(?\Redis $redis = null, ?string $prefix = null)
    {
        $this->redis = $redis ?? ConnectionManager::instance()->get_redis(true);
        $this->prefix = $prefix ?? (string)(App::instance()->get(self::CONFIG_PREFIX) ?: self::DEFAULT_PREFIX);
    }

    public function hit(string $key, int $limit, int $ttl): bool
    {
        return $this->increment($key, 1, $ttl) <= $limit;
    }

    public function increment(string $key, int $amount, int $ttl): int
    {
        $key = $this->key($key);
        $value = (int)$this->redis->incrBy($key, $amount);
        if ($value === $amount) {
            $this->redis->expire($key, $ttl);
        }
        return $value;
    }

    public function decrement(string $key, int $amount): int
    {
        $value = (int)$this->redis->decrBy($this->key($key), $amount);
        if ($value < 0) {
            $this->redis->set($this->key($key), 0);
            return 0;
        }
        return $value;
    }

    public function exists(string $key): bool
    {
        return (bool)$this->redis->exists($this->key($key));
    }

    public function clear(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    public function get(string $key): int
    {
        return (int)$this->redis->get($this->key($key));
    }

    public function ttl(string $key): int
    {
        return max(0, (int)$this->redis->ttl($this->key($key)));
    }

    public function sliding_hit(string $key, int $limit, int $window): bool
    {
        $now = microtime(true);
        return (bool)$this->redis->eval($this->script('sliding_hit'), [$this->key($key), (string)$now, (string)$window, (string)$limit, uniqid('', true)], 1);
    }

    public function reserve(string $quota_key, string $reservation_key, int $amount, int $ttl): bool
    {
        return (bool)$this->redis->eval($this->script('reserve'), [$this->key($quota_key), $this->key($reservation_key), $amount, $ttl], 2);
    }

    public function settle(string $quota_key, string $reservation_key, int $actual): int
    {
        return (int)$this->redis->eval($this->script('settle'), [$this->key($quota_key), $this->key($reservation_key), $actual], 2);
    }

    public function release(string $quota_key, string $reservation_key): void
    {
        $this->redis->eval($this->script('release'), [$this->key($quota_key), $this->key($reservation_key)], 2);
    }

    private function script(string $name): string
    {
        if (isset($this->scripts[$name])) {
            return $this->scripts[$name];
        }

        $path = self::LUA_DIR . '/' . $name . '.lua';
        $script = file_get_contents($path);
        if ($script === false) {
            throw new \RuntimeException("Failed to read Redis rate limit Lua script: $path");
        }

        return $this->scripts[$name] = $script;
    }

    private function key(string $key): string
    {
        return $this->prefix . self::KEY_PREFIX . $key;
    }
}
