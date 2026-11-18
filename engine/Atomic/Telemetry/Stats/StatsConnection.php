<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;

/** Returns null when MySQL is not configured or the stats tables are not migrated: statistics must never break a request. */
final class StatsConnection
{
    public const TABLE_COUNTERS = 'telemetry_counters';
    public const TABLE_TOTALS = 'telemetry_totals';
    public const TABLE_EVENTS = 'telemetry_events';
    public const TABLE_EXCEPTION_GROUPS = 'telemetry_exception_groups';
    public const TABLE_RATE_KEYS = 'telemetry_rate_keys';
    public const TABLE_LOCKOUTS = 'telemetry_lockouts';
    public const TABLE_QUOTA_SCOPES = 'telemetry_quota_scopes';
    public const TABLE_SCHEDULER_RUNS = 'scheduler_runs';
    public const TABLE_SCHEDULER_TASKS = 'scheduler_tasks';

    /** Marker column of the stats schema — its presence proves the migration ran. */
    private const PROBE_TABLE = self::TABLE_COUNTERS;

    private static ?bool $available = null;

    public static function enabled(): bool
    {
        $value = App::instance()->get('TELEMETRY_STATS_ENABLED');

        return filter_var($value ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public static function retention_days(): int
    {
        return max(1, (int)(App::instance()->get('TELEMETRY_STATS_RETENTION_DAYS') ?? 35));
    }

    public static function event_retention_days(): int
    {
        return max(1, (int)(App::instance()->get('TELEMETRY_STATS_EVENT_RETENTION_DAYS') ?? 30));
    }

    public static function db(): ?\DB\SQL
    {
        if (!self::enabled()) {
            return null;
        }
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
            $probe = $db->exec('SHOW TABLES LIKE ?', [(string)self::table(self::PROBE_TABLE)]);
            self::$available = $probe !== false && $probe !== [];
        }

        return self::$available ? $db : null;
    }

    public static function table(string $name): string
    {
        $prefix = (string)(App::instance()->get('DB_CONFIG.prefix') ?? 'atomic_');

        return $prefix . $name;
    }

    /** Test hook — forget the per-process availability probe. */
    public static function reset(): void
    {
        self::$available = null;
    }
}
