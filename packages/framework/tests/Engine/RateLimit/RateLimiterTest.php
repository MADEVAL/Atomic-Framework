<?php
declare(strict_types=1);

namespace Tests\Engine\RateLimit;

use Engine\Atomic\RateLimit\RateLimitStoreInterface;
use Engine\Atomic\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
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

    public function test_token_quota_reserve_settle_and_release_lifecycle(): void
    {
        $limiter = new RateLimiter(new TestRateLimitStore());
        $this->assertSame(100, $limiter->add_quota('quota:user:1', 100));

        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:1', 40));
        $this->assertSame(70, $limiter->settle_tokens('quota:user:1', 'reservation:1', 30));

        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:2', 50));
        $limiter->release_tokens('quota:user:1', 'reservation:2');
        $this->assertSame(70, $limiter->store()->get('quota:user:1'));

        $this->assertFalse($limiter->reserve_tokens('quota:user:1', 'reservation:3', 80));
        $this->assertSame(70, $limiter->store()->get('quota:user:1'));
    }

    public function test_token_quota_settle_charges_overage_and_clamps_at_zero(): void
    {
        $limiter = new RateLimiter(new TestRateLimitStore());
        $limiter->add_quota('quota:user:1', 50);

        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:1', 20));
        $this->assertSame(20, $limiter->settle_tokens('quota:user:1', 'reservation:1', 30));

        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:2', 10));
        $this->assertSame(0, $limiter->settle_tokens('quota:user:1', 'reservation:2', 50));
    }

    public function test_token_quota_missing_settlement_and_release_are_noops(): void
    {
        $limiter = new RateLimiter(new TestRateLimitStore());
        $limiter->add_quota('quota:user:1', 25);

        $this->assertSame(25, $limiter->settle_tokens('quota:user:1', 'missing', 10));
        $limiter->release_tokens('quota:user:1', 'missing');

        $this->assertSame(25, $limiter->store()->get('quota:user:1'));
    }

    public function test_token_quota_rejects_duplicate_active_reservation_id(): void
    {
        $limiter = new RateLimiter(new TestRateLimitStore());
        $limiter->add_quota('quota:user:1', 100);

        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:1', 40));
        $this->assertFalse($limiter->reserve_tokens('quota:user:1', 'reservation:1', 20));
        $this->assertSame(60, $limiter->store()->get('quota:user:1'));

        $limiter->release_tokens('quota:user:1', 'reservation:1');

        $this->assertSame(100, $limiter->store()->get('quota:user:1'));
        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:1', 20));
    }

    public function test_token_reservation_expires_and_stops_blocking_id_reuse(): void
    {
        $store = new TestRateLimitStore();
        $limiter = new RateLimiter($store);
        $limiter->add_quota('quota:user:1', 100);

        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:1', 40, 5));
        $this->assertFalse($limiter->reserve_tokens('quota:user:1', 'reservation:1', 10, 5));

        $store->advance(6);

        $this->assertTrue($limiter->reserve_tokens('quota:user:1', 'reservation:1', 10, 5));
        $this->assertSame(50, $limiter->store()->get('quota:user:1'));
    }
}

