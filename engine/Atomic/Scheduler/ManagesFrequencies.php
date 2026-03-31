<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

trait ManagesFrequencies
{
    protected string $expression = '* * * * *';
    protected \DateTimeZone|string|null $timezone = null;

    public function cron(string $expression): self
    {
        $parts = \preg_split('/\s+/', \trim($expression), -1, \PREG_SPLIT_NO_EMPTY);

        if ($parts === false || \count($parts) !== 5) {
            throw new \InvalidArgumentException('Cron expression must contain exactly 5 fields.');
        }

        $this->expression = \implode(' ', $parts);

        return $this;
    }

    public function every_minute(): self {
        return $this->splice_into_position(1, '*');
    }

    public function every_two_minutes(): self {
        return $this->splice_into_position(1, '*/2');
    }

    public function every_three_minutes(): self {
        return $this->splice_into_position(1, '*/3');
    }

    public function every_four_minutes(): self {
        return $this->splice_into_position(1, '*/4');
    }

    public function every_five_minutes(): self {
        return $this->splice_into_position(1, '*/5');
    }

    public function every_ten_minutes(): self {
        return $this->splice_into_position(1, '*/10');
    }

    public function every_fifteen_minutes(): self {
        return $this->splice_into_position(1, '*/15');
    }

    public function every_thirty_minutes(): self {
        return $this->splice_into_position(1, '0,30');
    }

    public function hourly(): self {
        return $this->splice_into_position(1, '0');
    }

    public function hourly_at(int|array $offset): self {
        $values = \is_array($offset) ? $offset : [$offset];
        foreach ($values as $v) {
            $this->assert_range('minute', (int)$v, 0, 59);
        }
        $offsetStr = \is_array($offset) ? \implode(',', $offset) : (string)$offset;
        return $this->splice_into_position(1, $offsetStr);
    }

