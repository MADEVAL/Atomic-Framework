<?php
declare(strict_types=1);

namespace Tests\Engine\RateLimit;

use Engine\Atomic\RateLimit\Drivers\Redis as RedisRateLimitStore;
use PHPUnit\Framework\TestCase;

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

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $password = (string)(getenv('REDIS_PASSWORD') ?: '');
        $db = (int)(getenv('REDIS_DB') ?: 0);

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
        if ($this->redis instanceof \Redis && $this->prefix !== '') {
            $keys = $this->redis->keys($this->prefix . 'rate_limit:*');
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

        usleep(1_100_000);

        $this->assertTrue($store->sliding_hit('sliding:test', 2, 1));
    }

    public function test_token_reservation_settle_release_and_failed_reserve(): void
    {
        $store = $this->store();

        $this->assertSame(100, $store->increment('quota:user:1', 100, 60));
        $this->assertTrue($store->reserve('quota:user:1', 'reservation:1', 40, 60));
        $this->assertSame(70, $store->settle('quota:user:1', 'reservation:1', 30));

        $this->assertTrue($store->reserve('quota:user:1', 'reservation:2', 50, 60));
        $store->release('quota:user:1', 'reservation:2');
        $this->assertSame(70, $store->get('quota:user:1'));

        $this->assertFalse($store->reserve('quota:user:1', 'reservation:3', 80, 60));
        $this->assertSame(70, $store->get('quota:user:1'));
    }

    public function test_settling_missing_reservation_does_not_debit_quota(): void
    {
        $store = $this->store();
        $store->increment('quota:user:1', 25, 60);

        $this->assertSame(25, $store->settle('quota:user:1', 'missing', 10));
        $this->assertSame(25, $store->get('quota:user:1'));
    }

    public function test_duplicate_active_reservation_id_is_rejected_without_double_debiting(): void
    {
        $store = $this->store();
        $store->increment('quota:user:1', 100, 60);

        $this->assertTrue($store->reserve('quota:user:1', 'reservation:1', 40, 60));
        $this->assertFalse($store->reserve('quota:user:1', 'reservation:1', 20, 60));
        $this->assertSame(60, $store->get('quota:user:1'));

        $store->release('quota:user:1', 'reservation:1');

        $this->assertSame(100, $store->get('quota:user:1'));
    }

    public function test_reservation_id_can_be_reused_after_reservation_ttl_expires(): void
    {
        $store = $this->store();
        $store->increment('quota:user:1', 100, 60);

        $this->assertTrue($store->reserve('quota:user:1', 'reservation:1', 40, 1));
        $this->assertFalse($store->reserve('quota:user:1', 'reservation:1', 10, 1));

        usleep(1_100_000);

        $this->assertTrue($store->reserve('quota:user:1', 'reservation:1', 10, 1));
        $this->assertSame(50, $store->get('quota:user:1'));
    }

    private function store(): RedisRateLimitStore
    {
        self::assertInstanceOf(RedisRateLimitStore::class, $this->store);

        return $this->store;
    }
}
