<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Stats\SecurityStats;
use Engine\Atomic\Telemetry\Stats\StatsQuery;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class SecurityStatsTest extends StatsDbTestCase
{
    public function test_login_failure_counters_and_lockout_registry(): void
    {
        SecurityStats::login_failed('127.0.0.1|user@test');
        SecurityStats::login_failed('127.0.0.1|user@test');
        SecurityStats::lockout('127.0.0.1|user@test', 5, time() - 120, time() + 600);
        SecurityStats::login_success();
        StatsRecorder::instance()->flush();

        self::assertSame(2, StatsQuery::lifetime('auth.failed'));
        self::assertSame(1, StatsQuery::lifetime('auth.lock'));
        self::assertSame(1, StatsQuery::lifetime('auth.login'));

        $top = StatsQuery::top_tags('auth.failed', '24h', 10);
        self::assertNotEmpty($top);
        self::assertSame('127.0.0.1|user@test', $top[0]['tags']['id']);

        $report = (new \Engine\Atomic\Telemetry\Reporters\SecurityReporter())->report('24h');
        self::assertCount(1, $report['lockouts']);
        $lockout = $report['lockouts'][0];
        self::assertSame('127.0.0.1|user@test', $lockout['identifier']);
        self::assertSame(5, $lockout['count']);
        self::assertTrue($lockout['locked']);
        self::assertGreaterThan(0, $lockout['remaining']);
        self::assertSame(2, array_sum($report['failed']));
        self::assertSame(1, array_sum($report['lock_events']));
        self::assertSame('127.0.0.1|user@test', array_key_first($report['top_failed']));
    }
}
