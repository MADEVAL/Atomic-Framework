<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Telemetry\Reporters\SchedulerReporter;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class SchedulerReporterOutcomeTest extends StatsDbTestCase
{
    public function test_scheduler_reports_unknown_and_stale_outcomes_without_health_verdict(): void
    {
        Scheduler::reset();
        $event = Scheduler::instance()->call(fn () => null)->name('exact outcome');
        $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS);
        $db = StatsConnection::db();
        $started = time() - 86400 * 100;
        $db->exec('INSERT INTO ' . $table . ' (task_key, name, cron, status, duration_ms, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$event->get_task_key(), 'exact outcome', '* * * * *', 'unexpected', 10, $started, $started]);
        try {
            $task = (new SchedulerReporter(__DIR__ . '/missing.php'))->report()[0];
            self::assertSame('unknown', $task['last_outcome']);
            self::assertSame('unexpected', $task['last_status']);
            self::assertSame($started, $task['last_run_at']);
            self::assertTrue($task['enabled']);
        } finally {
            Scheduler::reset();
        }
    }

    public function test_runs_carry_start_time_duration_and_error_for_the_drawer(): void
    {
        Scheduler::reset();
        $event = Scheduler::instance()->call(fn () => null)->name('failing task');
        $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS);
        $started = time() - 120;
        StatsConnection::db()->exec('INSERT INTO ' . $table . ' (task_key, name, cron, status, duration_ms, error, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$event->get_task_key(), 'failing task', '* * * * *', 'failed', 1250.5, 'Connection refused', $started, $started + 2]);
        try {
            $run = (new SchedulerReporter(__DIR__ . '/missing.php'))->report()[0]['runs'][0];
            self::assertSame('failed', $run['s']);
            self::assertSame($started, $run['at']);
            self::assertSame(1250.5, $run['ms']);
            self::assertSame('Connection refused', $run['error']);
        } finally {
            Scheduler::reset();
        }
    }
}
