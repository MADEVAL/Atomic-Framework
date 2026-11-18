<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

final class SecurityStats
{
    /** Recorded on every failed credential attempt (identifier = "ip|email"). */
    public static function login_failed(string $identifier): void
    {
        StatsRecorder::instance()->record('auth.failed', 1, ['id' => $identifier]);
    }

    public static function lockout(string $identifier, int $attempts, ?int $first_at, int $locked_until): void
    {
        StatsRecorder::instance()->record('auth.lock', 1, ['id' => $identifier]);
        StatsRecorder::instance()->observe_lockout($identifier, $attempts, $first_at, $locked_until);
    }

    public static function failure_streak(string $identifier, int $attempts, ?int $first_at): void
    {
        StatsRecorder::instance()->observe_lockout($identifier, $attempts, $first_at, null);
    }

    public static function login_success(): void
    {
        StatsRecorder::instance()->record('auth.login');
    }

}
