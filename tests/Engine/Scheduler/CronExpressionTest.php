<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Scheduler\CronExpression;
use PHPUnit\Framework\TestCase;

class CronExpressionTest extends TestCase
{
    // ── Matching ──

    public function test_every_minute(): void
    {
        $date = new \DateTime('2024-06-15 10:30:00');
        $this->assertTrue(CronExpression::matches('* * * * *', $date));
    }

    public function test_specific_minute(): void
    {
        $date = new \DateTime('2024-06-15 10:30:00');
        $this->assertTrue(CronExpression::matches('30 * * * *', $date));
        $this->assertFalse(CronExpression::matches('15 * * * *', $date));
    }

    public function test_specific_hour(): void
    {
        $date = new \DateTime('2024-06-15 10:00:00');
        $this->assertTrue(CronExpression::matches('0 10 * * *', $date));
        $this->assertFalse(CronExpression::matches('0 11 * * *', $date));
    }

    public function test_range(): void
    {
        $date = new \DateTime('2024-06-15 10:30:00');
        $this->assertTrue(CronExpression::matches('25-35 * * * *', $date));
        $this->assertFalse(CronExpression::matches('0-5 * * * *', $date));
    }

    public function test_step_from_star(): void
    {
        $date = new \DateTime('2024-06-15 10:30:00');
        $this->assertTrue(CronExpression::matches('*/15 * * * *', $date));
        $this->assertTrue(CronExpression::matches('*/10 * * * *', $date));
        $this->assertFalse(CronExpression::matches('*/7 * * * *', $date));
    }

    public function test_comma_list(): void
    {
        $date = new \DateTime('2024-06-15 10:30:00');
        $this->assertTrue(CronExpression::matches('15,30,45 * * * *', $date));
        $this->assertFalse(CronExpression::matches('0,15,45 * * * *', $date));
    }

    public function test_day_of_week(): void
    {
        // 2024-06-15 is Saturday (6)
        $date = new \DateTime('2024-06-15 10:30:00');
        $this->assertTrue(CronExpression::matches('30 10 * * 6', $date));
        $this->assertFalse(CronExpression::matches('30 10 * * 1', $date));
    }

    public function test_midnight_daily(): void
    {
        $date = new \DateTime('2024-06-15 00:00:00');
        $this->assertTrue(CronExpression::matches('0 0 * * *', $date));
    }

    public function test_day_of_month_and_day_of_week_or_logic(): void
    {
        // 2024-06-15 is Saturday (6), day 15
        $date = new \DateTime('2024-06-15 00:00:00');
        // Both restricted: OR logic
        $this->assertTrue(CronExpression::matches('0 0 15 * 1', $date)); // day 15 OR Monday
        $this->assertTrue(CronExpression::matches('0 0 20 * 6', $date)); // day 20 OR Saturday
        $this->assertFalse(CronExpression::matches('0 0 20 * 1', $date)); // day 20 OR Monday (neither)
    }

    // ── Validation ──

    public function test_is_valid(): void
    {
        $this->assertTrue(CronExpression::is_valid('* * * * *'));
        $this->assertTrue(CronExpression::is_valid('0 12 * * 1'));
        $this->assertTrue(CronExpression::is_valid('*/5 * * * *'));
        $this->assertTrue(CronExpression::is_valid('0-30 * * * *'));
        $this->assertTrue(CronExpression::is_valid('0,15,30,45 * * * *'));
    }

    public function test_is_valid_rejects_invalid(): void
    {
        $this->assertFalse(CronExpression::is_valid(''));
        $this->assertFalse(CronExpression::is_valid('* * *'));
        $this->assertFalse(CronExpression::is_valid('60 * * * *'));
        $this->assertFalse(CronExpression::is_valid('* 24 * * *'));
        $this->assertFalse(CronExpression::is_valid('* * 32 * *'));
        $this->assertFalse(CronExpression::is_valid('* * * 13 *'));
        $this->assertFalse(CronExpression::is_valid('* * * * 7'));
    }

    public function test_invalid_tokens_never_match(): void
    {
        $date = new \DateTime('2024-06-15 10:00:00');

        $this->assertFalse(CronExpression::matches('foo * * * *', $date));
        $this->assertFalse(CronExpression::matches('* bar * * *', $date));
        $this->assertFalse(CronExpression::matches('* * baz * *', $date));
        $this->assertFalse(CronExpression::matches('* * * qux *', $date));
        $this->assertFalse(CronExpression::matches('* * * * nope', $date));
    }

    // ── Description ──

    public function test_describe_every_minute(): void
    {
        $this->assertSame('Every minute', CronExpression::describe('* * * * *'));
    }

    public function test_describe_every_hour(): void
    {
        $this->assertSame('Every hour', CronExpression::describe('0 * * * *'));
    }

    public function test_describe_midnight(): void
    {
        $this->assertSame('Every day at midnight', CronExpression::describe('0 0 * * *'));
    }

    public function test_describe_every_n_minutes(): void
    {
        $this->assertSame('Every 5 minutes', CronExpression::describe('*/5 * * * *'));
    }

    public function test_describe_invalid(): void
    {
        $this->assertSame('Invalid expression', CronExpression::describe('bad'));
    }

    // ── Next run ──

    public function test_get_next_run_date(): void
    {
        $next = CronExpression::get_next_run_date('* * * * *');
        $this->assertInstanceOf(\DateTimeInterface::class, $next);
    }

    public function test_get_next_run_date_specific(): void
    {
        $next = CronExpression::get_next_run_date('0 12 * * *');
        if ($next !== null) {
            $this->assertSame('12', $next->format('G'));
            $this->assertSame('00', $next->format('i'));
        }
    }

    public function test_get_next_run_date_finds_yearly_schedule(): void
    {
        $next = CronExpression::get_next_run_date('0 0 1 1 *');

        $this->assertInstanceOf(\DateTimeInterface::class, $next);
        $this->assertSame('01-01 00:00', $next->format('m-d H:i'));
    }

    public function test_invalid_expression_no_match(): void
    {
        $date = new \DateTime('2024-06-15 10:30:00');
        $this->assertFalse(CronExpression::matches('bad expression', $date));
    }

    public function test_next_run_search_window_covers_the_longest_leap_year_gap(): void
    {
        $method = new \ReflectionMethod(CronExpression::class, 'get_next_run_date');
        $parameters = $method->getParameters();
        $window = (int)$parameters[2]->getDefaultValue();

        $this->assertGreaterThanOrEqual(
            8 * 366 * 24 * 60,
            $window,
            sprintf(
                'The default search window spans %d day(s), but two Feb 29 occurrences can be up to 8 years apart, '
                . 'so a valid leap-day schedule can never be resolved.',
                intdiv($window, 1440)
            )
        );
    }

    public function test_next_run_date_for_leap_day_expression_is_reachable(): void
    {
        $start = microtime(true);
        $next = CronExpression::get_next_run_date('0 0 29 2 *');
        $elapsed = microtime(true) - $start;

        $this->assertNotNull(
            $next,
            sprintf(
                'get_next_run_date() gave up on a valid Feb-29 schedule (yearly_on(2, 29)) after %0.2fs of scanning.',
                $elapsed
            )
        );
        $this->assertSame('29-02 00:00', $next->format('d-m H:i'));
    }
}
