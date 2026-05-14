<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Scheduler\Event;
use Engine\Atomic\Scheduler\Runner;
use Engine\Atomic\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;

class SchedulerEventTest extends TestCase
{
    public function test_event_has_uuid_id(): void
    {
        $event = new Event(function() {});
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $event->get_id()
        );
    }

    public function test_event_description(): void
    {
        $event = new Event(function() {});
        $event->description('My event');
        $this->assertSame('My event', $event->get_description());
    }

    public function test_event_name_is_alias_for_description(): void
    {
        $event = new Event(function() {});
        $event->name('Named event');
        $this->assertSame('Named event', $event->get_description());
    }

    public function test_event_fluent_chaining(): void
    {
        $event = new Event(function() {});
        $result = $event->description('test')
            ->when(true)
            ->skip(false);
        $this->assertSame($event, $result);
    }

    public function test_invalid_cron_expression_is_rejected(): void
    {
        $event = new Event(function() {});

        $this->expectException(\InvalidArgumentException::class);
        $event->cron('foo * * * *');
    }

    public function test_daily_at_modifier_sets_minute_and_hour(): void
    {
        $event = new Event(function() {});

        $event->daily()->at('03:15');

        $this->assertSame('15 3 * * *', $event->get_expression());
    }

    public function test_monthly_at_modifier_preserves_day_of_month(): void
    {
        $event = new Event(function() {});

        $event->monthly()->at('09:30');

        $this->assertSame('30 9 1 * *', $event->get_expression());
    }

    public function test_monthly_on_at_modifier_preserves_configured_day_of_month(): void
    {
        $event = new Event(function() {});

        $event->monthly_on(15)->at('22:05');

        $this->assertSame('5 22 15 * *', $event->get_expression());
    }

    public function test_weekly_at_modifier_preserves_day_of_week(): void
    {
        $event = new Event(function() {});

        $event->weekly()->at('08:45');

        $this->assertSame('45 8 * * 0', $event->get_expression());
    }

    public function test_at_modifier_rejects_invalid_time_format(): void
    {
        $event = new Event(function() {});

        $this->expectException(\InvalidArgumentException::class);
        $event->daily()->at('3pm');
    }

    public function test_at_modifier_rejects_out_of_range_hour(): void
    {
        $event = new Event(function() {});

        $this->expectException(\InvalidArgumentException::class);
        $event->daily()->at('24:00');
    }

    public function test_at_modifier_rejects_out_of_range_minute(): void
    {
        $event = new Event(function() {});

        $this->expectException(\InvalidArgumentException::class);
        $event->daily()->at('12:60');
    }

    public function test_event_run_closure(): void
    {
        $called = false;
        $event = new Event(function() use (&$called) {
            $called = true;
            return 42;
        });
        $event->every_minute();
        $result = $event->run();
        $this->assertTrue($called);
        $this->assertSame(42, $result);
        $this->assertSame(0, $event->get_exit_code());
    }

    public function test_event_with_parameters(): void
    {
        $event = new Event(function($a, $b) {
            return $a + $b;
        }, [10, 20]);
        $event->every_minute();
        $result = $event->run();
        $this->assertSame(30, $result);
    }

    public function test_event_captures_output(): void
    {
        $event = new Event(function() {
            echo 'Hello from event';
        });
        $event->every_minute();
        $event->run();
        $this->assertSame('Hello from event', $event->get_output());
    }

    public function test_event_failure_sets_exit_code_1(): void
    {
        $event = new Event(function() {
            throw new \RuntimeException('boom');
        });
        $event->every_minute();
        $this->expectException(\RuntimeException::class);
        try {
            $event->run();
        } finally {
            $this->assertSame(1, $event->get_exit_code());
        }
    }

    public function test_before_and_after_callbacks(): void
    {
        $order = [];
        $event = new Event(function() use (&$order) {
            $order[] = 'main';
        });
        $event->every_minute();
        $event->before(function() use (&$order) { $order[] = 'before'; });
        $event->after(function() use (&$order) { $order[] = 'after'; });
        $event->run();
        $this->assertSame(['before', 'main', 'after'], $order);
    }

    public function test_on_success_callback(): void
    {
        $successCalled = false;
        $event = new Event(function() { return 'ok'; });
        $event->every_minute();
        $event->on_success(function() use (&$successCalled) { $successCalled = true; });
        $event->run();
        $this->assertTrue($successCalled);
    }

    public function test_on_failure_callback(): void
    {
        $failException = null;
        $event = new Event(function() { throw new \RuntimeException('fail'); });
        $event->every_minute();
        $event->on_failure(function(\Throwable $e) use (&$failException) {
            $failException = $e;
        });
        try { $event->run(); } catch (\RuntimeException $e) {}
        $this->assertInstanceOf(\RuntimeException::class, $failException);
    }

    public function test_filters_pass(): void
    {
        $event = new Event(function() {});
        $event->when(true);
        $this->assertTrue($event->filters_pass());

        $event2 = new Event(function() {});
        $event2->when(false);
        $this->assertFalse($event2->filters_pass());
    }

    public function test_skip_rejects(): void
    {
        $event = new Event(function() {});
        $event->skip(true);
        $this->assertFalse($event->filters_pass());

        $event2 = new Event(function() {});
        $event2->skip(false);
        $this->assertTrue($event2->filters_pass());
    }

    public function test_get_mutex_name(): void
    {
        $event = new Event(function() {});
        $event->every_minute();
        $name = $event->get_mutex_name();
        $this->assertStringStartsWith('schedule-', $name);
    }

    public function test_get_summary(): void
    {
        $event = new Event(function() {});
        $event->every_minute();
        $event->description('Test event');
        $summary = $event->get_summary();
        $this->assertArrayHasKey('id', $summary);
        $this->assertArrayHasKey('description', $summary);
        $this->assertArrayHasKey('expression', $summary);
        $this->assertArrayHasKey('is_due', $summary);
        $this->assertSame('Test event', $summary['description']);
    }

    public function test_event_can_opt_into_maintenance_mode(): void
    {
        $event = new Event(function() {});

        $this->assertFalse($event->runs_in_maintenance_mode());
        $this->assertSame($event, $event->run_in_maintenance_mode());
        $this->assertTrue($event->runs_in_maintenance_mode());
    }

    public function test_scheduler_skips_events_during_maintenance_mode_unless_allowed(): void
    {
        $atomic = \Base::instance();
        $previous = $atomic->get('MAINTENANCE_MODE');
        $atomic->set('MAINTENANCE_MODE', true);

        try {
            $skipped = false;
            $allowed = false;
            $scheduler = new Scheduler();

            $scheduler->call(function() use (&$skipped) {
                $skipped = true;
            })->every_minute();

            $scheduler->call(function() use (&$allowed) {
                $allowed = true;
            })->every_minute()->run_in_maintenance_mode();

            $results = $scheduler->run();

            $this->assertFalse($skipped);
            $this->assertTrue($allowed);
            $this->assertCount(1, $results);
            $this->assertTrue($results[0]['success']);
        } finally {
            $atomic->set('MAINTENANCE_MODE', $previous);
        }
    }

    public function test_runner_force_run_executes_non_due_events(): void
    {
        $called = false;
        $scheduler = new Scheduler();
        $next_minute = ((int)\date('i') + 1) % 60;

        $scheduler->call(function() use (&$called) {
            $called = true;
        })->cron($next_minute . ' * * * *');

        $result = (new Runner($scheduler))->run_due_tasks(true);

        $this->assertTrue($called);
        $this->assertSame(1, $result['due_count']);
        $this->assertSame(1, $result['summary']['successful']);
        $this->assertSame(0, $result['summary']['failed']);
    }
}
