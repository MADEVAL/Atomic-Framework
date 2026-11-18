<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Core\App;
use Engine\Atomic\Scheduler\Runner;
use Engine\Atomic\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;

final class RunnerDueCountTest extends TestCase
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
        App::instance()->set('MAINTENANCE_MODE', false);
        parent::tearDown();
    }

    public function test_due_count_excludes_maintenance_skipped_tasks(): void
    {
        App::instance()->set('MAINTENANCE_MODE', true);

        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $plain = $scheduler->call(fn (): string => 'skip-me')->every_minute();
        $kept = $scheduler->call(fn (): string => 'run-me')->every_minute()->run_in_maintenance_mode();

        $runner = new Runner($scheduler);
        $response = $runner->run_due_tasks();

        self::assertSame(1, $response['due_count']);
        self::assertCount(1, $response['results']);
        self::assertSame(1, $response['summary']['successful']);
        self::assertSame(0, $response['summary']['failed']);
    }

    public function test_summary_counts_skipped_runs_separately(): void
    {
        App::instance()->set('MAINTENANCE_MODE', false);

        $scheduler = Scheduler::instance();
        $scheduler->clear();
        $event = $scheduler->call(fn (): string => 'ok')->every_minute()->without_overlapping(60);

        $token = \Engine\Atomic\Mutex\Mutex::acquire($event->get_mutex_name(), 30);
        if ($token === null) {
            $this->markTestSkipped('No mutex driver available - skipped-runs test cannot run.');
        }
        try {
            $response = (new Runner($scheduler))->run_due_tasks(true);
        } finally {
            \Engine\Atomic\Mutex\Mutex::release($event->get_mutex_name(), $token);
        }

        self::assertSame(1, $response['due_count']);
        self::assertSame(0, $response['summary']['successful']);
        self::assertSame(1, $response['summary']['skipped']);
        self::assertSame(0, $response['summary']['failed']);
    }
}
