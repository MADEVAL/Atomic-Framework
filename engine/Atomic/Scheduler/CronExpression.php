<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

class CronExpression
{
    public static function is_due(string $expression, \DateTimeZone|string|null $timezone = null): bool
    {
        $now = new \DateTime('now', self::resolve_timezone($timezone));
        
        return self::matches($expression, $now);
    }

    /**
     * Find the next whole minute after $from (or now), within ten years.
     * $max_iterations caps field-skipping search steps, not elapsed minutes;
     * its existing default and explicitly supplied limits remain supported.
     */
    public static function get_next_run_date(
        string $expression,
        \DateTimeZone|string|null $timezone = null,
        int $max_iterations = 527040,
        ?\DateTimeInterface $from = null
    ): ?\DateTimeInterface {
        if (!self::is_valid($expression)) {
            return null;
        }

        $tz = self::resolve_timezone($timezone);
        $date = $from === null
            ? new \DateTime('now', $tz)
            : \DateTime::createFromInterface($from)->setTimezone($tz);
        // Elapsed-time advances preserve both occurrences of a repeated DST hour.
        $date->setTimestamp((int)\floor($date->getTimestamp() / 60) * 60 + 60);
        $limit = (clone $date)->modify('+10 years');
        [$minute, $hour, $day_of_month, $month, $day_of_week] =
            \preg_split('/\s+/', \trim($expression));

        for ($i = 0; $i < $max_iterations && $date <= $limit; $i++) {
            if (!self::matches_part($month, (int)$date->format('n'), 1, 12)) {
                $date->setDate((int)$date->format('Y'), (int)$date->format('n') + 1, 1);
                $date->setTime(0, 0);
                continue;
            }
            if (!self::matches_day($day_of_month, $day_of_week, $date)) {
                $date->modify('+1 day')->setTime(0, 0);
                continue;
            }
            if (!self::matches_part($hour, (int)$date->format('G'), 0, 23)) {
                $date->setTimestamp($date->getTimestamp() + (60 - (int)$date->format('i')) * 60);
                continue;
            }
            if (!self::matches_part($minute, (int)$date->format('i'), 0, 59)) {
                $date->setTimestamp($date->getTimestamp() + 60);
                continue;
            }
            return $date;
        }

        return null;
    }

    public static function matches(string $expression, \DateTimeInterface $date): bool
    {
        if (!self::is_valid($expression)) {
            return false;
        }

        return self::matches_valid_expression($expression, $date);
    }

    protected static function matches_valid_expression(string $expression, \DateTimeInterface $date): bool
    {
        $segments = \preg_split('/\s+/', \trim($expression));
        
        if ($segments === false || \count($segments) !== 5) {
            return false;
        }

        [$minute, $hour, $day_of_month, $month, $day_of_week] = $segments;

        $current_minute = (int)$date->format('i');
        $current_hour = (int)$date->format('G');
        $current_month = (int)$date->format('n');

        if (!self::matches_part($minute, $current_minute, 0, 59)) {
            return false;
        }
        if (!self::matches_part($hour, $current_hour, 0, 23)) {
            return false;
        }
        if (!self::matches_part($month, $current_month, 1, 12)) {
            return false;
        }

        return self::matches_day($day_of_month, $day_of_week, $date);
    }

    protected static function matches_day(string $day_of_month, string $day_of_week, \DateTimeInterface $date): bool
    {
        $dom_restricted = ($day_of_month !== '*');
        $dow_restricted = ($day_of_week !== '*');
        
        $dom_matches = self::matches_part($day_of_month, (int)$date->format('j'), 1, 31);
        $dow_matches = self::matches_part($day_of_week, (int)$date->format('w'), 0, 6);

        if ($dom_restricted && $dow_restricted) {
            return $dom_matches || $dow_matches;
        }
        
        return $dom_matches && $dow_matches;
    }

    protected static function matches_part(string $part, int $value, int $min, int $max): bool
    {
        if ($part === '*') {
            return true;
        }

        if (\strpos($part, ',') !== false) {
            $values = \explode(',', $part);
            foreach ($values as $v) {
                if (self::matches_part(\trim($v), $value, $min, $max)) {
                    return true;
                }
            }
            return false;
        }

        if (\strpos($part, '-') !== false && \strpos($part, '/') === false) {
            [$start, $end] = \explode('-', $part, 2);
            $start = (int)$start;
            $end = (int)$end;
            return $value >= $start && $value <= $end;
        }

        if (\strpos($part, '/') !== false) {
            [$range, $step] = \explode('/', $part, 2);
            $step = (int)$step;

            if ($step <= 0) {
                return false;
            }

            if ($range === '*') {
                return ($value - $min) % $step === 0;
            }

            if (\strpos($range, '-') !== false) {
                [$start, $end] = \explode('-', $range, 2);
                $start = (int)$start;
                $end = (int)$end;

                if ($value < $start || $value > $end) {
                    return false;
                }

                return ($value - $start) % $step === 0;
            }

            $start = (int)$range;
            return $value >= $start && ($value - $start) % $step === 0;
        }

        return (int)$part === $value;
    }

