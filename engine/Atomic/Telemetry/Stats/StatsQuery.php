<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

/** Ranges aggregate the hourly buckets directly (24 hourly, 28 six-hourly or 30 daily points, oldest first); no rollup job. */
final class StatsQuery
{
    private const RANGES = [
        '24h' => ['bucket_seconds' => 3600, 'buckets' => 24],
        '7d' => ['bucket_seconds' => 21600, 'buckets' => 28],
        '30d' => ['bucket_seconds' => 86400, 'buckets' => 30],
    ];

    /**
     * An empty tag set sums every tag set of the metric.
     *
     * @return list<int> length 24/28/30 depending on the range
     */
    public static function series(string $metric, array $tags = [], string $range = '24h'): array
    {
        $window = self::range_window($range);
        $db = StatsConnection::db();
        if ($db === null) {
            return array_fill(0, $window['buckets'], 0);
        }

        try {
            $rows = (new Adapters\DB())->series(
                $metric,
                $tags === [] ? null : TagHash::of($tags),
                $window['from'],
                $window['to'],
            );
        } catch (\Throwable $e) {
            return array_fill(0, $window['buckets'], 0);
        }

        return self::fold($rows, $window);
    }

    /**
     * @param array<int,int> $hourly bucket_start => value
     * @return list<int>
     */
    public static function fold(array $hourly, array $window): array
    {
        $out = array_fill(0, $window['buckets'], 0);
        foreach ($hourly as $bucket_start => $value) {
            $i = intdiv((int)$bucket_start - $window['from'], $window['bucket_seconds']);
            if ($i >= 0 && $i < $window['buckets']) {
                $out[$i] += (int)$value;
            }
        }

        return $out;
    }

    public static function lifetime(string $metric, array $tags = []): int
    {
        $db = StatsConnection::db();
        if ($db === null) {
            return 0;
        }

        try {
            return (new Adapters\DB())->lifetime(
                $metric,
                $tags === [] ? null : TagHash::of($tags),
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return list<array{tags:array,value:int}> lifetime rows split by tag set, largest first */
    public static function lifetime_by_tag(string $metric, int $limit = 50): array
    {
        $db = StatsConnection::db();
        if ($db === null) {
            return [];
        }

        try {
            return (new Adapters\DB())->lifetime_by_tag($metric, $limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array $exclude_tags tag keys whose values must not appear in a row's tags
     * @return list<array{tag_hash:string,tags:array,total:int}>
     */
    public static function top_tags(string $metric, string $range = '24h', int $limit = 10, array $exclude_tags = []): array
    {
        $window = self::range_window($range);
        $db = StatsConnection::db();
        if ($db === null) {
            return [];
        }

        try {
            $rows = (new Adapters\DB())->top_tags($metric, $window['from'], $window['to'], $limit);
        } catch (\Throwable $e) {
            return [];
        }

        if ($exclude_tags !== []) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => empty(array_intersect_key($row['tags'], array_flip($exclude_tags))),
            ));
        }

        return $rows;
    }

    /** @return list<array{tags:array,data:array,created_at:int}> */
    public static function recent_events(string $metric, int $limit = 20, array $tags = []): array
    {
        $db = StatsConnection::db();
        if ($db === null) {
            return [];
        }

        try {
            return (new Adapters\DB())->recent_events(
                $metric,
                $tags === [] ? null : TagHash::of($tags),
                $limit,
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array{bucket_seconds:int, buckets:int, bucket_starts:list<int>, from:int, current_bucket:int, to:int} */
    public static function range_window(string $range): array
    {
        $config = self::RANGES[$range] ?? self::RANGES['24h'];
        $seconds = $config['bucket_seconds'];
        $count = $config['buckets'];
        $current = (int)floor(time() / $seconds) * $seconds;

        $starts = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $starts[] = $current - $i * $seconds;
        }

        return [
            'bucket_seconds' => $seconds,
            'buckets' => $count,
            'bucket_starts' => $starts,
            'from' => $starts[0],
            'current_bucket' => $current,
            // Inclusive upper bound on hourly rows: the in-progress hour.
            'to' => (int)floor(time() / 3600) * 3600,
        ];
    }
}
