<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsStoreInterface;
use Engine\Atomic\Telemetry\Stats\TagHash;

/** Hourly counter buckets feed the time series; totals hold the never-trimmed lifetime counters. */
final class DB implements StatsStoreInterface
{
    private const BATCH_ROWS = 200;

    /** Repeats the VALUES tuple per row, in chunks: one round trip per chunk instead of one per row. */
    private function exec_batch(string $sql, array $param_rows): void
    {
        if ($param_rows === []) {
            return;
        }
        if (!preg_match('/VALUES\s*(\((?:\?\s*,\s*)*\?\))/', $sql, $match, PREG_OFFSET_CAPTURE)) {
            throw new \LogicException('exec_batch expects a single VALUES (?, ...) tuple');
        }
        [$tuple, $offset] = $match[1];
        $head = substr($sql, 0, $offset);
        $tail = substr($sql, $offset + strlen($tuple));

        $db = $this->require_db();
        foreach (array_chunk($param_rows, self::BATCH_ROWS) as $chunk) {
            $args = [];
            foreach ($chunk as $params) {
                array_push($args, ...array_values($params));
            }
            $db->exec($head . implode(', ', array_fill(0, count($chunk), $tuple)) . $tail, $args);
        }
    }

    public function increment_counters(array $rows): void
    {
        $this->exec_batch(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_COUNTERS) . '`'
            . ' (metric, tag_hash, tags, bucket_start, value) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE value = value + VALUES(value)',
            array_map(static fn (array $row): array => [
                (string)$row['metric'],
                (string)$row['tag_hash'],
                (string)$row['tags'],
                (int)$row['bucket_start'],
                (int)$row['value'],
            ], $rows),
        );
    }

    public function upsert_totals(array $rows): void
    {
        $this->exec_batch(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_TOTALS) . '`'
            . ' (metric, tag_hash, tags, value) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE value = value + VALUES(value)',
            array_map(static fn (array $row): array => [
                (string)$row['metric'],
                (string)$row['tag_hash'],
                (string)$row['tags'],
                (int)$row['value'],
            ], $rows),
        );
    }

    public function insert_events(array $rows): void
    {
        $this->exec_batch(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_EVENTS) . '`'
            . ' (metric, tag_hash, tags, data, created_at) VALUES (?, ?, ?, ?, ?)',
            array_map(static fn (array $row): array => [
                (string)$row['metric'],
                (string)$row['tag_hash'],
                (string)$row['tags'],
                (string)$row['data'],
                (int)$row['created_at'],
            ], $rows),
        );
    }

    public function upsert_exception_groups(array $rows): void
    {
        $this->exec_batch(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_EXCEPTION_GROUPS) . '`'
            . ' (fingerprint, class, message, file, line, code, level, path, dump_id, trace, first_seen, last_seen, total)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' last_seen = VALUES(last_seen), total = total + VALUES(total),'
            . ' path = VALUES(path), level = VALUES(level), code = VALUES(code),'
            . ' dump_id = COALESCE(VALUES(dump_id), dump_id), trace = COALESCE(VALUES(trace), trace)',
            array_map(static fn (array $row): array => [
                (string)$row['fingerprint'],
                (string)$row['class'],
                (string)$row['message'],
                (string)$row['file'],
                (int)$row['line'],
                (int)$row['code'],
                (string)$row['level'],
                (string)$row['path'],
                ($row['dump_id'] ?? null) !== null ? (string)$row['dump_id'] : null,
                ($row['trace'] ?? null) !== null ? (string)$row['trace'] : null,
                (int)$row['first_seen'],
                (int)$row['last_seen'],
                (int)($row['total'] ?? 1),
            ], $rows),
        );
    }

    public function upsert_rate_keys(array $rows): void
    {
        $this->exec_batch(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_RATE_KEYS) . '`'
            . ' (key_hash, key_name, strategy, rate_limit, window_seconds, first_seen, last_seen)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' strategy = VALUES(strategy), rate_limit = VALUES(rate_limit),'
            . ' window_seconds = VALUES(window_seconds), last_seen = VALUES(last_seen)',
            array_map(static fn (array $row): array => [
                (string)$row['key_hash'],
                (string)$row['key_name'],
                (string)$row['strategy'],
                (int)$row['rate_limit'],
                (int)$row['window_seconds'],
                (int)$row['first_seen'],
                (int)$row['last_seen'],
            ], $rows),
        );
    }

    public function upsert_lockouts(array $rows): void
    {
        $this->exec_batch(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_LOCKOUTS) . '`'
            . ' (identifier_hash, identifier, attempts, first_at, locked_until, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' attempts = VALUES(attempts), first_at = COALESCE(VALUES(first_at), first_at),'
            . ' locked_until = VALUES(locked_until), updated_at = VALUES(updated_at)',
            array_map(static fn (array $row): array => [
                (string)$row['identifier_hash'],
                (string)$row['identifier'],
                (int)$row['attempts'],
                ($row['first_at'] ?? null) !== null ? (int)$row['first_at'] : null,
                ($row['locked_until'] ?? null) !== null ? (int)$row['locked_until'] : null,
                (int)$row['updated_at'],
            ], $rows),
        );
    }

    public function upsert_quota_scopes(array $rows): void
    {
        $this->exec_batch(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_QUOTA_SCOPES) . '`'
            . ' (scope, tier, first_seen, last_seen) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE tier = VALUES(tier), last_seen = VALUES(last_seen)',
            array_map(static fn (array $row): array => [
                (string)$row['scope'],
                (string)$row['tier'],
                (int)$row['first_seen'],
                (int)$row['last_seen'],
            ], $rows),
        );
    }

    public function series(string $metric, ?string $tag_hash, int $from, int $to): array
    {
        $db = $this->require_db();
        [$sql, $args] = $this->tagged_where(
            'SELECT bucket_start, SUM(value) AS total FROM `' . StatsConnection::table(StatsConnection::TABLE_COUNTERS) . '`'
            . ' WHERE metric = ? AND bucket_start >= ? AND bucket_start <= ?',
            $tag_hash,
            [$metric, $from, $to],
        );
        $sql .= ' GROUP BY bucket_start';

        $out = [];
        foreach ((array)$db->exec($sql, $args) as $row) {
            $out[(int)$row['bucket_start']] = (int)$row['total'];
        }

        return $out;
    }

    public function lifetime(string $metric, ?string $tag_hash): int
    {
        $db = $this->require_db();
        [$sql, $args] = $this->tagged_where(
            'SELECT SUM(value) AS total FROM `' . StatsConnection::table(StatsConnection::TABLE_TOTALS) . '` WHERE metric = ?',
            $tag_hash,
            [$metric],
        );

        $row = $db->exec($sql, $args);
        return isset($row[0]['total']) ? (int)$row[0]['total'] : 0;
    }

    public function lifetime_by_tag(string $metric, int $limit = 50): array
    {
        $db = $this->require_db();
        $rows = $db->exec(
            'SELECT tags, value FROM `' . StatsConnection::table(StatsConnection::TABLE_TOTALS) . '`'
            . ' WHERE metric = ? AND tag_hash != ? ORDER BY value DESC LIMIT ' . max(1, $limit),
            [$metric, ''],
        );

        return array_map(static fn (array $row): array => [
            'tags' => self::decode_tags((string)$row['tags']),
            'value' => (int)$row['value'],
        ], (array)$rows);
    }

    public function top_tags(string $metric, int $from, int $to, int $limit): array
    {
        $db = $this->require_db();
        $rows = $db->exec(
            'SELECT tag_hash, MAX(tags) AS tags, SUM(value) AS total'
            . ' FROM `' . StatsConnection::table(StatsConnection::TABLE_COUNTERS) . '`'
            . ' WHERE metric = ? AND tag_hash != ? AND bucket_start >= ? AND bucket_start <= ?'
            . ' GROUP BY tag_hash ORDER BY total DESC LIMIT ' . max(1, $limit),
            [$metric, '', $from, $to],
        );

        return array_map(static fn (array $row): array => [
            'tag_hash' => (string)$row['tag_hash'],
            'tags' => self::decode_tags((string)$row['tags']),
            'total' => (int)$row['total'],
        ], (array)$rows);
    }

    public function recent_events(string $metric, ?string $tag_hash, int $limit): array
    {
        $db = $this->require_db();
        [$sql, $args] = $this->tagged_where(
            'SELECT tags, data, created_at FROM `' . StatsConnection::table(StatsConnection::TABLE_EVENTS) . '` WHERE metric = ?',
            $tag_hash,
            [$metric],
        );
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limit);

        return array_map(static fn (array $row): array => [
            'tags' => self::decode_tags((string)$row['tags']),
            'data' => self::decode_data((string)$row['data']),
            'created_at' => (int)$row['created_at'],
        ], (array)$db->exec($sql, $args));
    }

    public function series_by_tag(string $metric, int $from, int $to): array
    {
        $db = $this->require_db();
        $rows = $db->exec(
            'SELECT tag_hash, bucket_start, SUM(value) AS total'
            . ' FROM `' . StatsConnection::table(StatsConnection::TABLE_COUNTERS) . '`'
            . ' WHERE metric = ? AND tag_hash != ? AND bucket_start >= ? AND bucket_start <= ?'
            . ' GROUP BY tag_hash, bucket_start',
            [$metric, '', $from, $to],
        );

        $out = [];
        foreach ((array)$rows as $row) {
            $tag = (string)$row['tag_hash'];
            $out[$tag] ??= [];
            $out[$tag][(int)$row['bucket_start']] = (int)$row['total'];
        }

        return $out;
    }

    public function recent_rate_keys(?int $since, int $limit): array
    {
        $db = $this->require_db();
        $sql = 'SELECT key_name, strategy, rate_limit, window_seconds, first_seen, last_seen'
            . ' FROM `' . StatsConnection::table(StatsConnection::TABLE_RATE_KEYS) . '`';
        $args = [];
        if ($since !== null) {
            $sql .= ' WHERE last_seen >= ?';
            $args[] = $since;
        }
        $sql .= ' ORDER BY last_seen DESC LIMIT ' . max(1, $limit);

        return array_map(static fn (array $row): array => [
            'key_name' => (string)$row['key_name'],
            'strategy' => (string)$row['strategy'],
            'rate_limit' => (int)$row['rate_limit'],
            'window_seconds' => (int)$row['window_seconds'],
            'first_seen' => (int)$row['first_seen'],
            'last_seen' => (int)$row['last_seen'],
        ], (array)$db->exec($sql, $args));
    }

    public function quota_scopes_list(?int $since, int $limit): array
    {
        $db = $this->require_db();
        $sql = 'SELECT scope, tier, first_seen, last_seen'
            . ' FROM `' . StatsConnection::table(StatsConnection::TABLE_QUOTA_SCOPES) . '`';
        $args = [];
        if ($since !== null) {
            $sql .= ' WHERE last_seen >= ?';
            $args[] = $since;
        }
        $sql .= ' ORDER BY last_seen DESC LIMIT ' . max(1, $limit);

        return array_map(static fn (array $row): array => [
            'scope' => (string)$row['scope'],
            'tier' => (string)$row['tier'],
            'first_seen' => (int)$row['first_seen'],
            'last_seen' => (int)$row['last_seen'],
        ], (array)$db->exec($sql, $args));
    }

    public function exception_groups(?int $since, int $limit): array
    {
        $db = $this->require_db();
        $sql = 'SELECT * FROM `' . StatsConnection::table(StatsConnection::TABLE_EXCEPTION_GROUPS) . '`';
        $args = [];
        if ($since !== null) {
            $sql .= ' WHERE last_seen >= ?';
            $args[] = $since;
        }
        $sql .= ' ORDER BY last_seen DESC LIMIT ' . max(1, $limit);

        return (array)$db->exec($sql, $args);
    }

    public function exception_groups_count(): int
    {
        $db = $this->require_db();
        $row = $db->exec(
            'SELECT COUNT(*) AS total FROM `' . StatsConnection::table(StatsConnection::TABLE_EXCEPTION_GROUPS) . '`',
        );

        return isset($row[0]['total']) ? (int)$row[0]['total'] : 0;
    }

    public function exception_group(string $fingerprint): ?array
    {
        $db = $this->require_db();
        $rows = $db->exec(
            'SELECT * FROM `' . StatsConnection::table(StatsConnection::TABLE_EXCEPTION_GROUPS) . '` WHERE fingerprint = ?',
            [$fingerprint],
        );

        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    public function trim_counters(int $before): int
    {
        return (int)$this->require_db()->exec(
            'DELETE FROM `' . StatsConnection::table(StatsConnection::TABLE_COUNTERS) . '` WHERE bucket_start < ?',
            [$before],
        );
    }

    public function trim_events(int $before): int
    {
        return (int)$this->require_db()->exec(
            'DELETE FROM `' . StatsConnection::table(StatsConnection::TABLE_EVENTS) . '` WHERE created_at < ?',
            [$before],
        );
    }

    public function prune_rate_keys(int $before): int
    {
        return (int)$this->require_db()->exec(
            'DELETE FROM `' . StatsConnection::table(StatsConnection::TABLE_RATE_KEYS) . '` WHERE last_seen < ?',
            [$before],
        );
    }

    public function prune_lockouts(int $before): int
    {
        return (int)$this->require_db()->exec(
            'DELETE FROM `' . StatsConnection::table(StatsConnection::TABLE_LOCKOUTS) . '` WHERE updated_at < ?',
            [$before],
        );
    }

    private function require_db(): \DB\SQL
    {
        $db = StatsConnection::db();
        if ($db === null) {
            throw new \RuntimeException('Telemetry stats storage is not available.');
        }

        return $db;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function tagged_where(string $sql, ?string $tag_hash, array $args): array
    {
        if ($tag_hash !== null && $tag_hash !== '') {
            $sql .= ' AND tag_hash = ?';
            $args[] = $tag_hash;
        }

        return [$sql, $args];
    }

    private static function decode_tags(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function decode_data(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
