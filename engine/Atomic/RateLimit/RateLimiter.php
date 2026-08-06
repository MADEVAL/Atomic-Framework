<?php
declare(strict_types=1);
namespace Engine\Atomic\RateLimit;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\RateLimit\Drivers\Redis;

final class RateLimiter
{
    public const DRIVER_REDIS = 'redis';
    public const CONFIG_ROOT = 'RATE_LIMITER';
    public const FAIL_OPEN = 'open';

    public const STRATEGY_FIXED = 'fixed';
    public const STRATEGY_SLIDING = 'sliding';
    public const STRATEGY_COOLDOWN = 'cooldown';
    public const STRATEGY_CONCURRENCY = 'concurrency';

    private static ?RateLimitStoreInterface $configured_store = null;

    public function __construct(private RateLimitStoreInterface $store) {}

    public static function from_config(): self
    {
        if (self::$configured_store === null) {
            self::$configured_store = new Redis();
        }
        return new self(self::$configured_store);
    }

    public function store(): RateLimitStoreInterface
    {
        return $this->store;
    }

    public function fixed(string $key, int $limit, int $ttl): RateLimitResult
    {
        $count = $this->store->increment($key, 1, $ttl);
        return new RateLimitResult($count <= $limit, $limit, max(0, $limit - $count), $this->store->ttl($key));
    }

    public function sliding(string $key, int $limit, int $window): RateLimitResult
    {
        $allowed = $this->store->sliding_hit($key, $limit, $window);
        return new RateLimitResult($allowed, $limit, $allowed ? $limit - 1 : 0, $window);
    }

    public function cooldown(string $key, int $seconds): RateLimitResult
    {
        if ($this->store->exists($key)) {
            return new RateLimitResult(false, 1, 0, $this->store->ttl($key));
        }

        $this->store->increment($key, 1, $seconds);
        return new RateLimitResult(true, 1, 0);
    }

    public function acquire(string $key, int $limit, int $ttl): RateLimitResult
    {
        $active = $this->store->increment($key, 1, $ttl);
        if ($active <= $limit) {
            return new RateLimitResult(true, $limit, max(0, $limit - $active));
        }

        $this->store->decrement($key, 1);
        return new RateLimitResult(false, $limit, 0, $this->store->ttl($key));
    }

    public function release(string $key): void
    {
        $this->store->decrement($key, 1);
    }

    public function add_quota(string $key, int $tokens, int $ttl = 31536000): int
    {
        return $this->store->increment($key, $tokens, $ttl);
    }

    public function reserve_tokens(string $quota_key, string $reservation_id, int $estimated_tokens, int $ttl = 300): bool
    {
        return $this->store->reserve($quota_key, $reservation_id, $estimated_tokens, $ttl);
    }

    public function settle_tokens(string $quota_key, string $reservation_id, int $actual_tokens): int
    {
        return $this->store->settle($quota_key, $reservation_id, $actual_tokens);
    }

    public function release_tokens(string $quota_key, string $reservation_id): void
    {
        $this->store->release($quota_key, $reservation_id);
    }
}
