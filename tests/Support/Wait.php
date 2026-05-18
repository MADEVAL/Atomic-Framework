<?php
declare(strict_types=1);

namespace Tests\Support;

final class Wait
{
    public static function until(callable $condition, int $timeout_seconds, int $interval_microseconds = 250_000): bool
    {
        $deadline = microtime(true) + $timeout_seconds;

        do {
            if ($condition()) {
                return true;
            }
            usleep($interval_microseconds);
        } while (microtime(true) < $deadline);

        return $condition();
    }
}
