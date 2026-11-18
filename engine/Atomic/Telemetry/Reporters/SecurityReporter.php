<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Security\PasswordPolicy;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsQuery;

class SecurityReporter implements SnapshotProviderInterface
{
    private const MAX_SESSIONS = 200;

    /** @return array<string,mixed> */
    public function report(string $range = '24h'): array
    {
        return [
            'sessions' => $this->sessions(),
            'lockouts' => $this->lockouts(),
            'failed' => StatsQuery::series('auth.failed', [], $range),
            'lock_events' => StatsQuery::series('auth.lock', [], $range),
            'top_failed' => $this->top_failed($range),
            'lifetime' => [
                'failed_logins' => StatsQuery::lifetime('auth.failed'),
                'lockouts' => StatsQuery::lifetime('auth.lock'),
            ],
            'policy' => $this->policy(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function sessions(): array
    {
        $atomic = App::instance();
        $driver = (string)($atomic->get('SESSION_CONFIG.driver') ?? 'db');
        $now = time();
        $current_id = session_id();
        $out = [];

        try {
            if ($driver === 'redis') {
                $out = $this->redis_sessions($now);
            } else {
                $out = $this->db_sessions($now);
            }
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($out as &$row) {
            $row['current'] = $current_id !== '' && $row['session_id'] === $current_id;
        }
        unset($row);

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function db_sessions(int $now): array
    {
        $conf = \Engine\Atomic\Session\Models\Session::resolveConfiguration();
        $db = $conf['db'];
        $table = $conf['table'];

        $columns = [];
        foreach ((array)$db->exec('SHOW COLUMNS FROM `' . $table . '`') ?: [] as $column) {
            $columns[(string)($column['Field'] ?? '')] = true;
        }
        $has_owner = isset($columns['user_uuid'], $columns['created_at']);

        $sql = 'SELECT session_id, ip, agent, stamp' . ($has_owner ? ', user_uuid, created_at' : '') . ', data'
            . ' FROM `' . $table . '`'
            . ' WHERE session_id NOT LIKE \'.revoked.%\''
            . ' ORDER BY stamp DESC LIMIT ' . self::MAX_SESSIONS;
        $rows = (array)$db->exec($sql);

        $out = [];
        foreach ($rows as $row) {
            $payload = (string)($row['data'] ?? '');
            $user = $has_owner ? ($row['user_uuid'] ?: null) : self::payload_string($payload, 'user_uuid');
            $created_at = $has_owner ? (int)($row['created_at'] ?? 0) : (int)(self::payload_value($payload, 'created_at') ?? 0);
            if ($created_at === 0) {
                $created_at = (int)($row['stamp'] ?? $now);
            }
            $out[] = [
                'session_id' => (string)$row['session_id'],
                'user' => $user,
                'ip' => (string)($row['ip'] ?? ''),
                'agent' => mb_substr((string)($row['agent'] ?? ''), 0, 80),
                'created_ago' => max(0, $now - $created_at),
                'last_ago' => max(0, $now - (int)($row['stamp'] ?? $now)),
                'impersonating' => self::payload_string($payload, 'admin_uuid'),
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function redis_sessions(int $now): array
    {
        $redis = ConnectionManager::instance()->get_redis(false);
        if (!$redis instanceof \Redis) {
            return [];
        }
        $prefix = (string)App::instance()->get('SESSION_CONFIG.redis_prefix');

        $out = [];
        $iterator = null;
        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        while (($keys = $redis->scan($iterator, $prefix . '*', 200)) !== false && count($out) < self::MAX_SESSIONS) {
            $keys = array_values(array_filter((array)$keys, static fn ($k): bool => is_string($k) && !str_starts_with(substr($k, strlen($prefix)), 'revoked.')));
            if ($keys === []) {
                continue;
            }
            foreach ($keys as $key) {
                $raw = $redis->get($key);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($decoded)) {
                    continue;
                }
                $payload = (string)($decoded['data'] ?? '');
                $created_at = (int)($decoded['created_at'] ?? ($decoded['stamp'] ?? $now));
                $out[] = [
                    'session_id' => substr((string)$key, strlen($prefix)),
                    'user' => $decoded['user_uuid'] ?? self::payload_string($payload, 'user_uuid'),
                    'ip' => (string)($decoded['ip'] ?? ''),
                    'agent' => mb_substr((string)($decoded['agent'] ?? ''), 0, 80),
                    'created_ago' => max(0, $now - $created_at),
                    'last_ago' => max(0, $now - (int)($decoded['stamp'] ?? $now)),
                    'impersonating' => $decoded['admin_uuid'] ?? self::payload_string($payload, 'admin_uuid'),
                ];
                if (count($out) >= self::MAX_SESSIONS) {
                    break;
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['last_ago'] <=> $a['last_ago']);

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function lockouts(): array
    {
        $db = StatsConnection::db();
        if ($db === null) {
            return [];
        }
        try {
            $rows = (array)$db->exec(
                'SELECT identifier, attempts, first_at, locked_until, updated_at FROM `'
                . StatsConnection::table(StatsConnection::TABLE_LOCKOUTS) . '`'
                . ' ORDER BY updated_at DESC LIMIT 100',
            );
        } catch (\Throwable $e) {
            return [];
        }

        $max_attempts = max(1, (int)App::instance()->get('AUTH_RATE_LIMIT.max_attempts'));
        $now = time();
        $out = [];
        foreach ($rows as $row) {
            $locked_until = $row['locked_until'] !== null ? (int)$row['locked_until'] : 0;
            $out[] = [
                'identifier' => (string)$row['identifier'],
                'count' => (int)$row['attempts'],
                'max' => $max_attempts,
                'first_ago' => $row['first_at'] !== null ? max(0, $now - (int)$row['first_at']) : null,
                'locked' => $locked_until > $now,
                'remaining' => max(0, $locked_until - $now),
            ];
        }

        return $out;
    }

    /** @return array<string,int> identifier => failures in range */
    private function top_failed(string $range): array
    {
        $out = [];
        foreach (StatsQuery::top_tags('auth.failed', $range, 10) as $row) {
            $identifier = (string)($row['tags']['id'] ?? '');
            if ($identifier !== '') {
                $out[$identifier] = $row['total'];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        $atomic = App::instance();
        $policy = PasswordPolicy::default();

        return [
            'lockout' => [
                'max_attempts' => (int)$atomic->get('AUTH_RATE_LIMIT.max_attempts'),
                'window' => (int)$atomic->get('AUTH_RATE_LIMIT.window_seconds'),
                'lockout' => (int)$atomic->get('AUTH_RATE_LIMIT.lockout_seconds'),
            ],
            'password' => [
                'min_length' => $policy->min_length(),
                'mixed_case' => $policy->require_mixed_case(),
                'numbers' => $policy->require_numbers(),
                'symbols' => $policy->require_symbols(),
            ],
            'csrf' => ['key' => 'SESSION.csrf_token'],
            'session' => [
                'driver' => (string)($atomic->get('SESSION_CONFIG.driver') ?? 'db'),
                'lifetime' => (int)($atomic->get('SESSION_CONFIG.lifetime') ?? 0),
            ],
        ];
    }

    /** Pull a scalar value out of a PHP-serialized session payload without full unserialization. */
    private static function payload_value(string $payload, string $key): mixed
    {
        if ($payload === '') {
            return null;
        }
        $escaped = preg_quote($key, '/');
        if (preg_match('/"' . $escaped . '";(?:i|s|d):(\d+)(?::"([^"]*)")?/', $payload, $m)) {
            if (isset($m[2])) {
                return $m[2];
            }

            return (int)$m[1];
        }

        return null;
    }

    private static function payload_string(string $payload, string $key): ?string
    {
        $value = self::payload_value($payload, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function snapshot(): array
    {
        $locked = 0;
        $failed_24h = 0;
        $sessions = 0;
        try {
            $data = $this->report('24h');
        } catch (\Throwable $e) {
            $data = ['sessions' => [], 'lockouts' => []];
        }
        foreach ($data['lockouts'] as $lockout) {
            if ($lockout['locked']) {
                $locked++;
            }
        }
        $sessions = count($data['sessions']);
        try {
            $failed_24h = array_sum(StatsQuery::series('auth.failed', [], '24h'));
        } catch (\Throwable $e) {
            $failed_24h = 0;
        }

        return ['locked' => $locked, 'failed_24h' => $failed_24h, 'sessions' => $sessions];
    }
}
