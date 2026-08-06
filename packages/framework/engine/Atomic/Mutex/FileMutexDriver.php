<?php
declare(strict_types=1);

namespace Engine\Atomic\Mutex;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;

class FileMutexDriver implements MutexDriverInterface
{
    protected string $base_path = '';
    protected bool $initialized = false;

    public function __construct()
    {
        $this->init_path();
    }

    protected function init_path(): void
    {
        if ($this->initialized) return;

        try {
            $atomic = App::instance();
            $storage_path = $atomic->get('TEMP') ?: \sys_get_temp_dir();
            $this->base_path = \rtrim($storage_path, '/') . '/mutex/';

            if (!\is_dir($this->base_path)) {
                if (!@\mkdir($this->base_path, 0755, true)) {
                    Log::error('[Mutex] Failed to create mutex dir: ' . $this->base_path);
                    return;
                }
            }

            if (!\is_writable($this->base_path)) {
                Log::error('[Mutex] Mutex dir not writable: ' . $this->base_path);
                return;
            }

            $this->initialized = true;
        } catch (\Throwable $e) {
            Log::error('[Mutex] File driver init failed: ' . $e->getMessage());
        }
    }

    public function acquire(string $name, string $token, int $ttl): bool
    {
        if (!$this->initialized || $ttl <= 0) return false;

        $now = \time();
        $expires_at = $now + $ttl;

        $lock_dir = $this->lock_dir($name);

        try {
            if (@\mkdir($lock_dir, 0755, false)) {
                return $this->write_meta($lock_dir, [
                    'token'      => $token,
                    'expires_at' => $expires_at,
                    'created_at' => $now,
                ]);
            }

            $meta = $this->read_meta($lock_dir);
            if ($meta === null) {
                Log::warning('[Mutex] Lock dir exists but meta unreadable: ' . $lock_dir);
                return false;
            }

            $ownerExpiry = (int)$meta['expires_at'];

            if ($ownerExpiry <= $now) {
                return $this->takeover_and_create($lock_dir, $token, $expires_at, $now);
            }

            return false;

        } catch (\Throwable $e) {
            Log::error('[Mutex] File acquire failed: ' . $e->getMessage());
            return false;
        }
    }

    public function release(string $name, string $token): bool
    {
        if (!$this->initialized) return false;

        $lock_dir = $this->lock_dir($name);

        try {
            if (!\is_dir($lock_dir)) return false;

            $meta = $this->read_meta($lock_dir);
            if ($meta === null) return false;

            if ((string)$meta['token'] !== $token) return false;

            return $this->remove_dir_recursive($lock_dir);
        } catch (\Throwable $e) {
            Log::error('[Mutex] File release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function exists(string $name): bool
    {
        if (!$this->initialized) return false;

        $lock_dir = $this->lock_dir($name);

        try {
            if (!\is_dir($lock_dir)) return false;

            $meta = $this->read_meta($lock_dir);
            if ($meta === null) return false;

            return (int)$meta['expires_at'] > \time();
        } catch (\Throwable $e) {
            Log::error('[Mutex] File exists failed: ' . $e->getMessage());
            return false;
        }
    }

    public function force_release(string $name): bool
    {
        if (!$this->initialized) return false;

        $lock_dir = $this->lock_dir($name);

        try {
            if (!\is_dir($lock_dir)) return true;
            return $this->remove_dir_recursive($lock_dir);
        } catch (\Throwable $e) {
            Log::error('[Mutex] File force_release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function get_name(): string
    {
        return 'file';
    }

    public function get_token(string $name): ?string
    {
        if (!$this->initialized) return null;

        $lock_dir = $this->lock_dir($name);

        try {
            if (!\is_dir($lock_dir)) return null;

            $meta = $this->read_meta($lock_dir);
            if ($meta === null) return null;

            if ((int)$meta['expires_at'] <= \time()) return null;

            return isset($meta['token']) ? (string)$meta['token'] : null;
        } catch (\Throwable $e) {
            Log::error('[Mutex] File get_token failed: ' . $e->getMessage());
            return null;
        }
    }

    public function is_available(): bool
    {
        return $this->initialized && \is_dir($this->base_path) && \is_writable($this->base_path);
    }

    protected function sanitize_name(string $name): string
    {
        return \preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    protected function lock_dir(string $name): string
    {
        return $this->base_path . $this->sanitize_name($name) . '.lock';
    }

    protected function meta_path(string $lock_dir): string
    {
        return $lock_dir . '/meta.json';
    }

    protected function read_meta(string $lock_dir): ?array
    {
        $path = $this->meta_path($lock_dir);
        $raw = @\file_get_contents($path);
        if ($raw === false) return null;

        $data = @\json_decode($raw, true);
        if (!\is_array($data)) return null;

        if (!isset($data['token'], $data['expires_at'])) return null;

        return $data;
    }

    protected function write_meta(string $lock_dir, array $meta): bool
    {
        $path = $this->meta_path($lock_dir);

        $tmp = $path . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(6));
        $json = \json_encode($meta);
        if ($json === false) return false;

        if (@\file_put_contents($tmp, $json, LOCK_EX) === false) {
            @\unlink($tmp);
            return false;
        }

        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            return false;
        }

        return true;
    }

    protected function takeover_and_create(string $lock_dir, string $token, int $expires_at, int $now): bool
    {
        // Atomic takeover: rename existing lock dir out of the way
        $stale = $lock_dir . '.stale.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));

        if (!@\rename($lock_dir, $stale)) {
            return false; // someone else raced, or lockDir changed
        }

        // best-effort cleanup of previous owner state
        $this->remove_dir_recursive($stale);

        // create fresh lock dir
        if (!@\mkdir($lock_dir, 0755, false)) {
            return false;
        }

        return $this->write_meta($lock_dir, [
            'token'      => $token,
            'expires_at' => $expires_at,
            'created_at' => $now,
        ]);
    }

    protected function remove_dir_recursive(string $dir): bool
    {
        if (!\is_dir($dir)) return true;

        $items = @\scandir($dir);
        if ($items === false) return false;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . '/' . $item;
            if (\is_dir($path)) {
                if (!$this->remove_dir_recursive($path)) return false;
            } else {
                if (!@\unlink($path)) return false;
            }
        }

        return @\rmdir($dir);
    }
}
