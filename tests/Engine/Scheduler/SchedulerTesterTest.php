<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Scheduler\Tester;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for `schedule/test "<expr>"` (Tester::get_upcoming_runs()).
 *
 * The CLI prints the "Upcoming runs" section from this method, and the
 * documented contract is a list of `$count` upcoming run times.
 */
final class SchedulerTesterTest extends TestCase
{
    public function test_upcoming_runs_returns_the_requested_number_for_a_minute_schedule(): void
    {
        $runs = (new Tester())->get_upcoming_runs('* * * * *', 5);

        $this->assertCount(5, $runs);
    }

    public function test_upcoming_runs_returns_the_requested_number_for_a_weekly_schedule(): void
    {
        $runs = (new Tester())->get_upcoming_runs('0 9 * * 1', 5);

        $this->assertCount(
            5,
            $runs,
            'get_upcoming_runs() must return all requested runs, not stop after a fixed minute-by-minute search window.'
        );
    }

    public function test_upcoming_runs_returns_the_requested_number_for_a_monthly_schedule(): void
    {
        $runs = (new Tester())->get_upcoming_runs('0 0 1 * *', 5);

        $this->assertCount(
            5,
            $runs,
            'get_upcoming_runs() must return all requested runs, not stop after a fixed minute-by-minute search window.'
        );
    }
    public function test_daily_runs_are_strictly_increasing_and_one_day_apart(): void
    {
        $from = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $runs = (new Tester())->get_upcoming_runs('0 0 * * *', 5, $from);

        $this->assertSame([
            '2026-01-02 00:00:00',
            '2026-01-03 00:00:00',
            '2026-01-04 00:00:00',
            '2026-01-05 00:00:00',
            '2026-01-06 00:00:00',
        ], $runs);
        $previous = $from;
        foreach ($runs as $run) {
            $current = new \DateTimeImmutable($run, $from->getTimezone());
            $this->assertGreaterThan($previous->getTimestamp(), $current->getTimestamp());
            $this->assertSame(86400, $current->getTimestamp() - $previous->getTimestamp());
            $previous = $current;
        }
    }

    public function test_minute_runs_start_after_the_current_minute_without_mutating_start(): void
    {
        $from = new \DateTime('2026-01-01 12:34:45', new \DateTimeZone('UTC'));

        $this->assertSame([
            '2026-01-01 12:35:00',
            '2026-01-01 12:36:00',
            '2026-01-01 12:37:00',
        ], (new Tester())->get_upcoming_runs('* * * * *', 3, $from));
        $this->assertSame('2026-01-01 12:34:45', $from->format('Y-m-d H:i:s'));
    }

    public function test_leap_day_runs_span_multiple_leap_years(): void
    {
        $from = new \DateTimeImmutable('2024-02-29 00:00:00', new \DateTimeZone('UTC'));

        $this->assertSame([
            '2028-02-29 00:00:00',
            '2032-02-29 00:00:00',
            '2036-02-29 00:00:00',
            '2040-02-29 00:00:00',
            '2044-02-29 00:00:00',
        ], (new Tester())->get_upcoming_runs('0 0 29 2 *', 5, $from));
    }

    public function test_impossible_expression_returns_empty_without_prolonged_search(): void
    {
        $from = new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'));
        $start = microtime(true);

        $this->assertSame([], (new Tester())->get_upcoming_runs('0 0 31 2 *', 5, $from));
        $this->assertLessThan(2.0, microtime(true) - $start);
    }

    public function test_invalid_expression_returns_empty(): void
    {
        $this->assertSame([], (new Tester())->get_upcoming_runs('invalid', 5));
    }
}