final class TestRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, array{value: int, expires_at: int|null}> */
    private array $values = [];
    /** @var array<string, list<float>> */
    private array $timestamps = [];
    /** @var array<string, array{quota_key: string, amount: int, expires_at: int}> */
    private array $reservations = [];
    private int $now = 1_000;

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function hit(string $key, int $limit, int $ttl): bool
    {
        return $this->increment($key, 1, $ttl) <= $limit;
    }

    public function increment(string $key, int $amount, int $ttl): int
    {
        $this->purge_value($key);
        $is_new = !isset($this->values[$key]);
        $value = ($this->values[$key]['value'] ?? 0) + $amount;
        $this->values[$key] = [
            'value' => $value,
            'expires_at' => $is_new ? $this->now + $ttl : $this->values[$key]['expires_at'],
        ];

        return $value;
    }

    public function decrement(string $key, int $amount): int
    {
        $this->purge_value($key);
        $value = max(0, ($this->values[$key]['value'] ?? 0) - $amount);
        $this->values[$key] = [
            'value' => $value,
            'expires_at' => $this->values[$key]['expires_at'] ?? null,
        ];

        return $value;
    }

    public function exists(string $key): bool
    {
        $this->purge_value($key);
        $this->purge_reservation($key);

        return isset($this->values[$key]) || isset($this->timestamps[$key]) || isset($this->reservations[$key]);
    }

    public function clear(string $key): void
    {
        unset($this->values[$key], $this->timestamps[$key], $this->reservations[$key]);
    }

    public function get(string $key): int
    {
        $this->purge_value($key);

        return (int)($this->values[$key]['value'] ?? 0);
    }

    public function ttl(string $key): int
    {
        $this->purge_value($key);
        $expires_at = $this->values[$key]['expires_at'] ?? null;

        return $expires_at === null ? 0 : max(0, $expires_at - $this->now);
    }

    public function sliding_hit(string $key, int $limit, int $window): bool
    {
        $this->prune_sliding($key, $window);
        if (count($this->timestamps[$key] ?? []) >= $limit) {
            return false;
        }

        $this->timestamps[$key][] = (float)$this->now;
        return true;
    }

    public function sliding_count(string $key, int $window = 60): int
    {
        $this->prune_sliding($key, $window);

        return count($this->timestamps[$key] ?? []);
    }

    public function reserve(string $quota_key, string $reservation_key, int $amount, int $ttl): bool
    {
        $this->purge_value($quota_key);
        $this->purge_reservation($reservation_key);
        if (isset($this->reservations[$reservation_key]) || ($this->values[$quota_key]['value'] ?? 0) < $amount) {
            return false;
        }

        $this->values[$quota_key]['value'] -= $amount;
        $this->reservations[$reservation_key] = [
            'quota_key' => $quota_key,
            'amount' => $amount,
            'expires_at' => $this->now + $ttl,
        ];

        return true;
    }

    public function settle(string $quota_key, string $reservation_key, int $actual): int
    {
        $this->purge_value($quota_key);
        $this->purge_reservation($reservation_key);
        if (!isset($this->reservations[$reservation_key])) {
            return (int)($this->values[$quota_key]['value'] ?? 0);
        }

        $reserved = $this->reservations[$reservation_key]['amount'];
        unset($this->reservations[$reservation_key]);

        if ($reserved > $actual) {
            $this->values[$quota_key]['value'] = ($this->values[$quota_key]['value'] ?? 0) + ($reserved - $actual);
        } elseif ($actual > $reserved) {
            $this->values[$quota_key]['value'] = max(0, ($this->values[$quota_key]['value'] ?? 0) - ($actual - $reserved));
        }

        return (int)($this->values[$quota_key]['value'] ?? 0);
    }

    public function release(string $quota_key, string $reservation_key): void
    {
        $this->purge_value($quota_key);
        $this->purge_reservation($reservation_key);
        if (!isset($this->reservations[$reservation_key])) {
            return;
        }

        $reserved = $this->reservations[$reservation_key]['amount'];
        $this->values[$quota_key]['value'] = ($this->values[$quota_key]['value'] ?? 0) + $reserved;
        unset($this->reservations[$reservation_key]);
    }

    private function purge_value(string $key): void
    {
        $expires_at = $this->values[$key]['expires_at'] ?? null;
        if ($expires_at !== null && $expires_at <= $this->now) {
            unset($this->values[$key]);
        }
    }

    private function purge_reservation(string $key): void
    {
        $expires_at = $this->reservations[$key]['expires_at'] ?? null;
        if ($expires_at !== null && $expires_at <= $this->now) {
            unset($this->reservations[$key]);
        }
    }

    private function prune_sliding(string $key, int $window): void
    {
        $min = $this->now - $window;
        $this->timestamps[$key] = array_values(array_filter(
            $this->timestamps[$key] ?? [],
            static fn(float $timestamp): bool => $timestamp > $min
        ));

        if ($this->timestamps[$key] === []) {
            unset($this->timestamps[$key]);
        }
    }
}
