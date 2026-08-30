<?php
declare(strict_types=1);

namespace Tests\Engine\RateLimit;

use Engine\Atomic\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestRateLimitStore;

final class RateLimiterTest extends TestCase
{
    protected function tearDown(): void
    {
        RateLimiter::reset();
    }

    public function test_fixed_window_blocks_after_limit_and_reports_state(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);

        $first = $limiter->fixed('api:test', 2, 60);
        $second = $limiter->fixed('api:test', 2, 60);
        $third = $limiter->fixed('api:test', 2, 60);

        $this->assertTrue($first->allowed);
        $this->assertSame(2, $first->limit);
        $this->assertSame(1, $first->remaining);
        $this->assertSame(60, $first->retry_after);

        $this->assertTrue($second->allowed);
        $this->assertSame(0, $second->remaining);

        $this->assertFalse($third->allowed);
        $this->assertSame(0, $third->remaining);
        $this->assertSame(60, $third->retry_after);
        $this->assertSame(3, $store->get('api:test'));
    }

    public function test_fixed_window_resets_after_ttl_and_isolates_keys(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);

        $this->assertTrue($limiter->fixed('api:a', 1, 10)->allowed);
        $this->assertFalse($limiter->fixed('api:a', 1, 10)->allowed);
        $this->assertTrue($limiter->fixed('api:b', 1, 10)->allowed);

        $store->advance(11);

        $result = $limiter->fixed('api:a', 1, 10);
        $this->assertTrue($result->allowed);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(1, $store->get('api:a'));
    }

    public function test_sliding_window_blocks_denied_hits_and_expires_old_hits(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);

        $this->assertTrue($limiter->sliding('user:test', 2, 10)->allowed);
        $store->advance(1);
        $this->assertTrue($limiter->sliding('user:test', 2, 10)->allowed);
        $this->assertFalse($limiter->sliding('user:test', 2, 10)->allowed);
        $this->assertSame(2, $store->sliding_count('user:test'));

        $store->advance(10);

        $this->assertTrue($limiter->sliding('user:test', 2, 10)->allowed);
        $this->assertSame(1, $store->sliding_count('user:test'));
    }

    public function test_sliding_window_result_metadata_uses_window_for_retry_after(): void
    {
        $limiter = new RateLimiter(new TestRateLimitStore());

        $allowed = $limiter->sliding('user:test', 3, 45);
        $denied = $limiter->sliding('other:test', 0, 45);

        $this->assertTrue($allowed->allowed);
        $this->assertSame(3, $allowed->limit);
        $this->assertSame(2, $allowed->remaining);
        $this->assertSame(45, $allowed->retry_after);

        $this->assertFalse($denied->allowed);
        $this->assertSame(0, $denied->remaining);
        $this->assertSame(45, $denied->retry_after);
    }

    public function test_concurrency_acquire_rolls_back_denied_hit_and_release_clamps_to_zero(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);

        $first = $limiter->acquire('job:test', 1, 60);
        $second = $limiter->acquire('job:test', 1, 60);

        $this->assertTrue($first->allowed);
        $this->assertSame(0, $first->remaining);

        $this->assertFalse($second->allowed);
        $this->assertSame(1, $store->get('job:test'));
        $this->assertSame(60, $second->retry_after);

        $limiter->release('job:test');
        $limiter->release('job:test');

        $this->assertSame(0, $store->get('job:test'));
        $this->assertTrue($limiter->acquire('job:test', 1, 60)->allowed);
    }

    public function test_concurrency_slot_expires_after_ttl(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);

        $this->assertTrue($limiter->acquire('job:test', 1, 5)->allowed);
        $this->assertFalse($limiter->acquire('job:test', 1, 5)->allowed);

        $store->advance(6);

        $this->assertTrue($limiter->acquire('job:test', 1, 5)->allowed);
        $this->assertSame(1, $store->get('job:test'));
    }

    public function test_cooldown_blocks_until_key_expires(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);

        $first = $limiter->cooldown('expensive:test', 30);
        $second = $limiter->cooldown('expensive:test', 30);

        $this->assertTrue($first->allowed);
        $this->assertSame(1, $first->limit);
        $this->assertSame(0, $first->remaining);
        $this->assertSame(0, $first->retry_after);

        $this->assertFalse($second->allowed);
        $this->assertSame(30, $second->retry_after);

        $store->advance(31);

        $this->assertTrue($limiter->cooldown('expensive:test', 30)->allowed);
    }

    public function test_clear_removes_rate_limit_state(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);

        $this->assertTrue($limiter->fixed('api:test', 1, 60)->allowed);
        $this->assertFalse($limiter->fixed('api:test', 1, 60)->allowed);

        $store->clear('api:test');

        $this->assertTrue($limiter->fixed('api:test', 1, 60)->allowed);
    }
}
