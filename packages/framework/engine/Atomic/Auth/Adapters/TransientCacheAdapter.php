<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Tools\Transient;

class TransientCacheAdapter
{
    public function get(string $key): mixed
    {
        return Transient::get($key);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        Transient::set($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        Transient::delete($key);
    }
}
