<?php
declare(strict_types=1);
namespace Engine\Atomic\RateLimit;

if (!defined('ATOMIC_START')) exit;

interface RateLimitStoreInterface
{
    public function hit(string $key, int $limit, int $ttl): bool;
    public function increment(string $key, int $amount, int $ttl): int;
    public function decrement(string $key, int $amount): int;
    public function exists(string $key): bool;
    public function clear(string $key): void;
    public function get(string $key): int;
    public function ttl(string $key): int;
    public function sliding_hit(string $key, int $limit, int $window): bool;
    public function reserve(string $quota_key, string $reservation_key, int $amount, int $ttl): bool;
    public function settle(string $quota_key, string $reservation_key, int $actual): int;
    public function release(string $quota_key, string $reservation_key): void;
}
