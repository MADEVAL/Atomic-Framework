<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Core\App;
use Engine\Atomic\Scheduler\Event;
use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Telemetry\Stats\FlushJob;
use Engine\Atomic\Telemetry\Stats\TrimJob;
use PHPUnit\Framework\TestCase;

final class FrameworkScheduleTest extends TestCase
{
    private mixed $stats_enabled;

    protected function setUp(): void
    {
        $this->stats_enabled = App::instance()->get('TELEMETRY_STATS_ENABLED');
        Scheduler::reset();
    }

    protected function tearDown(): void
    {
        App::instance()->set('TELEMETRY_STATS_ENABLED', $this->stats_enabled);
        Scheduler::reset();
    }

    /** @return list<Event> */
    private static function stats_jobs(): array
    {
        return array_values(array_filter(
            Scheduler::instance()->events(),
            static fn (Event $event): bool => in_array($event->get_callback_description(), [TrimJob::class . '::handle', FlushJob::class . '::handle'], true),
        ));
    }

    public function test_stats_jobs_are_registered_once_and_marked_internal(): void
    {
        App::instance()->set('TELEMETRY_STATS_ENABLED', true);

        Scheduler::instance()->register_schedule();
        Scheduler::instance()->register_schedule();

        $jobs = self::stats_jobs();
        self::assertCount(2, $jobs);
        foreach ($jobs as $job) {
            self::assertTrue($job->is_internal());
        }
    }

    public function test_stats_jobs_are_skipped_when_stats_are_disabled(): void
    {
        App::instance()->set('TELEMETRY_STATS_ENABLED', false);

        Scheduler::instance()->register_schedule();

        self::assertSame([], self::stats_jobs());
    }

    public function test_app_schedule_jobs_are_not_internal(): void
    {
        Scheduler::instance()->register_schedule();

        $app_jobs = array_filter(Scheduler::instance()->events(), static fn (Event $event): bool => !$event->is_internal());
        self::assertNotSame([], $app_jobs);
    }
}
