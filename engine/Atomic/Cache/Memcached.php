<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache;

if (!defined('ATOMIC_START')) exit;


class Memcached
{
    private const GEN_TTL = 0;

    private \Memcached $mc;
    private string $gen_key;
    private ?int $cached_gen = null;

    public function __construct(\Memcached $mc, string $namespace = 'atomic')
    {
        $this->mc     = $mc;
        $this->gen_key = $namespace . '.gen';
    }

    public function get_generation(): int
    {
        if ($this->cached_gen !== null) {
            return $this->cached_gen;
        }

        $val = $this->mc->get($this->gen_key);

        if ($val === false) {
            $this->mc->set($this->gen_key, 1, self::GEN_TTL);
            $val = 1;
        }

        return $this->cached_gen = (int) $val;
    }

    public function flush_local_cache(): void
    {
        $this->cached_gen = null;
    }

    private function real_key(string $key): string
    {
        return $this->get_generation() . '.' . $key;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->mc->set($this->real_key($key), serialize($value), $ttl);
    }

    public function get(string $key): mixed
    {
        $raw = $this->mc->get($this->real_key($key));

        if ($raw === false) {
            return false;
        }

        return unserialize($raw);
    }

    public function clear(string $key): bool
    {
        return $this->mc->delete($this->real_key($key));
    }

    public function reset(?string $suffix = null): bool
    {
        $new_gen = $this->mc->increment($this->gen_key);

        if ($new_gen === false) {
            $new_gen = ($this->cached_gen ?? 0) + 1;
            $this->mc->set($this->gen_key, $new_gen, self::GEN_TTL);
        }

        $this->cached_gen = (int) $new_gen;

        return true;
    }
}
