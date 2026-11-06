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
            'get_upcoming_runs() stops after a fixed 1000-minute scan, so any weekly/monthly schedule yields an empty "Upcoming runs" list.'
        );
    }

    public function test_upcoming_runs_returns_the_requested_number_for_a_monthly_schedule(): void
    {
        $runs = (new Tester())->get_upcoming_runs('0 0 1 * *', 5);

        $this->assertCount(
            5,
            $runs,
            'get_upcoming_runs() stops after a fixed 1000-minute scan, so any weekly/monthly schedule yields an empty "Upcoming runs" list.'
        );
    }
}