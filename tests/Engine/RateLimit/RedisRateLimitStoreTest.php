<?php
declare(strict_types=1);

namespace Tests\Engine\RateLimit;

use Engine\Atomic\RateLimit\Drivers\Redis as RedisRateLimitStore;
use Engine\Atomic\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestConfig;
use Tests\Support\Wait;

final class RedisRateLimitStoreTest extends TestCase
{
    private ?\Redis $redis = null;
    private ?RedisRateLimitStore $store = null;
    private string $prefix = '';

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis extension is not installed.');
        }

        $config = TestConfig::redis();
        $host = (string)$config['host'];
        $port = (int)$config['port'];
        $password = (string)$config['password'];
        $db = (int)$config['db'];

        $redis = new \Redis();
        try {
            $connected = $redis->connect($host, $port, 0.2);
            if (!$connected) {
                $this->markTestSkipped('Redis server is not reachable.');
            }
            if ($password !== '') {
                $redis->auth($password);
            }
            $redis->select($db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server is not reachable: ' . $e->getMessage());
        }

        $this->redis = $redis;
        $this->prefix = 'atomic_test_rate_limit_' . bin2hex(random_bytes(6)) . ':';
        $this->store = new RedisRateLimitStore($this->redis, $this->prefix);
    }

    protected function tearDown(): void
    {
        RateLimiter::reset();

        if ($this->redis instanceof \Redis && $this->prefix !== '') {
            $keys = $this->redis->keys($this->prefix . 'rate_limit.*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
            $this->redis->close();
        }
    }

    public function test_increment_sets_ttl_only_when_key_is_created(): void
    {
        $store = $this->store();

        $this->assertSame(5, $store->increment('fixed:test', 5, 20));
        $ttl = $store->ttl('fixed:test');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(20, $ttl);

        $this->assertSame(8, $store->increment('fixed:test', 3, 120));
        $this->assertLessThanOrEqual($ttl, $store->ttl('fixed:test'));
    }

    public function test_decrement_clamps_negative_values_to_zero(): void
    {
        $store = $this->store();

        $store->increment('concurrency:test', 1, 60);

        $this->assertSame(0, $store->decrement('concurrency:test', 5));
        $this->assertSame(0, $store->get('concurrency:test'));
    }

    public function test_sliding_window_removes_old_hits_and_does_not_record_denied_hits(): void
    {
        $store = $this->store();

        $this->assertTrue($store->sliding_hit('sliding:test', 2, 1));
        $this->assertTrue($store->sliding_hit('sliding:test', 2, 1));
        $this->assertFalse($store->sliding_hit('sliding:test', 2, 1));

        $this->assertTrue(Wait::until(fn (): bool => $store->sliding_hit('sliding:test', 2, 1), 3));
    }

    private function store(): RedisRateLimitStore
    {
        self::assertInstanceOf(RedisRateLimitStore::class, $this->store);

        return $this->store;
    }
}
