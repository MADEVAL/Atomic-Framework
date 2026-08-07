<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class PlatformGuard
{
    public static function requireExtension(string $extension): void
    {
        if (!extension_loaded($extension)) {
            TestCase::markTestSkipped("Extension '{$extension}' not loaded");
        }
    }

    public static function requirePcntl(): void
    {
        if (!function_exists('pcntl_fork')) {
            TestCase::markTestSkipped('pcntl extension not available (requires Linux/Unix)');
        }
    }

    public static function requirePosix(): void
    {
        if (!function_exists('posix_kill')) {
            TestCase::markTestSkipped('posix extension not available (requires Linux/Unix)');
        }
    }

    public static function requireProcFS(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            TestCase::markTestSkipped('/proc filesystem not available (requires Linux)');
        }
    }

    public static function requireMySql(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            TestCase::markTestSkipped('pdo_mysql extension not loaded');
        }
        try {
            $pdo = new \PDO('mysql:host=127.0.0.1;port=3306', 'atomic_test_user', 'atomic_test_pass');
            $pdo->query('SELECT 1');
        } catch (\PDOException) {
            TestCase::markTestSkipped('MySQL not available');
        }
    }

    public static function requireRedis(): void
    {
        if (!extension_loaded('redis')) {
            TestCase::markTestSkipped('redis extension not loaded');
        }
        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 0.5);
            $redis->ping();
        } catch (\RedisException) {
            TestCase::markTestSkipped('Redis server not available');
        }
    }

    public static function requireMemcached(): void
    {
        if (!extension_loaded('memcached')) {
            TestCase::markTestSkipped('memcached extension not loaded');
        }
        try {
            $mc = new \Memcached();
            $mc->addServer('127.0.0.1', 11211);
            $versions = $mc->getVersion();
            if (empty($versions)) {
                throw new \RuntimeException('No servers');
            }
        } catch (\Throwable) {
            TestCase::markTestSkipped('Memcached server not available');
        }
    }

    public static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            TestCase::markTestSkipped('sodium extension not loaded');
        }
    }
}
