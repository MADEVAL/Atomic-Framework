<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\RateLimit\RateLimiter;
use Engine\Atomic\Telemetry\Data\RateKey;
use Engine\Atomic\Telemetry\Data\RateStrategy;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsQuery;

class RateLimitReporter implements SnapshotProviderInterface
{
    private const MAX_KEYS = 100;

    /** @return array{keys:list<array<string,mixed>>} */
    public function report(): array
    {
        return ['keys' => array_map(static fn (RateKey $key): array => $key->to_array(), $this->keys())];
    }

    /** @return list<RateKey> keys denied in the last 24 hours */
    public function keys(): array
    {
        $registry = [];
        if (StatsConnection::db() !== null) {
            try {
                $store = new \Engine\Atomic\Telemetry\Stats\Adapters\DB();
                $registry = $store->recent_rate_keys(time() - 86400, self::MAX_KEYS);
            } catch (\Throwable $e) {
                $registry = [];
            }
        }

        if ($registry === []) {
            return [];
        }

        $denied_series = StatsQuery::series('ratelimit.denied', [], '24h');
        $blocked_1h_total = (int)end($denied_series);

        try {
            $limiter = RateLimiter::from_config();
        } catch (\Throwable $e) {
            $limiter = null;
        }

        $keys = [];
        foreach ($registry as $row) {
            $strategy = RateStrategy::tryFrom($row['strategy']);
            if ($strategy === null) {
                continue;
            }
            $key = $row['key_name'];
            [$used, $reset_in] = $this->live_state($limiter, $key, $row['strategy']);
            $keys[] = new RateKey(
                key: $key,
                strategy: $strategy,
                limit: $row['rate_limit'],
                used: $used,
                reset_in: $reset_in,
                blocked_hour: $blocked_1h_total > 0 ? $this->blocked_hour($key) : 0,
            );
        }

        return $keys;
    }

    /** @return array{0:int,1:int} used counter + seconds until reset */
    private function live_state(?RateLimiter $limiter, string $key, string $strategy): array
    {
        if ($limiter === null) {
            return [0, 0];
        }
        try {
            $store = $limiter->store();
            $used = $strategy === 'sliding' ? $this->sliding_count($key) : $store->get($key);
            $reset_in = max(0, $store->ttl($key));

            return [$used, $reset_in];
        } catch (\Throwable $e) {
            return [0, 0];
        }
    }

    /** Sliding-window usage lives in a ZSET — count members directly when Redis is reachable. */
    private function sliding_count(string $key): int
    {
        try {
            $redis = ConnectionManager::instance()->get_redis(false);
            if (!$redis instanceof \Redis) {
                return 0;
            }
            $physical = (string)App::instance()->get('REDIS.prefix') . 'rate_limit.' . $key;

            return (int)$redis->zCard($physical);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function blocked_hour(string $key): int
    {
        $series = StatsQuery::series('ratelimit.denied', ['key' => $key], '24h');

        return (int)end($series);
    }

    public function snapshot(): array
    {
        $limited = 0;
        $blocked_1h = 0;
        try {
            $keys = $this->keys();
        } catch (\Throwable $e) {
            $keys = [];
        }
        foreach ($keys as $key) {
            if ($key->limit > 0 && $key->is_limited()) {
                $limited++;
            }
        }
        try {
            $series = StatsQuery::series('ratelimit.denied', [], '24h');
            $blocked_1h = (int)end($series);
        } catch (\Throwable $e) {
            $blocked_1h = 0;
        }

        return ['limited' => $limited, 'blocked_1h' => $blocked_1h];
    }
}
