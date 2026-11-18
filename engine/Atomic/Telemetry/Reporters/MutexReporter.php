<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Mutex\DatabaseMutexDriver;
use Engine\Atomic\Mutex\FileMutexDriver;
use Engine\Atomic\Mutex\MemcachedMutexDriver;
use Engine\Atomic\Mutex\Mutex;
use Engine\Atomic\Mutex\RedisMutexDriver;

class MutexReporter implements SnapshotProviderInterface
{
    /** @return array{driver:?string, drivers:list<array<string,mixed>>, locks:list<array<string,mixed>>} */
    public function report(): array
    {
        $drivers = [];
        $locks = [];

        $active = Mutex::get_driver_name();
        foreach (['redis', 'memcached', 'db', 'file'] as $name) {
            $drivers[] = ['name' => $name, 'available' => false, 'active' => $name === $active];
        }

        $active_driver = Mutex::get_driver();
        if ($active_driver !== null) {
            try {
                $locks = $this->enumerate($active, $active_driver);
                foreach ($drivers as &$row) {
                    if ($row['name'] === $active) {
                        $row['available'] = true;
                    }
                }
                unset($row);
            } catch (\Throwable $e) {
                $locks = [];
            }
        }

        return ['driver' => $active, 'drivers' => $drivers, 'locks' => $locks];
    }

    private function enumerate(string $driver, object $instance): array
    {
        return match ($driver) {
            'db' => $this->from_db($instance),
            'file' => $this->from_file($instance),
            'redis' => $this->from_redis($instance),
            default => [],
        };
    }

    private function from_db(DatabaseMutexDriver $driver): array
    {
        $conf = \Engine\Atomic\App\Models\MutexLock::resolveConfiguration();
        $db = $conf['db'];
        $rows = (array)$db->exec('SELECT name, token, expires_at, created_at FROM `' . $conf['table'] . '` ORDER BY created_at ASC LIMIT 500');

        $out = [];
        foreach ($rows as $row) {
            $created = (int)$row['created_at'];
            $expires = (int)$row['expires_at'];
            $now = time();
            $out[] = [
                'name' => (string)$row['name'],
                'token' => (string)$row['token'],
                'age' => max(0, $now - $created),
                'ttl' => max(1, $expires - $created),
                'remaining' => $expires - $now,
            ];
        }

        return $out;
    }

    private function from_file(FileMutexDriver $driver): array
    {
        $base = rtrim((string)App::instance()->get('TEMP') ?: sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mutex' . DIRECTORY_SEPARATOR;
        if (!is_dir($base)) {
            return [];
        }

        $fs = Filesystem::instance();
        $out = [];
        $entries = glob($base . '*.lock' . DIRECTORY_SEPARATOR . 'meta.json') ?: [];
        foreach (array_slice($entries, 0, 500) as $meta_path) {
            $raw = $fs->read($meta_path);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($meta)) {
                continue;
            }
            $created = (int)($meta['created_at'] ?? 0);
            $expires = (int)($meta['expires_at'] ?? 0);
            $now = time();
            $lock_dir = basename(dirname($meta_path));
            $out[] = [
                'name' => basename($lock_dir, '.lock'),
                'token' => (string)($meta['token'] ?? ''),
                'age' => $created > 0 ? max(0, $now - $created) : null,
                'ttl' => $created > 0 ? max(1, $expires - $created) : null,
                'remaining' => $expires - $now,
            ];
        }

        return $out;
    }

    private function from_redis(RedisMutexDriver $driver): array
    {
        $redis = ConnectionManager::instance()->get_redis(false);
        if (!$redis instanceof \Redis) {
            return [];
        }

        $prefix = (string)App::instance()->get('REDIS.prefix');
        $pattern = $prefix . 'mutex.*';

        $out = [];
        $iterator = null;
        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        while (($keys = $redis->scan($iterator, $pattern, 500)) !== false) {
            $keys = array_values(array_filter((array)$keys, static fn ($k): bool => is_string($k) && str_starts_with($k, $prefix . 'mutex.')));
            if ($keys === []) {
                continue;
            }
            foreach ($keys as $key) {
                if (count($out) >= 500) {
                    break 2;
                }
                $token = $redis->get($key);
                $remaining = $redis->ttl($key);
                if ($token === false || $remaining < 0) {
                    continue;
                }
                $name = substr($key, strlen($prefix . 'mutex.'));
                // Redis keeps only the remaining TTL: age and full TTL are unknown.
                $out[] = [
                    'name' => $name,
                    'token' => (string)$token,
                    'age' => null,
                    'ttl' => null,
                    'remaining' => max(0, $remaining),
                ];
            }
        }

        return $out;
    }


    public function snapshot(): array
    {
        $total = 0;
        $expired = 0;
        try {
            $data = $this->report();
        } catch (\Throwable $e) {
            $data = ['locks' => []];
        }
        foreach ($data['locks'] as $lock) {
            $total++;
            $remaining = (int)$lock['remaining'];
            if ($remaining <= 0) {
                $expired++;
            }
        }

        return ['total' => $total, 'expired' => $expired];
    }
}
