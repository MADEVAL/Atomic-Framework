<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Stats;

use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Engine\Atomic\Telemetry\Stats\StatsQuery;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class StatsQueryTest extends StatsDbTestCase
{
    public function test_series_buckets_align_and_sum_by_tag_set(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('q.spent', 3, ['scope' => 'a']);
        $recorder->record('q.spent', 4, ['scope' => 'b']);
        $recorder->record('q.spent', 5, ['scope' => 'a']);
        $recorder->flush();

        $window = StatsQuery::range_window('24h');
        $series_a = StatsQuery::series('q.spent', ['scope' => 'a'], '24h');
        $series_all = StatsQuery::series('q.spent', [], '24h');

        self::assertSame(8, array_sum($series_a));
        self::assertSame(12, array_sum($series_all));
        self::assertSame(8, $series_a[23]);
        self::assertCount($window['buckets'], $series_all);
    }

    public function test_coarse_ranges_fold_every_hourly_bucket(): void
    {
        // Counters are written hourly; 7d/30d points must sum all hours they
        // cover, including the in-progress hour.
        $now_hour = (int)floor(time() / 3600) * 3600;
        $rows = [];
        foreach ([0, 1, 2, 5, 7, 30] as $hours_ago) {
            $rows[] = [
                'metric' => 'q.fold',
                'tag_hash' => '',
                'tags' => '{}',
                'bucket_start' => $now_hour - $hours_ago * 3600,
                'value' => 1,
            ];
        }
        (new \Engine\Atomic\Telemetry\Stats\Adapters\DB())->increment_counters($rows);

        self::assertSame(6, array_sum(StatsQuery::series('q.fold', [], '7d')));
        self::assertSame(6, array_sum(StatsQuery::series('q.fold', [], '30d')));
        self::assertSame(1, StatsQuery::series('q.fold', [], '24h')[23]);
    }

    public function test_series_for_unknown_metric_is_all_zero(): void
    {
        $series = StatsQuery::series('q.unknown', [], '7d');
        self::assertCount(28, $series);
        self::assertSame(array_fill(0, 28, 0), $series);
    }

    public function test_top_tags_returns_largest_tag_sets_in_range(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('auth.failed', 1, ['id' => 'ip|a@test']);
        $recorder->record('auth.failed', 5, ['id' => 'ip|b@test']);
        $recorder->flush();

        $top = StatsQuery::top_tags('auth.failed', '24h', 10);
        self::assertNotEmpty($top);
        self::assertSame('ip|b@test', (string)$top[0]['tags']['id']);
        self::assertSame(5, $top[0]['total']);
    }

    public function test_lifetime_by_tag_splits_totals(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('quota.denied', 2, ['scope' => 'a', 'reason' => 'pacing']);
        $recorder->record('quota.denied', 1, ['scope' => 'a', 'reason' => 'pacing']);
        $recorder->flush();

        $rows = StatsQuery::lifetime_by_tag('quota.denied', 10);
        self::assertNotEmpty($rows);
        self::assertSame(3, $rows[0]['value']);
        self::assertSame('a', $rows[0]['tags']['scope']);
    }

    public function test_range_window_geometry(): void
    {
        $w24 = StatsQuery::range_window('24h');
        self::assertSame(3600, $w24['bucket_seconds']);
        self::assertCount(24, $w24['bucket_starts']);
        self::assertSame(24, $w24['buckets']);

        $starts = $w24['bucket_starts'];
        self::assertSame($starts[23], (int)floor(time() / 3600) * 3600);
        self::assertSame($starts[0], $starts[23] - 23 * 3600);

        $w7 = StatsQuery::range_window('7d');
        self::assertSame(21600, $w7['bucket_seconds']);
        self::assertCount(28, $w7['bucket_starts']);

        $w30 = StatsQuery::range_window('30d');
        self::assertSame(86400, $w30['bucket_seconds']);
        self::assertCount(30, $w30['bucket_starts']);
    }
}
