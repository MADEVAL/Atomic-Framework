<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Core\App;
use Engine\Atomic\Mutex\FileMutexDriver;
use Engine\Atomic\Mutex\Mutex;
use Engine\Atomic\Scheduler\Runner;
use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Scheduler\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for what the scheduler reports back to the operator.
 *
 * These tests document mismatches between the documented result shape and the
 * rows produced by Scheduler::run(), and between the number of tasks the CLI
 * announces and the number of tasks it actually executes.
 */
final class SchedulerReportingTest extends TestCase
{
    protected function setUp(): void
    {
        Scheduler::reset();
        App::atomic()->set('MAINTENANCE_MODE', false);
    }

    protected function tearDown(): void
    {
        Mutex::reset();
        App::atomic()->set('MAINTENANCE_MODE', false);
        Scheduler::reset();
    }

    public function test_result_row_exposes_the_documented_error_key(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->call(static fn(): string => 'done')->description('ok-task')->every_minute();

        $rows = $scheduler->run();

        $this->assertCount(1, $rows);
        // docs/scheduler.md documents the row shape as including 'error' => null.
        $this->assertArrayHasKey(
            'error',
            $rows[0],
            'A successful result row must keep the documented shape (error => null).'
        );
        $this->assertNull($rows[0]['error']);
    }

    public function test_overlapping_task_is_not_reported_as_successful(): void
    {
        $driver = new FileMutexDriver();
        if (!$driver->is_available()) {
            $this->markTestSkipped('FileMutex driver is unavailable in this environment.');
        }
        Mutex::set_driver($driver);

        $scheduler = Scheduler::instance();
        $executed = false;

        $event = $scheduler->call(function () use (&$executed): void {
            $executed = true;
        })
            ->description('locked-task')
            ->every_minute()
            ->without_overlapping(60);

        $token = Mutex::acquire($event->get_mutex_name(), 60);
        $this->assertNotNull($token, 'Unable to hold the overlap lock for the test.');

        try {
            $rows = $scheduler->run();

            $this->assertSame(true, $rows[0]['skipped']);
            $this->assertSame(true, $event->was_skipped());
            $this->assertSame(null, $rows[0]['error']);
            $this->assertSame('', $rows[0]['output']);
            $this->assertFalse($executed, 'The task must not run while the overlap lock is held.');
            $this->assertFalse(
                $rows[0]['success'],
                'A task that was skipped because of an active overlap lock must not be reported as successful.'
            );
        } finally {
            Mutex::release($event->get_mutex_name(), (string)$token);
        }
    }

    public function test_overlapping_task_is_not_counted_as_successful(): void
    {
        $driver = new FileMutexDriver();
        if (!$driver->is_available()) {
            $this->markTestSkipped('FileMutex driver is unavailable in this environment.');
        }
        Mutex::set_driver($driver);

        $scheduler = Scheduler::instance();
        $event = $scheduler->call(static fn(): null => null)
            ->description('locked-task')
            ->every_minute()
            ->without_overlapping(60);

        $token = Mutex::acquire($event->get_mutex_name(), 60);
        $this->assertNotNull($token, 'Unable to hold the overlap lock for the test.');

        try {
            $result = (new Runner($scheduler))->run_due_tasks();

            $stream = fopen('php://temp', 'w+');
            try {
                $cli = new \Engine\Atomic\CLI\CLI();
                \Tests\Support\ReflectionHelper::set($cli, 'output', new \Engine\Atomic\CLI\Console\Output($stream));
                $cli->schedule_run();
                rewind($stream);
                $output = stream_get_contents($stream);
                $this->assertStringContainsString('[skipped] locked-task', $output);
                $this->assertStringContainsString('Completed: 0 successful, 0 failed, 1 skipped', $output);
            } finally {
                fclose($stream);
            }
            $this->assertSame(1, $result['summary']['skipped']);
            $this->assertSame(0, $result['summary']['failed']);
            $this->assertSame(1, $result['due_count']);
            $this->assertSame(
                0,
                $result['summary']['successful'],
                'schedule/run must not claim "1 successful" for a task that was skipped by the overlap lock.'
            );
        } finally {
            Mutex::release($event->get_mutex_name(), (string)$token);
        }
    }

