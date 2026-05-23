<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Drivers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Helpers\Payload;

class Memcached implements CacheStoreInterface
{
    private const GEN_TTL = 0;
    private const MAX_RELATIVE_EXPIRATION = 2592000;

    private \Memcached $mc;
    private string $namespace;
    private string $gen_key;
    private bool $valid_namespace;
    private ?int $cached_gen = null;

    public function __construct(\Memcached $mc, string $namespace = 'atomic')
    {
        $this->mc = $mc;
        $this->namespace = $this->normalize_namespace($namespace);
        $this->valid_namespace = $this->namespace !== '';
        $this->gen_key = $this->namespace . '.gen';
    }

    private function normalize_namespace(string $namespace): string
    {
        $namespace = rtrim(trim($namespace), '.');
        return $namespace !== '' ? $namespace : 'atomic';
    }

    public function get_generation(): int
    {
        if (!$this->valid_namespace) {
            return 0;
        }

        if ($this->cached_gen !== null) {
            return $this->cached_gen;
        }

        $val = $this->mc->get($this->gen_key);
        $result_code = $this->mc->getResultCode();

        if ($result_code !== \Memcached::RES_SUCCESS && $result_code !== \Memcached::RES_NOTFOUND) {
            throw new \RuntimeException('Memcached cache generation read failed: ' . $this->mc->getResultMessage());
        }

        if ($val === false) {
            if ($result_code === \Memcached::RES_NOTFOUND && !$this->mc->add($this->gen_key, 1, self::GEN_TTL)) {
                $val = $this->mc->get($this->gen_key);
                if ($val === false) {
                    throw new \RuntimeException('Memcached cache generation initialization failed.');
                }
            } elseif ($result_code === \Memcached::RES_NOTFOUND) {
                $val = 1;
            }
        }

        if (!ctype_digit((string) $val)) {
            if (!$this->mc->set($this->gen_key, 1, self::GEN_TTL)) {
                throw new \RuntimeException('Memcached cache generation normalization failed.');
            }
            $val = 1;
        }

        return $this->cached_gen = (int) $val;
    }

    public function flush_local_cache(): void
    {
        $this->cached_gen = null;
    }

    private function real_key(string $key): string|false
    {
        if (!$this->valid_namespace) {
            return false;
        }

        $key = $this->normalize_key($key);
        if ($key === false) {
            return false;
        }

        return $this->namespace . '.' . $this->get_generation() . '.' . $key;
    }

    private function normalize_key(string $key): string|false
    {
        $key = ltrim($key, '.');
        return $key !== '' ? $key : false;
    }

    private function expiration(int $ttl): int
    {
        $ttl = max(0, $ttl);
        return $ttl > self::MAX_RELATIVE_EXPIRATION ? time() + $ttl : $ttl;
    }

    public function exists(string $key, mixed &$val = null): array|false
    {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        $raw = $this->mc->get($real_key);

        if ($raw === false && $this->mc->getResultCode() === \Memcached::RES_NOTFOUND) {
            return false;
        }

        if ($raw === false) {
            throw new \RuntimeException('Memcached cache key read failed.');
        }

        $payload = Payload::unpack($raw);
        if ($payload === false) {
            if (!$this->mc->delete($real_key) && $this->mc->getResultCode() !== \Memcached::RES_NOTFOUND) {
                throw new \RuntimeException('Memcached cache key cleanup failed.');
            }

            $val = null;
            return false;
        }

        if (Payload::is_expired($payload)) {
            if (!$this->mc->delete($real_key) && $this->mc->getResultCode() !== \Memcached::RES_NOTFOUND) {
                throw new \RuntimeException('Memcached cache key cleanup failed.');
            }

            $val = null;
            return false;
        }

        $val = $payload['value'];
        return Payload::meta($payload);
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        if (!$this->mc->set($real_key, Payload::pack($value, $ttl), $this->expiration($ttl))) {
            throw new \RuntimeException('Memcached cache key write failed.');
        }

        return true;
    }

    public function get(string $key): mixed
    {
        return $this->exists($key, $value) ? $value : false;
    }

    public function clear(string $key): bool
    {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        if (!$this->mc->delete($real_key)) {
            if ($this->mc->getResultCode() !== \Memcached::RES_NOTFOUND) {
                throw new \RuntimeException('Memcached cache key delete failed.');
            }

            return false;
        }

        return true;
    }

    public function reset(): bool
    {
        if (!$this->valid_namespace) {
            return false;
        }

        $new_gen = $this->mc->increment($this->gen_key);

        if ($new_gen === false) {
            if ($this->mc->getResultCode() !== \Memcached::RES_NOTFOUND) {
                throw new \RuntimeException('Memcached cache generation reset failed.');
            }

            if ($this->mc->add($this->gen_key, 2, self::GEN_TTL)) {
                $new_gen = 2;
            } else {
                $new_gen = $this->mc->increment($this->gen_key);
                if ($new_gen === false) {
                    throw new \RuntimeException('Memcached cache generation reset failed after initialization race.');
                }
            }
        }

        $this->cached_gen = (int)$new_gen;

        return true;
    }

}
