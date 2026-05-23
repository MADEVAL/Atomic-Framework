<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Drivers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface;
use Engine\Atomic\Cache\Helpers\Payload;
use Engine\Atomic\Core\Filesystem;

class Folder implements CacheStoreInterface, PrunableCacheStoreInterface
{
    private const META_FILE = 'namespace.meta';
    private const PRUNE_DELETE_LIMIT = 20;
    private const PRUNE_SCAN_LIMIT = 100;
    private const GC_PROBABILITY = 10000;
    private const GC_DIVISOR = 1000000;

    private string $path;
    private string $namespace;
    private string $meta_file;
    private string $lock_file;
    private Filesystem $filesystem;
    private ?int $cached_gen = null;

    public function __construct(string $path, string $namespace = 'atomic')
    {
        $base = rtrim($path, DIRECTORY_SEPARATOR . '/\\');
        if ($base === '') throw new \InvalidArgumentException('Folder cache path cannot be empty.');

        $this->namespace = $this->normalize_namespace($namespace);
        $this->path = $base . DIRECTORY_SEPARATOR . 'atomic-cache' . DIRECTORY_SEPARATOR . $this->hash($this->namespace);
        $this->meta_file = $this->path . DIRECTORY_SEPARATOR . self::META_FILE;
        $this->lock_file = $this->path . DIRECTORY_SEPARATOR . self::META_FILE . '.lock';
        $this->filesystem = Filesystem::instance();
    }

