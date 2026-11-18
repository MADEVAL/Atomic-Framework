<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Stats;

use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\TrimJob;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class TrimJobTest extends StatsDbTestCase
{
    public function test_handle_trims_old_buckets_and_events_but_keeps_lifetime(): void
    {
        $db = StatsConnection::db();
        self::assertNotNull($db);

        $now = time();
        $old_bucket = $now - 90 * 86400;
        $fresh_bucket = $now - ($now % 3600);

        $db->exec(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_COUNTERS) . '`'
            . ' (metric, tag_hash, tags, bucket_start, value) VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
            ['t.old', 'aaaaaaaaaaaaaaaa', '', $old_bucket, 5, 't.fresh', 'bbbbbbbbbbbbbbbb', '', $fresh_bucket, 9],
        );
        $db->exec(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_TOTALS) . '`'
            . ' (metric, tag_hash, tags, value) VALUES (?, ?, ?, ?)',
            ['t.old', 'aaaaaaaaaaaaaaaa', '', 5],
        );
        $db->exec(
            'INSERT INTO `' . StatsConnection::table(StatsConnection::TABLE_EVENTS) . '`'
            . ' (metric, tag_hash, tags, data, created_at) VALUES (?, ?, ?, ?, ?)',
            ['t.old', '', '', '{}', $now - 90 * 86400],
        );

        (new TrimJob())->handle();

        $counters = (array)$db->exec(
            'SELECT metric FROM `' . StatsConnection::table(StatsConnection::TABLE_COUNTERS) . '`'
        );
        $events = (array)$db->exec(
            'SELECT metric FROM `' . StatsConnection::table(StatsConnection::TABLE_EVENTS) . '`'
        );
        $totals = (array)$db->exec(
            'SELECT value FROM `' . StatsConnection::table(StatsConnection::TABLE_TOTALS) . '`'
        );

        self::assertSame(['t.fresh'], array_column($counters, 'metric'));
        self::assertSame([], array_column($events, 'metric'));
        self::assertCount(1, $totals);
        self::assertSame(5, (int)$totals[0]['value']);
    }
}
