<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Drivers;

use Engine\Atomic\App\Models\Options;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface;
use Engine\Atomic\Cache\Helpers\Payload;
use Engine\Atomic\Core\App;

class DB extends \Prefab implements CacheStoreInterface, PrunableCacheStoreInterface
{
    protected Options $options;
    private string $namespace;
    private string $gen_key;
    private ?int $cached_gen = null;

    public function __construct(string $namespace = 'atomic'){
        $this->options = new Options();
        $this->namespace = $this->normalize_namespace($namespace);
        $this->gen_key = $this->namespace . '.gen';
    }

    private function normalize_namespace(string $namespace): string
    {
        $namespace = rtrim(trim($namespace), '.');
        return $namespace !== '' ? $namespace : 'atomic';
    }

    private function transaction(string $context, callable $callback): mixed
    {
        $db = App::instance()->get('DB');
        if (!$db instanceof \DB\SQL) {
            throw new \RuntimeException($context . ': DB connection is not configured.');
        }
        $started = false;

        try {
            if (!$db->trans()) {
                $db->begin();
                $started = true;
            }

            $result = $callback();

            if ($started) {
                $db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started) {
                try { $db->rollback(); } catch (\Throwable $_) {}
            }

            throw new \RuntimeException($context . ': ' . $e->getMessage(), 0, $e);
        }
    }

    private function initialize_generation(): int|false
    {
        return $this->transaction('Error initializing DB cache generation', function (): int|false {
            $meta = $this->options->has_option($this->gen_key, $val);
            if ($meta !== false && ctype_digit((string) $val)) {
                return (int) $val;
            }

            if (!$this->options->set_option($this->gen_key, '1')) {
                throw new \RuntimeException('DB cache generation initialization write failed.');
            }

            return 1;
        });
    }

    public function get_generation(): int
    {
        if ($this->cached_gen !== null) {
            return $this->cached_gen;
        }

        $meta = $this->options->has_option($this->gen_key, $val);
        if ($meta === false || !ctype_digit((string) $val)) {
            $val = $this->initialize_generation();
            if ($val === false) {
                throw new \RuntimeException('DB cache generation initialization failed.');
            }
        }

        return $this->cached_gen = (int) $val;
    }

    public function flush_local_cache(): void
    {
        $this->cached_gen = null;
    }

    private function normalize_key(string $key): string|false
    {
        $key = ltrim($key, '.');
        return $key !== '' ? $key : false;
    }

    private function real_key(string $key): string|false
    {
        $key = $this->normalize_key($key);
        if ($key === false) {
            return false;
        }

        return $this->namespace . '.' . $this->get_generation() . '.' . $key;
    }
    
    public function exists(string $key, mixed &$val = NULL): array|false {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        return $this->transaction('Error reading DB cache key', function () use ($real_key, &$val): array|false {
            $meta = $this->options->has_option($real_key, $raw);
            if ($meta === false) {
                return false;
            }

            $payload = Payload::unpack($raw);
            if ($payload === false || Payload::is_expired($payload)) {
                if (!Options::delete_option($real_key)) {
                    throw new \RuntimeException('DB cache key cleanup failed.');
                }

                $val = null;
                return false;
            }

            $val = $payload['value'];
            return Payload::meta($payload);
        });
    }

    public function set(string $key, mixed $val, int $ttl = 0): bool {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        if (!$this->options->set_option($real_key, Payload::pack($val, $ttl), $ttl)) {
            throw new \RuntimeException('DB cache key write failed.');
        }

        return true;
    }

    public function get(string $key): mixed {
        return $this->exists($key, $value) ? $value : false;
    }

    public function clear(string $key): bool {
        $real_key = $this->real_key($key);
        if ($real_key === false) {
            return false;
        }

        return $this->transaction('Error clearing DB cache key', function () use ($real_key): bool {
            if (Options::has_option($real_key) === false) {
                return false;
            }

            if (!Options::delete_option($real_key)) {
                throw new \RuntimeException('DB cache key delete failed.');
            }

            return true;
        });
    }

    private function increment_generation(): int|false
    {
        return $this->transaction('Error incrementing DB cache generation', function (): int|false {
            $raw = Options::get_option($this->gen_key, '1');
            $current = ctype_digit((string) $raw) ? (int) $raw : 1;
            $new_gen = $current + 1;

            if (!Options::set_option($this->gen_key, (string) $new_gen)) {
                throw new \RuntimeException('DB cache generation increment write failed.');
            }

            return $new_gen;
        });
    }

    public function reset(): bool {
        $new_gen = $this->increment_generation();
        if ($new_gen === false) {
            throw new \RuntimeException('DB cache generation reset failed.');
        }

        $this->cached_gen = (int)$new_gen;

        return true;
    }

    public function prune(): bool {
        $pattern = $this->namespace . '.' . $this->get_generation() . '.%';

        if (!$this->options->delete_expired_option_like($pattern)) {
            throw new \RuntimeException('DB cache expired key cleanup failed.');
        }

        return true;
    }
}
