<?php
declare(strict_types=1);
namespace Engine\Atomic\Mutex;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Mutex\MutexDriverInterface;
use Engine\Atomic\Mutex\RedisMutexDriver;
use Engine\Atomic\Mutex\MemcachedMutexDriver;
use Engine\Atomic\Mutex\DatabaseMutexDriver;
use Engine\Atomic\Mutex\FileMutexDriver;

class Mutex
{
    public const DRIVER_REDIS = 'redis';
    public const DRIVER_MEMCACHED = 'memcached';
    public const DRIVER_DB = 'db';
    public const DRIVER_FILE = 'file';

    protected static ?MutexDriverInterface $driver = null;
    protected static ?string $driver_name = null;
    protected static bool $initialized = false;

    public static function acquire(string $name, int $ttl): ?string
    {
        if (!self::validate_name($name)) {
            Log::warning('[Mutex] Invalid lock name: ' . $name);
            return null;
        }

        if ($ttl <= 0) {
            Log::warning('[Mutex] TTL must be positive, got: ' . $ttl);
            return null;
        }

        $driver = self::get_driver();
        
        if ($driver === null) {
            Log::error('[Mutex] No mutex driver available');
            return null;
        }

        $token = self::generate_token();

        try {
            $acquired = $driver->acquire($name, $token, $ttl);
            
            if ($acquired) {
                Log::debug("[Mutex] Acquired lock '{$name}' with TTL {$ttl}s");
                return $token;
            }
            
            Log::debug("[Mutex] Failed to acquire lock '{$name}' (already held)");
            return null;

        } catch (\Throwable $e) {
            Log::error('[Mutex] Acquire exception: ' . $e->getMessage());
            return null;
        }
    }

    public static function release(string $name, string $token): bool
    {
        if (!self::validate_name($name)) {
            Log::warning('[Mutex] Invalid lock name: ' . $name);
            return false;
        }

        $driver = self::get_driver();
        
        if ($driver === null) {
            return false;
        }

        try {
            $released = $driver->release($name, $token);
            
            if ($released) {
                Log::debug("[Mutex] Released lock '{$name}'");
            } else {
                Log::debug("[Mutex] Failed to release lock '{$name}' (token mismatch or not found)");
            }
            
            return $released;

        } catch (\Throwable $e) {
            Log::error('[Mutex] Release exception: ' . $e->getMessage());
            return false;
        }
    }

    public static function exists(string $name): bool
    {
        if (!self::validate_name($name)) {
            return false;
        }

        $driver = self::get_driver();
        
        if ($driver === null) {
            return false;
        }

        try {
            return $driver->exists($name);
        } catch (\Throwable $e) {
            Log::error('[Mutex] Exists check exception: ' . $e->getMessage());
            return false;
        }
    }

    public static function force_release(string $name): bool
    {
        if (!self::validate_name($name)) {
            Log::warning('[Mutex] Invalid lock name: ' . $name);
            return false;
        }

        $driver = self::get_driver();
        
        if ($driver === null) {
            return false;
        }

        try {
            $released = $driver->force_release($name);
            
            if ($released) {
                Log::warning("[Mutex] Force-released lock '{$name}'");
            }
            
            return $released;

        } catch (\Throwable $e) {
            Log::error('[Mutex] Force release exception: ' . $e->getMessage());
            return false;
        }
    }

    public static function get_driver(): ?MutexDriverInterface
    {
        if (!self::$initialized) self::init_driver();
        return self::$driver;
    }

    public static function get_driver_name(): ?string
    {
        if (!self::$initialized) self::init_driver();
        return self::$driver_name;
    }

    protected static function init_driver(): void
    {
        if (self::$initialized) return;

        self::$initialized = true;

        try {
            $atomic = App::instance();
            $mutex_config = $atomic->get('MUTEX');
            $configured_driver = is_array($mutex_config) ? ($mutex_config['driver'] ?? null) : null;

            if ($configured_driver !== null) {
                self::$driver = self::create_driver($configured_driver);
                
                if (self::$driver !== null && self::$driver->is_available()) {
                    self::$driver_name = self::$driver->get_name();
                    Log::debug('[Mutex] Using configured driver: ' . self::$driver_name);
                    return;
                }
                
                Log::warning("[Mutex] Configured driver '{$configured_driver}' is not available, falling back to auto-selection");
            }

            self::auto_select_driver();

        } catch (\Throwable $e) {
            Log::error('[Mutex] Driver initialization failed: ' . $e->getMessage());
            self::$driver = null;
            self::$driver_name = null;
        }
    }

    protected static function auto_select_driver(): void
    {
        $driver_order = [
            self::DRIVER_REDIS,
            self::DRIVER_MEMCACHED,
            self::DRIVER_DB,
            self::DRIVER_FILE,
        ];

        foreach ($driver_order as $driver_name) {
            try {
                $driver = self::create_driver($driver_name);
                
                if ($driver !== null && $driver->is_available()) {
                    self::$driver = $driver;
                    self::$driver_name = $driver->get_name();
                    Log::debug('[Mutex] Auto-selected driver: ' . self::$driver_name);
                    return;
                }
            } catch (\Throwable $e) {
                Log::debug("[Mutex] Driver '{$driver_name}' not available: " . $e->getMessage());
            }
        }

        Log::error('[Mutex] No mutex driver available');
        self::$driver = null;
        self::$driver_name = null;
    }

    protected static function create_driver(string $name): ?MutexDriverInterface
    {
        return match ($name) {
            self::DRIVER_REDIS => new RedisMutexDriver(),
            self::DRIVER_MEMCACHED => new MemcachedMutexDriver(),
            self::DRIVER_DB => new DatabaseMutexDriver(),
            self::DRIVER_FILE => new FileMutexDriver(),
            default => null,
        };
    }

    protected static function generate_token(): string
    {
        return ID::uuid_v4();
    }

    protected static function validate_name(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9:._-]{1,128}$/', $name) === 1;
    }

    public static function synchronized(
        string $name, 
        int $ttl, 
        callable $callback, 
        ?callable $on_locked = null
    ): mixed {
        $token = self::acquire($name, $ttl);
        
        if ($token === null) {
            return $on_locked !== null ? $on_locked() : null;
        }

        try {
            return $callback();
        } finally {
            self::release($name, $token);
        }
    }

    public static function reset(): void
    {
        self::$driver = null;
        self::$driver_name = null;
        self::$initialized = false;
    }

    public static function set_driver(MutexDriverInterface $driver): void
    {
        self::$driver = $driver;
        self::$driver_name = $driver->get_name();
        self::$initialized = true;
    }

    public static function info(): array
    {
        return [
            'driver' => self::get_driver_name(),
            'initialized' => self::$initialized,
            'available' => self::$driver?->is_available() ?? false,
        ];
    }
}
