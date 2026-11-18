<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\View\Fmt;
use PHPUnit\Framework\TestCase;

final class FmtTest extends TestCase
{
    public function test_age_matches_the_client_formatter(): void
    {
        self::assertSame('0s', Fmt::age(0));
        self::assertSame('59s', Fmt::age(59));
        self::assertSame('1m 0s', Fmt::age(60));
        self::assertSame('1h 1m', Fmt::age(3661));
        self::assertSame('2d 3h', Fmt::age(2 * 86400 + 3 * 3600 + 59));
    }

    public function test_numbers_get_thousands_separators(): void
    {
        self::assertSame('12,345', Fmt::n(12345));
    }

    public function test_run_durations_match_the_client_formatter(): void
    {
        self::assertSame('1.2s', Fmt::dur(1.2));
        self::assertSame('3s', Fmt::dur(3.0));
        self::assertSame('1m 05s', Fmt::dur(65));
        self::assertSame('2m 10.5s', Fmt::dur(130.5));
        self::assertSame('850 ms', Fmt::run_ms(850.2));
        self::assertSame('1.3s', Fmt::run_ms(1250));
    }

    public function test_times_print_in_utc(): void
    {
        $now = gmmktime(15, 0, 0, 11, 20, 2026);
        self::assertSame('2026-11-20 14:05:00 UTC', Fmt::utc(gmmktime(14, 5, 0, 11, 20, 2026)));
        self::assertSame('-', Fmt::utc(null));
        self::assertSame('14:05', Fmt::short(gmmktime(14, 5, 0, 11, 20, 2026), $now));
        self::assertSame('Nov 18, 03:20', Fmt::short(new \DateTimeImmutable('2026-11-18 05:20', new \DateTimeZone('+02:00')), $now));
    }
}
