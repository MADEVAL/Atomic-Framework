<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Telemetry\Reporters\SchedulerReporter;
use PHPUnit\Framework\TestCase;

final class SchedulerReporterTest extends TestCase
{
    private const NO_SCHEDULE_FILE = __DIR__ . '/does-not-exist-schedule.php';

    protected function setUp(): void
    {
        parent::setUp();
        Scheduler::reset();
    }

    protected function tearDown(): void
    {
        Scheduler::instance()->clear();
        Scheduler::reset();
        parent::tearDown();
    }

    public function test_report_loads_app_schedule_outside_cli(): void
    {
        // Web requests skip App::register_schedule(), so the dashboard has to
        // load routes/schedule.php itself or it always shows zero tasks.
        $report = (new SchedulerReporter())->report();

        $commands = array_column($report, 'command');
        self::assertContains('Engine\Atomic\Telemetry\Stats\TrimJob::handle', $commands);

        // Idempotent: a second report does not duplicate tasks.
        self::assertCount(count($report), (new SchedulerReporter())->report());
    }

    public function test_report_describes_registered_tasks(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $scheduler->call(fn (): string => 'work')->daily()->at('03:15')->name('nightly job')->owner('ops');

        $report = (new SchedulerReporter(self::NO_SCHEDULE_FILE))->report();

        self::assertCount(1, $report);
        $task = $report[0];
        self::assertSame('nightly job', $task['name']);
        self::assertSame('15 3 * * *', $task['cron']);
        self::assertSame('ops', $task['owner']);
        self::assertTrue($task['enabled']);
        self::assertNotSame('Invalid expression', $task['human']);
        self::assertCount(3, $task['next_runs']);
        self::assertSame([], $task['runs']);
        // Enabled but never run is idle, not paused.
        self::assertSame('never run', $task['last_outcome']);
        self::assertNull($task['last_run_at']);
        self::assertNotNull($task['next_runs'][0]);
    }

    public function test_upcoming_runs_use_cron_fields_and_timezone(): void
    {
        $event = Scheduler::instance()->call(fn () => null)->cron('*/5 */2 * * *')->timezone('UTC');
        $runs = $event->get_next_run_dates(3, new \DateTimeImmutable('2026-01-01 00:54:00 UTC'));
        self::assertSame(['2026-01-01T00:55:00+00:00', '2026-01-01T02:00:00+00:00', '2026-01-01T02:05:00+00:00'], array_map(fn ($d) => $d->format(DATE_ATOM), $runs));
    }

    public function test_upcoming_runs_handle_non_uniform_calendar_and_dst(): void
    {
        $event = Scheduler::instance()->call(fn () => null)->cron('0 9 1,15 * *')->timezone('UTC');
        self::assertSame(['2026-02-15', '2026-03-01', '2026-03-15'], array_map(fn ($d) => $d->format('Y-m-d'), $event->get_next_run_dates(3, new \DateTimeImmutable('2026-02-02 UTC'))));
        $event->cron('0 3 * * *')->timezone('Europe/Berlin');
        self::assertSame(['2026-03-28T03:00:00+01:00', '2026-03-29T03:00:00+02:00', '2026-03-30T03:00:00+02:00'], array_map(fn ($d) => $d->format(DATE_ATOM), $event->get_next_run_dates(3, new \DateTimeImmutable('2026-03-28 00:00:00 UTC'))));
    }

    public function test_upcoming_runs_searches_each_annual_occurrence(): void
    {
        $event = Scheduler::instance()->call(fn () => null)->cron('0 0 1 1 *')->timezone('UTC');
        self::assertSame(['2027-01-01', '2028-01-01', '2029-01-01'], array_map(fn ($d) => $d->format('Y-m-d'), $event->get_next_run_dates(3, new \DateTimeImmutable('2026-01-02 UTC'))));
    }

    public function test_snapshot_counts(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $scheduler->call(fn (): string => 'a')->daily()->at('03:15');
        $scheduler->call(fn (): string => 'b')->hourly();

        $snapshot = (new SchedulerReporter(self::NO_SCHEDULE_FILE))->snapshot();

        self::assertSame(['total' => 2, 'failing' => 0, 'paused' => 0], $snapshot);
    }
}