    public function test_skip_state_resets_after_lock_release(): void
    {
        $driver = new FileMutexDriver();
        if (!$driver->is_available()) {
            $this->markTestSkipped('FileMutex driver unavailable.');
        }
        Mutex::set_driver($driver);
        $scheduler = Scheduler::instance();
        $event = $scheduler->call(static fn(): null => null)->every_minute()->without_overlapping();
        $token = Mutex::acquire($event->get_mutex_name(), 60);
        $this->assertNotNull($token);
        try {
            $this->assertSame(true, $scheduler->run()[0]['skipped']);
        } finally {
            Mutex::release($event->get_mutex_name(), (string)$token);
        }
        $row = $scheduler->run()[0];
        $this->assertSame(false, $row['skipped']);
        $this->assertSame(true, $row['success']);
        $this->assertSame(false, $event->was_skipped());
    }

    public function test_runner_formats_skipped_and_legacy_rows(): void
    {
        $runner = new Runner(Scheduler::instance());
        $row = ['id' => 'task', 'description' => null, 'duration' => 0, 'success' => false, 'skipped' => true];
        $this->assertSame('[skipped] task (0ms)', $runner->format_result($row));
        unset($row['skipped']);
        $this->assertSame('[✗] task (0ms)', $runner->format_result($row));
        $row['success'] = true;
        $this->assertSame('[✓] task (0ms)', $runner->format_result($row));
    }

    public function test_empty_summary_has_skipped_count(): void
    {
        $this->assertSame(['successful' => 0, 'failed' => 0, 'skipped' => 0],
            (new Runner(Scheduler::instance()))->run_due_tasks()['summary']);
    }

    public function test_due_filters_are_evaluated_once_per_cycle(): void
    {
        $scheduler = Scheduler::instance();
        $evaluations = 0;

        $scheduler->call(static fn(): null => null)
            ->description('filtered-task')
            ->every_minute()
            ->when(function () use (&$evaluations): bool {
                $evaluations++;
                return $evaluations === 1;
            });

        $result = (new Runner($scheduler))->run_due_tasks();

        $this->assertSame(1, $result['due_count']);
        $this->assertSame(
            1,
            $evaluations,
            'when()/skip() callbacks are evaluated by due_events() and again by run(); a stateful filter doubles its side effects '
            . 'and makes the announced due count disagree with what actually runs.'
        );
    }

    public function test_reported_due_count_matches_the_executed_tasks_in_maintenance_mode(): void
    {
        App::atomic()->set('MAINTENANCE_MODE', true);

        $scheduler = Scheduler::instance();
        $executed = false;
        $scheduler->call(function () use (&$executed): void {
            $executed = true;
        })->description('normal-task')->every_minute();

        $result = (new Runner($scheduler))->run_due_tasks();

        $this->assertFalse($executed, 'Tasks must not run in maintenance mode unless they opt in.');

        $executed_count = $result['summary']['successful'] + $result['summary']['failed'];
        $this->assertSame(
            $executed_count,
            $result['due_count'],
            'schedule/run prints "Found {$due_count} due task(s)" while only the executed tasks are listed.'
        );
    }

    public function test_worker_prints_overlapping_task_as_skipped(): void
    {
        $driver = new FileMutexDriver();
        if (!$driver->is_available()) {
            $this->markTestSkipped('FileMutex driver is unavailable in this environment.');
        }
        Mutex::set_driver($driver);

        $scheduler = Scheduler::instance();
        $event = $scheduler->call(static function (): void {})
            ->description('locked-worker-task')
            ->every_minute()
            ->without_overlapping(60);

        $token = Mutex::acquire($event->get_mutex_name(), 60);
        $this->assertNotNull($token, 'Unable to hold the overlap lock for the test.');

        $lines = [];
        try {
            (new Worker($scheduler, 1, 1))->run(function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
        } finally {
            Mutex::release($event->get_mutex_name(), (string)$token);
        }

        $this->assertContains('    [skipped] locked-worker-task', $lines);
    }
}