    private function normalize_namespace(string $namespace): string
    {
        $namespace = rtrim(trim($namespace), '.');
        return $namespace !== '' ? $namespace : 'atomic';
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function normalize_key(string $key): string|false
    {
        $key = ltrim($key, '.');
        return $key !== '' ? $key : false;
    }

    private function key_file(string $key): string|false
    {
        $key = $this->normalize_key($key);
        if ($key === false) {
            return false;
        }

        $hash = $this->hash($this->get_generation() . "\0" . $key);
        $shard = substr($hash, 0, 2);

        return $this->path . DIRECTORY_SEPARATOR . $shard . DIRECTORY_SEPARATOR . $hash . '.cache';
    }

    private function read_meta_file(): array|false
    {
        if (!$this->filesystem->is_file($this->meta_file) || !is_readable($this->meta_file)) {
            return false;
        }

        $raw = $this->filesystem->read($this->meta_file);
        if ($raw === false) {
            throw new \RuntimeException('Folder cache metadata read failed.');
        }

        try {
            $meta = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Folder cache metadata is invalid JSON.', 0, $e);
        }

        if (!is_array($meta)) {
            throw new \RuntimeException('Folder cache metadata must be a JSON object.');
        }

        return $meta;
    }

    private function read_generation_file(): int|false
    {
        $meta = $this->read_meta_file();
        if ($meta === false) {
            return false;
        }

        $generation = is_array($meta) ? ($meta['generation'] ?? null) : null;

        if ($generation === null) {
            return false;
        }

        if (!is_int($generation) || $generation <= 0) {
            throw new \RuntimeException('Folder cache generation metadata is invalid.');
        }

        return $generation;
    }

    private function write_meta_file(array $meta): bool
    {
        if (!$this->filesystem->ensure_dir($this->path, 0775, true)) {
            throw new \RuntimeException('Folder cache directory creation failed.');
        }

        if ($this->filesystem->write_json($this->meta_file, $meta, JSON_THROW_ON_ERROR) === false) {
            throw new \RuntimeException('Folder cache metadata write failed.');
        }

        return true;
    }

    private function write_generation_file(int $generation): bool
    {
        $meta = $this->read_meta_file();
        if ($meta === false) {
            $meta = [];
        }

        $meta['generation'] = $generation;

        $this->write_meta_file($meta);

        return true;
    }

    private function with_generation_lock(callable $callback): mixed
    {
        if (!$this->filesystem->ensure_dir($this->path, 0775, true)) {
            throw new \RuntimeException('Folder cache directory creation failed.');
        }

        $handle = @fopen($this->lock_file, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Folder cache generation lock open failed.');
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Folder cache generation lock failed.');
            }

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public function get_generation(): int
    {
        if ($this->cached_gen !== null) {
            return $this->cached_gen;
        }

        $generation = $this->read_generation_file();
        if ($generation === false) {
            $generation = $this->with_generation_lock(function (): int|false {
                $generation = $this->read_generation_file();
                if ($generation !== false) {
                    return $generation;
                }

                return $this->write_generation_file(1) ? 1 : false;
            });

            if ($generation === false) {
                throw new \RuntimeException('Folder cache generation initialization failed.');
            }
        }

        return $this->cached_gen = $generation;
    }

    public function flush_local_cache(): void
    {
        $this->cached_gen = null;
    }

    public function exists(string $key, mixed &$val = null): array|false
    {
        $file = $this->key_file($key);
        if ($file === false) {
            return false;
        }

        if (!$this->filesystem->is_file($file) || !is_readable($file)) {
            return false;
        }

        $raw = $this->filesystem->read($file);
        if ($raw === false) {
            throw new \RuntimeException('Folder cache key read failed.');
        }

        $payload = Payload::unpack($raw);
        if ($payload === false || Payload::is_expired($payload)) {
            if (!$this->filesystem->delete($file)) {
                throw new \RuntimeException('Folder cache key cleanup failed.');
            }

            $val = null;
            return false;
        }

        $val = $payload['value'];
        return Payload::meta($payload);
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->filesystem->ensure_dir($this->path, 0775, true)) {
            throw new \RuntimeException('Folder cache directory creation failed.');
        }

        $file = $this->key_file($key);
        if ($file === false) {
            return false;
        }

        if (!$this->filesystem->ensure_dir(dirname($file), 0775, true)) {
            throw new \RuntimeException('Folder cache shard directory creation failed.');
        }

        $written = $this->filesystem->write_atomic($file, Payload::pack($value, $ttl)) !== false;
        if (!$written) {
            throw new \RuntimeException('Folder cache key write failed.');
        }

        if ($written && $this->should_run_gc()) {
            $this->prune_expired();
        }

        return $written;
    }

    public function get(string $key): mixed
    {
        return $this->exists($key, $value) ? $value : false;
    }

    public function clear(string $key): bool
    {
        $file = $this->key_file($key);
        if ($file === false || !$this->filesystem->is_file($file)) {
            return false;
        }

        if (!$this->filesystem->delete($file)) {
            throw new \RuntimeException('Folder cache key delete failed.');
        }

        return true;
    }

    public function reset(): bool
    {
        $generation = $this->with_generation_lock(function (): int|false {
            $current = $this->read_generation_file();
            if ($current === false) {
                $current = 1;
            }

            $generation = $current + 1;
            return $this->write_generation_file($generation) ? $generation : false;
        });

        if ($generation === false) {
            throw new \RuntimeException('Folder cache generation reset failed.');
        }

        $this->cached_gen = $generation;

        return true;
    }

    public function prune(): bool
    {
        $this->prune_expired_from_shards(null, null, false);
        return true;
    }

    private function should_run_gc(): bool
    {
        return random_int(1, self::GC_DIVISOR) <= self::GC_PROBABILITY;
    }

    private function shard_names(): array
    {
        $shards = [];
        for ($i = 0; $i <= 255; $i++) {
            $shards[] = sprintf('%02x', $i);
        }

        return $shards;
    }

    private function next_prune_cursor(): string
    {
        $meta = $this->read_meta_file();
        $cursor = is_array($meta) ? ($meta['prune_cursor'] ?? null) : null;

        if (!is_string($cursor) || !preg_match('/^[a-f0-9]{2}$/', $cursor)) {
            return '00';
        }

        return sprintf('%02x', (hexdec($cursor) + 1) % 256);
    }

    private function save_prune_cursor(string $cursor): void
    {
        $this->with_generation_lock(function () use ($cursor): bool {
            $meta = $this->read_meta_file();
            if ($meta === false) {
                $meta = ['generation' => 1];
            }

            $meta['generation'] = $meta['generation'] ?? 1;
            $meta['prune_cursor'] = $cursor;

            return $this->write_meta_file($meta);
        });
    }

    private function ordered_shards(string $start): array
    {
        $shards = $this->shard_names();
        $offset = array_search($start, $shards, true);
        if ($offset === false) {
            return $shards;
        }

        return array_merge(array_slice($shards, $offset), array_slice($shards, 0, $offset));
    }

    private function prune_expired(): void
    {
        $this->prune_expired_from_shards(self::PRUNE_SCAN_LIMIT, self::PRUNE_DELETE_LIMIT, true);
    }

    private function prune_expired_from_shards(?int $scan_limit, ?int $delete_limit, bool $rotate): void
    {
        $scanned = 0;
        $deleted = 0;
        $last_shard = null;
        $start = $rotate ? $this->next_prune_cursor() : '00';

        foreach ($this->ordered_shards($start) as $shard) {
            $last_shard = $shard;
            $files = $this->filesystem->glob($this->path . DIRECTORY_SEPARATOR . $shard . DIRECTORY_SEPARATOR . '*.cache') ?: [];

            foreach ($files as $file) {
                if (($scan_limit !== null && $scanned >= $scan_limit) || ($delete_limit !== null && $deleted >= $delete_limit)) {
                    if ($rotate && $last_shard !== null) {
                        $this->save_prune_cursor($last_shard);
                    }
                    return;
                }

                if (!$this->filesystem->is_file($file) || !is_readable($file)) {
                    continue;
                }

                $scanned++;

                $raw = $this->filesystem->read($file);
                if ($raw === false) {
                    throw new \RuntimeException('Folder cache prune read failed.');
                }

                $payload = Payload::unpack($raw);
                if ($payload === false || Payload::is_expired($payload)) {
                    if (!$this->filesystem->delete($file)) {
                        throw new \RuntimeException('Folder cache prune delete failed.');
                    }

                    $deleted++;
                }
            }
        }

        if ($rotate && $last_shard !== null) {
            $this->save_prune_cursor($last_shard);
        }
    }
}
