<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Mutex\Mutex;
use Engine\Atomic\Scheduler\Event;
use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Scheduler\TaskTimeoutException;
use PHPUnit\Framework\TestCase;

final class SchedulerTaskKeyTest extends TestCase
{
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

    public function test_task_key_is_stable_across_instances_for_same_callback_and_cron(): void
    {
        $first = new Event([self::class, 'noop']);
        $first->daily();
        $second = new Event([self::class, 'noop']);
        $second->daily();

        self::assertSame($first->get_task_key(), $second->get_task_key());
        self::assertNotSame($first->get_id(), $second->get_id());
    }

    public function test_task_key_differs_for_different_cron_or_callback(): void
    {
        $daily = new Event([self::class, 'noop']);
        $daily->daily();
        $hourly = new Event([self::class, 'noop']);
        $hourly->hourly();
        $other = new Event([self::class, 'noop_other']);
        $other->daily();

        self::assertNotSame($daily->get_task_key(), $hourly->get_task_key());
        self::assertNotSame($daily->get_task_key(), $other->get_task_key());
    }

    public function test_exec_tasks_with_same_cron_get_distinct_identity(): void
    {
        // Every exec() wraps the command in a closure defined at the same
        // file:line, so identity must come from the command, not the closure.
        $scheduler = Scheduler::instance();
        $backup = $scheduler->exec('php -r "echo 1;"')->daily();
        $report = $scheduler->exec('php -r "echo 2;"')->daily();

        self::assertNotSame($backup->get_task_key(), $report->get_task_key());
        self::assertNotSame($backup->get_mutex_name(), $report->get_mutex_name());
        self::assertStringContainsString('echo 1', $backup->get_callback_description());
    }

    public function test_owner_and_timeout_setters(): void
    {
        $event = new Event([self::class, 'noop']);
        self::assertNull($event->get_owner());
        self::assertNull($event->get_timeout());

        $event->owner('ops@example')->timeout(120);
        self::assertSame('ops@example', $event->get_owner());
        self::assertSame(120, $event->get_timeout());
    }

    public function test_overlap_skipped_run_is_not_reported_as_success(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => 'ok')
            ->every_minute()
            ->without_overlapping(60);

        // Occupy the task's mutex so the run inside Scheduler::run() is skipped.
        $token = Mutex::acquire($event->get_mutex_name(), 30);
        if ($token === null) {
            $this->markTestSkipped('No mutex driver available - overlap test cannot run.');
        }
        try {
            $results = $scheduler->run(true, [$event]);
        } finally {
            Mutex::release($event->get_mutex_name(), $token);
        }

        self::assertCount(1, $results);
        self::assertTrue($event->was_skipped());
        self::assertSame('skipped', $results[0]['status']);
        self::assertFalse($results[0]['success']);
        self::assertTrue((bool)$results[0]['skipped']);
    }

    public function test_run_result_carries_stable_task_key(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => 'ok')->every_minute();

        $results = $scheduler->run(true, [$event]);

        self::assertCount(1, $results);
        self::assertSame($event->get_task_key(), $results[0]['task_key']);
        self::assertSame('ok', $results[0]['status']);
        self::assertTrue($results[0]['success']);
    }

    public function test_run_interrupted_by_its_budget_is_recorded_as_timeout(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(static function (): void {
            throw new TaskTimeoutException(1);
        })->every_minute()->timeout(1);

        $results = $scheduler->run(true, [$event]);

        self::assertSame('timeout', $results[0]['status']);
        self::assertFalse($results[0]['success']);
    }

    public function test_pcntl_alarm_interrupts_run_as_timeout(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl not available.');
        }
        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(static function (): void {
            $until = \microtime(true) + 3;
            while (\microtime(true) < $until) {
                \usleep(10000);
            }
        })->every_minute()->timeout(1);

        $results = $scheduler->run(true, [$event]);

        self::assertSame('timeout', $results[0]['status']);
        self::assertLessThan(2500, $results[0]['duration']);
    }

    public static function noop(): void {}
    public static function noop_other(): void {}
}
