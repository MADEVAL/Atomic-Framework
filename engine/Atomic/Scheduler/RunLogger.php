<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Telemetry\Stats\StatsConnection;

/**
 * Scheduler run persistence: one row per executed task in
 * `{prefix}scheduler_runs`, plus the `{prefix}scheduler_tasks` registry that
 * carries the stable task identity, owner annotation and the persisted
 * enabled/paused flag. Failure-isolated — a missing table or broken
 * connection never affects task execution.
 */
final class RunLogger
{
    private static ?bool $available = null;

    /** Persist one run result and refresh the task registry row. */
    public static function record(Event $event, array $result): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }

        try {
            $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS);
            $db->exec(
                'INSERT INTO `' . $table . '`'
                . ' (task_key, name, cron, status, duration_ms, error, started_at, finished_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string)$result['task_key'],
                    mb_substr((string)($result['description'] ?? ''), 0, 255),
                    (string)$result['expression'],
                    (string)$result['status'],
                    (float)$result['duration'],
                    $result['error'] !== null ? mb_substr((string)$result['error'], 0, 2000) : null,
                    self::parse_time((string)$result['started_at']),
                    self::parse_time((string)$result['finished_at']),
                ],
            );

            self::upsert_task($event);
        } catch (\Throwable $e) {
            \Engine\Atomic\Core\Log::debug('[Scheduler] Run persistence skipped: ' . $e->getMessage());
        }
    }

    /** @return array<string, true> task keys whose persisted enabled flag is 0 */
    public static function paused_keys(): array
    {
        $db = self::db();
        if ($db === null) {
            return [];
        }

        try {
            $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_TASKS);
            $rows = $db->exec('SELECT task_key FROM `' . $table . '` WHERE enabled = 0') ?: [];
            $paused = [];
            foreach ($rows as $row) {
                $paused[(string)$row['task_key']] = true;
            }

            return $paused;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string, array{task_key:string,name:?string,cron:string,owner:?string,enabled:bool}> */
    public static function tasks(): array
    {
        $db = self::db();
        if ($db === null) {
            return [];
        }

        try {
            $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_TASKS);
            $rows = $db->exec('SELECT task_key, name, cron, owner, enabled FROM `' . $table . '`') ?: [];
            $tasks = [];
            foreach ($rows as $row) {
                $tasks[(string)$row['task_key']] = [
                    'task_key' => (string)$row['task_key'],
                    'name' => $row['name'] !== null ? (string)$row['name'] : null,
                    'cron' => (string)$row['cron'],
                    'owner' => $row['owner'] !== null ? (string)$row['owner'] : null,
                    'enabled' => (int)$row['enabled'] === 1,
                ];
            }

            return $tasks;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return list<array{status:string,duration_ms:float,error:?string,started_at:int}> newest first */
    public static function last_runs(string $task_key, int $limit = 20): array
    {
        $db = self::db();
        if ($db === null) {
            return [];
        }

        try {
            $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS);
            $rows = $db->exec(
                'SELECT status, duration_ms, error, started_at FROM `' . $table . '`'
                . ' WHERE task_key = ? ORDER BY started_at DESC, id DESC LIMIT ' . max(1, $limit),
                [$task_key],
            ) ?: [];

            return array_map(static fn (array $row): array => [
                'status' => (string)$row['status'],
                'duration_ms' => (float)$row['duration_ms'],
                'error' => $row['error'] !== null ? (string)$row['error'] : null,
                'started_at' => (int)$row['started_at'],
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Trim run history older than the given timestamp. */
    public static function trim(int $before): int
    {
        $db = self::db();
        if ($db === null) {
            return 0;
        }

        try {
            $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS);

            return (int)$db->exec('DELETE FROM `' . $table . '` WHERE started_at < ?', [$before]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Test hook — forget the per-process availability probe. */
    public static function reset(): void
    {
        self::$available = null;
    }

    private static function upsert_task(Event $event): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_TASKS);
        $now = time();
        // The enabled flag is intentionally NOT refreshed on duplicate — it
        // carries the persisted pause decision made between runs.
        $db->exec(
            'INSERT INTO `' . $table . '` (task_key, name, cron, owner, enabled, first_seen, last_seen)'
            . ' VALUES (?, ?, ?, ?, 1, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name), cron = VALUES(cron),'
            . ' owner = COALESCE(VALUES(owner), owner), last_seen = VALUES(last_seen)',
            [
                $event->get_task_key(),
                mb_substr((string)($event->get_description() ?? ''), 0, 255),
                (string)$event->get_expression(),
                $event->get_owner(),
                $now,
                $now,
            ],
        );
    }

    /**
     * The scheduler tables live on the default connection and are scheduler
     * state, not statistics: the persisted pause flag must hold even when
     * TELEMETRY_STATS_ENABLED is off or the statistics tables are missing.
     */
    private static function db(): ?\DB\SQL
    {
        if (self::$available === false) {
            return null;
        }

        try {
            $db = ConnectionManager::instance()->get_db(false);
        } catch (\Throwable $e) {
            $db = null;
        }
        if (!$db instanceof \DB\SQL) {
            self::$available = false;

            return null;
        }

        if (self::$available === null) {
            try {
                $probe = $db->exec('SHOW TABLES LIKE ?', [StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS)]);
                self::$available = $probe !== false && $probe !== [];
            } catch (\Throwable $e) {
                self::$available = false;

                return null;
            }
        }

        return self::$available ? $db : null;
    }

    private static function parse_time(string $value): int
    {
        if ($value === '') {
            return time();
        }
        $ts = strtotime($value);

        return $ts === false ? time() : $ts;
    }
}
