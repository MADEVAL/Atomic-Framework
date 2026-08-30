<?php
declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\RateLimit\RateLimitStoreInterface;

/**
 * In-memory store for rate-limit strategies.
 */
final class TestRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, array{value: int, expires_at: int|null}> */
    private array $values = [];
    /** @var array<string, list<float>> */
    private array $timestamps = [];
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

        return isset($this->values[$key]) || isset($this->timestamps[$key]);
    }

    public function clear(string $key): void
    {
        unset($this->values[$key], $this->timestamps[$key]);
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

    private function purge_value(string $key): void
    {
        $expires_at = $this->values[$key]['expires_at'] ?? null;
        if ($expires_at !== null && $expires_at <= $this->now) {
            unset($this->values[$key]);
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
