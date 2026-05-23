<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface CacheStoreInterface
{
    public function exists(string $key, mixed &$val = null): array|false;
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    public function get(string $key): mixed;
    public function clear(string $key): bool;
    public function reset(): bool;
    public function flush_local_cache(): void;
}