    public function every_two_hours(): self {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '*/2');
    }

    public function every_three_hours(): self {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '*/3');
    }

    public function every_four_hours(): self {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '*/4');
    }

    public function every_six_hours(): self {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '*/6');
    }

    public function daily(): self {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '0');
    }

    public function daily_at(string $time): self
    {
        $time = \trim($time);
        if (!\preg_match('/^\d{1,2}:\d{1,2}$/', $time)) {
            throw new \InvalidArgumentException('Time must be in HH:MM format.');
        }

        $segments = \explode(':', $time);
        $hour = (int)($segments[0] ?? 0);
        $minute = (int)($segments[1] ?? 0);

        $this->assert_range('hour', $hour, 0, 23);
        $this->assert_range('minute', $minute, 0, 59);

        return $this->splice_into_position(1, (string)$minute)
                    ->splice_into_position(2, (string)$hour);
    }

    public function twice_daily(int $first = 1, int $second = 13): self {
        $this->assert_range('hour', $first, 0, 23);
        $this->assert_range('hour', $second, 0, 23);

        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, "{$first},{$second}");
    }

    public function twice_daily_at(int $first = 1, int $second = 13, int $offset = 0): self {
        $this->assert_range('minute', $offset, 0, 59);
        $this->assert_range('hour', $first, 0, 23);
        $this->assert_range('hour', $second, 0, 23);

        return $this->splice_into_position(1, (string)$offset)
                    ->splice_into_position(2, "{$first},{$second}");
    }

    public function weekly(): self {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '0')
                    ->splice_into_position(5, '0'); 
    }

    public function weekly_on(int|array|string $dayOfWeek, string $time = '0:0'): self {
        $this->daily_at($time);

        if (\is_array($dayOfWeek)) {
            foreach ($dayOfWeek as $d) {
                $this->assert_range('day_of_week', (int)$d, 0, 6);
            }
            $dayOfWeek = \implode(',', $dayOfWeek);
        } elseif (\is_int($dayOfWeek) || \ctype_digit((string)$dayOfWeek)) {
            $this->assert_range('day_of_week', (int)$dayOfWeek, 0, 6);
            $dayOfWeek = (string)$dayOfWeek;
        } else {
            $dayOfWeek = (string)$dayOfWeek;
        }

        return $this->splice_into_position(5, $dayOfWeek);
    }

    public function monthly(): self {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '0')
                    ->splice_into_position(3, '1');
    }

    public function monthly_on(int $dayOfMonth = 1, string $time = '0:0'): self {
        $this->assert_range('day_of_month', $dayOfMonth, 1, 31);
        $this->daily_at($time);
        return $this->splice_into_position(3, (string)$dayOfMonth);
    }

    public function twice_monthly(int $first = 1, int $second = 16, string $time = '0:0'): self {
        $this->assert_range('day_of_month', $first, 1, 31);
        $this->assert_range('day_of_month', $second, 1, 31);

        $this->daily_at($time);
        return $this->splice_into_position(3, "{$first},{$second}");
    }

    public function quarterly(): self
    {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '0')
                    ->splice_into_position(3, '1')
                    ->splice_into_position(4, '1,4,7,10');
    }

    public function quarterly_on(int $dayOfQuarter = 1, string $time = '0:0'): self {
        $this->assert_range('day_of_month', $dayOfQuarter, 1, 31);
        $this->daily_at($time);
        return $this->splice_into_position(3, (string)$dayOfQuarter)->splice_into_position(4, '1,4,7,10');
    }

    public function yearly(): self
    {
        return $this->splice_into_position(1, '0')
                    ->splice_into_position(2, '0')
                    ->splice_into_position(3, '1')
                    ->splice_into_position(4, '1');
    }

    public function yearly_on(int $month = 1, int|string $dayOfMonth = 1, string $time = '0:0'): self
    {
        $this->assert_range('month', $month, 1, 12);

        if (\is_int($dayOfMonth) || \ctype_digit((string)$dayOfMonth)) {
            $this->assert_range('day_of_month', (int)$dayOfMonth, 1, 31);
        }

        $this->daily_at($time);
        return $this->splice_into_position(3, (string)$dayOfMonth)
                    ->splice_into_position(4, (string)$month);
    }

    public function weekdays(): self {
        return $this->splice_into_position(5, '1-5');
    }

    public function weekends(): self {
        return $this->splice_into_position(5, '6,0');
    }

    public function mondays(): self {
        return $this->days(1);
    }

    public function tuesdays(): self {
        return $this->days(2);
    }

    public function wednesdays(): self {
        return $this->days(3);
    }

    public function thursdays(): self {
        return $this->days(4);
    }

    public function fridays(): self {
        return $this->days(5);
    }

    public function saturdays(): self {
        return $this->days(6);
    }

    public function sundays(): self {
        return $this->days(0);
    }

    public function days(int|array|string $days): self {
        if (\is_array($days)) {
            foreach ($days as $d) {
                $this->assert_range('day_of_week', (int)$d, 0, 6);
            }
            $days = \implode(',', $days);
        } elseif (\is_int($days) || \ctype_digit((string)$days)) {
            $this->assert_range('day_of_week', (int)$days, 0, 6);
            $days = (string)$days;
        } else {
            $days = (string)$days;
        }

        return $this->splice_into_position(5, $days);
    }

    public function timezone(\DateTimeZone|string $timezone): self {
        $this->timezone = $timezone;
        return $this;
    }

    protected function assert_range(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('%s must be between %d and %d.', $name, $min, $max));
        }
    }

    protected function splice_into_position(int $position, string $value): self
    {
        if ($position < 1 || $position > 5) {
            throw new \InvalidArgumentException('Position must be between 1 and 5.');
        }

        $segments = \preg_split('/\s+/', \trim($this->expression), -1, \PREG_SPLIT_NO_EMPTY);

        if ($segments === false || \count($segments) !== 5) {
            throw new \InvalidArgumentException('Current cron expression must contain exactly 5 fields.');
        }

        $segments[$position - 1] = $value;
        
        $this->expression = \implode(' ', $segments);
        
        return $this;
    }
}