    protected static function resolve_timezone(\DateTimeZone|string|null $timezone): \DateTimeZone
    {
        if ($timezone instanceof \DateTimeZone) {
            return $timezone;
        }

        if (\is_string($timezone) && !empty($timezone)) {
            return new \DateTimeZone($timezone);
        }

        $atomic = \Base::instance();
        $tz = $atomic->get('TZ');
        
        if ($tz) {
            return new \DateTimeZone($tz);
        }

        return new \DateTimeZone(\date_default_timezone_get());
    }

    public static function is_valid(string $expression): bool
    {
        $segments = \preg_split('/\s+/', \trim($expression));
        
        if ($segments === false || \count($segments) !== 5) {
            return false;
        }

        $limits = [
            [0, 59],   // minute
            [0, 23],   // hour
            [1, 31],   // day of month
            [1, 12],   // month
            [0, 6],    // day of week
        ];

        foreach ($segments as $i => $segment) {
            if (!self::is_valid_part($segment, $limits[$i][0], $limits[$i][1])) {
                return false;
            }
        }

        return true;
    }

    protected static function is_valid_part(string $part, int $min, int $max): bool
    {
        if ($part === '*') {
            return true;
        }

        if (\strpos($part, ',') !== false) {
            $values = \explode(',', $part);
            foreach ($values as $v) {
                if (!self::is_valid_part(\trim($v), $min, $max)) {
                    return false;
                }
            }
            return true;
        }

        if (\strpos($part, '/') !== false) {
            [$range, $step] = \explode('/', $part, 2);
            if (!\is_numeric($step) || (int)$step <= 0) {
                return false;
            }
            if ($range !== '*') {
                return self::is_valid_part($range, $min, $max);
            }
            return true;
        }

        if (\strpos($part, '-') !== false) {
            [$start, $end] = \explode('-', $part, 2);
            if (!\is_numeric($start) || !\is_numeric($end)) {
                return false;
            }
            $start = (int)$start;
            $end = (int)$end;
            return $start >= $min && $end <= $max && $start <= $end;
        }

        if (!\is_numeric($part)) {
            return false;
        }
        
        $val = (int)$part;
        return $val >= $min && $val <= $max;
    }

    public static function describe(string $expression): string
    {
        $segments = \preg_split('/\s+/', \trim($expression));
        
        if ($segments === false || \count($segments) !== 5) {
            return 'Invalid expression';
        }

        [$minute, $hour, $day_of_month, $month, $day_of_week] = $segments;

        if ($expression === '* * * * *') {
            return 'Every minute';
        }

        if ($minute === '0' && $hour === '*' && $day_of_month === '*' && $month === '*' && $day_of_week === '*') {
            return 'Every hour';
        }

        if ($minute === '0' && $hour === '0' && $day_of_month === '*' && $month === '*' && $day_of_week === '*') {
            return 'Every day at midnight';
        }

        if (\preg_match('/^\*\/(\d+)$/', $minute, $m) && $hour === '*' && $day_of_month === '*' && $month === '*' && $day_of_week === '*') {
            return "Every {$m[1]} minutes";
        }

        if ($minute === '0' && \preg_match('/^\*\/(\d+)$/', $hour, $m) && $day_of_month === '*' && $month === '*' && $day_of_week === '*') {
            return "Every {$m[1]} hours";
        }

        $desc = 'At ';
        
        if ($minute !== '*') {
            $desc .= "minute {$minute}";
        }
        
        if ($hour !== '*') {
            $desc .= " past hour {$hour}";
        }

        if ($day_of_month !== '*') {
            $desc .= " on day {$day_of_month}";
        }

        if ($month !== '*') {
            $desc .= " in month {$month}";
        }

        if ($day_of_week !== '*') {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            if (\is_numeric($day_of_week) && isset($days[(int)$day_of_week])) {
                $desc .= " on {$days[(int)$day_of_week]}";
            } else {
                $desc .= " on day-of-week {$day_of_week}";
            }
        }

        return \trim($desc);
    }
}
