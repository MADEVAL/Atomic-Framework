<?php
declare(strict_types=1);
namespace Engine\Atomic\Mutex;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;

class MemcachedMutexDriver implements MutexDriverInterface
{
    protected ?\Memcached $memcached = null;
    protected string $prefix = 'mutex.';
    protected ConnectionManager $connectionManager;

    public function __construct()
    {
        $this->connectionManager = new ConnectionManager();
        $this->init_connection();
    }

    protected function init_connection(): void
    {
        if ($this->memcached !== null) return;
        if (!extension_loaded('memcached')) return;

        try {
            $this->memcached = $this->connectionManager->get_memcached(false);
            
            if ($this->memcached) {
                $atomic = App::instance();
                $config = $atomic->get('MEMCACHED');
                $this->prefix = ($config['prefix'] ?? 'atomic.') . 'mutex.';
            }

        } catch (\Throwable $e) {
            Log::error('[Mutex] Memcached connection failed: ' . $e->getMessage());
            $this->memcached = null;
        }
    }

    public function acquire(string $name, string $token, int $ttl): bool
    {
        if ($this->memcached === null || $ttl <= 0) {
            return false;
        }

        try {
            $key = $this->prefix . $name;
            $this->memcached->add($key, $token, $ttl);
            $result_code = $this->memcached->getResultCode();
            return $result_code === \Memcached::RES_SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Memcached acquire failed: ' . $e->getMessage());
            return false;
        }
    }

    public function release(string $name, string $token): bool
    {
        if ($this->memcached === null) return false;

        try {
            $key = $this->prefix . $name;

            $stored = $this->memcached->get($key);
            
            $result_code_get = $this->memcached->getResultCode();
            if ($result_code_get === \Memcached::RES_NOTFOUND) {
                return false;
            }
            
            if ($result_code_get !== \Memcached::RES_SUCCESS) {
                return false;
            }

            if (!is_string($stored) || $stored !== $token) {
                return false;
            }

            $this->memcached->delete($key);
            $result_code_del = $this->memcached->getResultCode();
            
            if ($result_code_del === \Memcached::RES_SUCCESS) {
                return true;
            }
            
            if ($result_code_del === \Memcached::RES_NOTFOUND) {
                return true;
            }
            
            return false;

        } catch (\Throwable $e) {
            Log::error('[Mutex] Memcached release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function exists(string $name): bool
    {
        if ($this->memcached === null) {
            return false;
        }

        try {
            $key = $this->prefix . $name;
            $this->memcached->get($key);
            $result_code = $this->memcached->getResultCode();
            return $result_code === \Memcached::RES_SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Memcached exists check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function force_release(string $name): bool
    {
        if ($this->memcached === null) {
            return false;
        }

        try {
            $key = $this->prefix . $name;
            $this->memcached->delete($key);
            $result_code = $this->memcached->getResultCode();
            
            if ($result_code === \Memcached::RES_SUCCESS) {
                return true;
            }
            
            if ($result_code === \Memcached::RES_NOTFOUND) {
                return true;
            }
            
            return false;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Memcached force release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function get_name(): string
    {
        return 'memcached';
    }

    public function is_available(): bool
    {
        if ($this->memcached === null) {
            return false;
        }

        try {
            $test_key = $this->prefix . '_health_check';
            $this->memcached->set($test_key, '1', 1);
            $this->memcached->delete($test_key);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function get_token(string $name): ?string
    {
        if ($this->memcached === null) {
            return null;
        }

        try {
            $key = $this->prefix . $name;
            $val = $this->memcached->get($key);
            $result_code = $this->memcached->getResultCode();
            if ($result_code !== \Memcached::RES_SUCCESS) {
                return null;
            }
            return is_string($val) ? $val : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
