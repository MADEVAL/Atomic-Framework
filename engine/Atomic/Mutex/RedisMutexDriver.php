<?php
declare(strict_types=1);
namespace Engine\Atomic\Mutex;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;

class RedisMutexDriver implements MutexDriverInterface
{
    protected ?\Redis $redis = null;
    protected string $prefix = 'mutex.';
    protected ConnectionManager $connectionManager;

    public function __construct()
    {
        $this->connectionManager = ConnectionManager::instance();
        $this->init_connection();
    }

    protected function init_connection(): void
    {
        if ($this->redis !== null) return;
        if (!extension_loaded('redis')) return;

        try {
            $this->redis = $this->connectionManager->get_redis(false);
            
            if ($this->redis) {
                $atomic = App::instance();
                $config = $atomic->get('REDIS');
                $this->prefix = ($config['prefix'] ?? 'atomic.') . 'mutex.';
            }

        } catch (\Throwable $e) {
            Log::error('[Mutex] Redis connection failed: ' . $e->getMessage());
            $this->redis = null;
        }
    }

    public function acquire(string $name, string $token, int $ttl): bool
    {
        if ($this->redis === null || $ttl <= 0) return false;

        try {
            $key = $this->prefix . $name;
            $result = $this->redis->set($key, $token, ['NX', 'EX' => $ttl]);
            return $result === true;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Redis acquire failed: ' . $e->getMessage());
            return false;
        }
    }

    public function release(string $name, string $token): bool
    {
        if ($this->redis === null) return false;

        try {
            $key = $this->prefix . $name;

            $result = $this->redis->eval(
                'return (redis.call("GET", KEYS[1]) == ARGV[1]) and redis.call("DEL", KEYS[1]) or 0',
                [$key, $token],
                1
            );

            return (bool) $result;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Redis release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function exists(string $name): bool
    {
        if ($this->redis === null) return false;

        try {
            $key = $this->prefix . $name;
            return $this->redis->exists($key) > 0;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Redis exists check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function force_release(string $name): bool
    {
        if ($this->redis === null) return false;

        try {
            $key = $this->prefix . $name;
            $this->redis->del($key);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Redis force release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function get_name(): string
    {
    return 'redis';
    }

    public function is_available(): bool
    {
        if ($this->redis === null) return false;

        try {
            $test_key = $this->prefix . '_health_check';
            $ok = $this->redis->set($test_key, '1', ['EX' => 1]);
            $this->redis->del($test_key);
            return $ok === true || $ok === 'OK';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function get_token(string $name): ?string
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            $key = $this->prefix . $name;
            $token = $this->redis->get($key);
            return $token === false ? null : $token;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
