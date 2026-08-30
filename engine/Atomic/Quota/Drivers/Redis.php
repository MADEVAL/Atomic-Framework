<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota\Drivers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Quota\Interfaces\QuotaStoreInterface;
use Engine\Atomic\Quota\QuotaKeyBuilder;
use Engine\Atomic\Quota\QuotaPacingWindow;
use Engine\Atomic\Quota\QuotaResult;

final class Redis implements QuotaStoreInterface
{
    private const CONFIG_PREFIX       = 'REDIS.prefix';
    private const DEFAULT_PREFIX      = 'atomic.';
    private const KEY_PREFIX          = 'quota.';
    private const PACING_USED_SUFFIX  = '.used';
    private const LUA_DIR             = __DIR__ . DIRECTORY_SEPARATOR . 'lua';

    private \Redis $redis;
    private string $prefix;
    /** @var array<string, string> */
    private array $scripts = [];

    public function __construct(?\Redis $redis = null, ?string $prefix = null)
    {
        $this->redis = $redis ?? ConnectionManager::instance()->get_redis(true);
        $this->prefix = $prefix ?? (string)(App::instance()->get(self::CONFIG_PREFIX) ?: self::DEFAULT_PREFIX);
    }

    public function exists(string $key): bool
    {
        return (bool)$this->redis->exists($this->key($key));
    }

    public function clear(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    public function clear_scope(string $scope): void
    {
        $this->redis->del([
            $this->key(QuotaKeyBuilder::balance_key($scope)),
            $this->key(QuotaKeyBuilder::epoch_key($scope)),
        ]);
        $this->delete_matching($this->key(QuotaKeyBuilder::pacing_prefix($scope)) . '*');
        $this->delete_matching($this->key(QuotaKeyBuilder::reservation_prefix($scope)) . '*');
    }

    public function get(string $key): int
    {
        return (int)$this->redis->get($this->key($key));
    }

    public function get_string(string $key): string
    {
        $value = $this->redis->get($this->key($key));

        return $value === false ? '' : (string)$value;
    }

    public function ttl(string $key): int
    {
        return max(0, (int)$this->redis->ttl($this->key($key)));
    }

    public function set_quota(string $balance_key, string $epoch_key, int $credits, int $ttl): int
    {
        return (int)$this->eval_script('set_quota', [
            $this->key($balance_key),
            $this->key($epoch_key),
            (string)$credits,
            (string)$ttl,
            bin2hex(random_bytes(16)),
        ], 2);
    }

    public function add_quota(string $balance_key, int $credits, int $ttl): int
    {
        return (int)$this->eval_script('add_quota', [
            $this->key($balance_key),
            (string)$credits,
            (string)$ttl,
        ], 1);
    }

    public function quota_reserve(
        string $balance_key,
        string $epoch_key,
        string $reservation_key,
        string $reservation_id,
        int $cost,
        int $reservation_ttl,
        array $pacing,
        int $now
    ): QuotaResult {
        $keys = [$this->key($balance_key), $this->key($epoch_key), $this->key($reservation_key)];
        $arguments = [
            (string)$now,
            (string)$cost,
            (string)$reservation_ttl,
            $reservation_id,
            bin2hex(random_bytes(8)),
        ];
        $this->append_pacing($keys, $arguments, $pacing);

        return $this->to_result($this->eval_script('reserve', array_merge($keys, $arguments), count($keys)));
    }

    public function quota_settle(string $balance_key, string $reservation_key): int
    {
        return (int)$this->eval_script('settle', [$this->key($balance_key), $this->key($reservation_key)], 2);
    }

    public function quota_release(string $balance_key, string $epoch_key, string $reservation_key, string $reservation_id): int
    {
        return (int)$this->eval_script('release', [
            $this->key($balance_key),
            $this->key($epoch_key),
            $this->key($reservation_key),
            $reservation_id,
        ], 3);
    }

    /**
     * Append each pacing window as a zset + used-counter pair, then one
     * ARGV triple per window. The key index in the triple points at the
     * zset; Lua reads the counter from KEYS[index + 1].
     *
     * PACING_USED_SUFFIX is only how this driver names those KEYS.
     * reserve.lua stores the counter keys on the reservation hash;
     * release.lua reads that field instead of reconstructing the suffix.
     *
     * @param list<string> $keys
     * @param list<string> $arguments
     * @param list<QuotaPacingWindow> $pacing
     */
    private function append_pacing(array &$keys, array &$arguments, array $pacing): void
    {
        $triples = [];
        foreach ($pacing as $window) {
            $pacing_key = $this->key($window->key);
            $keys[] = $pacing_key;
            $keys[] = $pacing_key . self::PACING_USED_SUFFIX;
            $triples[] = (string)$window->limit;
            $triples[] = (string)$window->window;
            $triples[] = (string)(count($keys) - 1);
        }

        $arguments = array_merge($arguments, $triples);
    }

    private function to_result(mixed $payload): QuotaResult
    {
        $payload = is_array($payload) ? array_values($payload) : [];
        [$allowed, $reason, $balance, $retry_after, $limited_by] = [
            (int)($payload[0] ?? 0),
            (string)($payload[1] ?? ''),
            (int)($payload[2] ?? 0),
            (int)($payload[3] ?? -1),
            (string)($payload[4] ?? ''),
        ];

        return new QuotaResult(
            $allowed === 1,
            $reason,
            $balance,
            $retry_after < 0 ? null : $retry_after,
            $limited_by === '' ? null : $limited_by
        );
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    private function eval_script(string $name, array $arguments, int $key_count): mixed
    {
        $this->clear_redis_error();
        $result = $this->redis->eval($this->script($name), $arguments, $key_count);
        if ($result !== false) {
            return $result;
        }

        $error = $this->redis->getLastError();
        if (is_string($error) && $error !== '') {
            throw new \RuntimeException($error);
        }

        return $result;
    }

    private function clear_redis_error(): void
    {
        if (method_exists($this->redis, 'clearLastError')) {
            $this->redis->clearLastError();
        }
    }

    private function script(string $name): string
    {
        if (isset($this->scripts[$name])) {
            return $this->scripts[$name];
        }

        $path = self::LUA_DIR . '/' . $name . '.lua';
        $script = file_get_contents($path);
        if ($script === false) {
            throw new \RuntimeException("Failed to read Redis quota Lua script: $path");
        }

        return $this->scripts[$name] = $script;
    }

    private function key(string $key): string
    {
        return $this->prefix . self::KEY_PREFIX . $key;
    }

    private function delete_matching(string $pattern): void
    {
        $iterator = null;
        $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        while (($keys = $this->redis->scan($iterator, $pattern, 64)) !== false) {
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        }
    }
}
