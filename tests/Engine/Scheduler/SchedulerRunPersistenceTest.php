<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Core\App;
use Engine\Atomic\Scheduler\RunLogger;
use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\TrimJob;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class SchedulerRunPersistenceTest extends StatsDbTestCase
{
    protected function tearDown(): void
    {
        Scheduler::instance()->clear();
        parent::tearDown();
    }

    public function test_successful_run_is_persisted_with_task_registry_row(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => 'done')
            ->every_minute()
            ->name('persisted task')
            ->owner('ops');

        $results = $scheduler->run(true, [$event]);
        self::assertCount(1, $results);
        self::assertSame('ok', $results[0]['status']);

        $task_key = $event->get_task_key();
        $runs = RunLogger::last_runs($task_key, 20);
        self::assertNotEmpty($runs);
        self::assertSame('ok', $runs[0]['status']);

        $tasks = RunLogger::tasks();
        self::assertArrayHasKey($task_key, $tasks);
        self::assertSame('persisted task', $tasks[$task_key]['name']);
        self::assertSame('ops', $tasks[$task_key]['owner']);
        self::assertTrue($tasks[$task_key]['enabled']);
    }

    public function test_failed_run_persists_error_and_status(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => throw new \RuntimeException('task exploded'))
            ->every_minute();

        $results = $scheduler->run(true, [$event]);
        self::assertSame('failed', $results[0]['status']);
        self::assertSame('task exploded', $results[0]['error']);

        $runs = RunLogger::last_runs($event->get_task_key(), 20);
        self::assertNotEmpty($runs);
        self::assertSame('failed', $runs[0]['status']);
    }

    public function test_run_history_is_trimmed(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => 'ok')->every_minute();

        $scheduler->run(true, [$event]);

        $db = StatsConnection::db();
        self::assertNotNull($db);
        $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS);

        // Backdate the row, then trim everything older than "now".
        $db->exec('UPDATE `' . $table . '` SET started_at = ? WHERE task_key = ?', [time() - 40 * 86400, $event->get_task_key()]);
        $before = (int)$db->exec('SELECT COUNT(*) AS c FROM `' . $table . '` WHERE task_key = ?', [$event->get_task_key()])[0]['c'];
        self::assertSame(1, $before);

        RunLogger::trim(time());

        $after = (int)$db->exec('SELECT COUNT(*) AS c FROM `' . $table . '` WHERE task_key = ?', [$event->get_task_key()])[0]['c'];
        self::assertSame(0, $after);
    }

    public function test_paused_task_is_not_due(): void
    {
        $event = $this->persisted_event();
        $this->pause($event->get_task_key());

        self::assertSame([], Scheduler::instance()->due_events(true));
    }

    public function test_paused_task_stays_paused_when_statistics_are_disabled(): void
    {
        $event = $this->persisted_event();
        $this->pause($event->get_task_key());

        $this->disable_statistics();

        self::assertSame([], Scheduler::instance()->due_events(true));
    }

    public function test_runs_are_persisted_when_statistics_are_disabled(): void
    {
        $this->disable_statistics();
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => 'ok')->every_minute();

        $scheduler->run(true, [$event]);

        self::assertNotEmpty(RunLogger::last_runs($event->get_task_key(), 20));
    }

    public function test_trim_job_trims_runs_when_statistics_are_disabled(): void
    {
        $event = $this->persisted_event();
        $db = StatsConnection::db();
        self::assertNotNull($db);
        $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_RUNS);
        $db->exec('UPDATE `' . $table . '` SET started_at = ? WHERE task_key = ?', [time() - 400 * 86400, $event->get_task_key()]);

        $this->disable_statistics();
        (new TrimJob())->handle();

        $left = (int)$db->exec('SELECT COUNT(*) AS c FROM `' . $table . '` WHERE task_key = ?', [$event->get_task_key()])[0]['c'];
        self::assertSame(0, $left);
    }

    private function persisted_event(): \Engine\Atomic\Scheduler\Event
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => 'ok')->every_minute();
        $scheduler->run(true, [$event]);

        return $event;
    }

    private function pause(string $task_key): void
    {
        $db = StatsConnection::db();
        self::assertNotNull($db);
        $table = StatsConnection::table(StatsConnection::TABLE_SCHEDULER_TASKS);
        $db->exec('UPDATE `' . $table . '` SET enabled = 0 WHERE task_key = ?', [$task_key]);
    }

    private function disable_statistics(): void
    {
        App::instance()->set('TELEMETRY_STATS_ENABLED', false);
        StatsConnection::reset();
        RunLogger::reset();
    }
}
