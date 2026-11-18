<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsQuery;
use Engine\Atomic\Telemetry\Stats\TagHash;

class ExceptionsReporter implements SnapshotProviderInterface
{
    private const MAX_GROUPS = 100;

    /** @return array{groups:list<array<string,mixed>>, lifetime:array<string,int>} */
    public function report(string $range = '24h'): array
    {
        $db = StatsConnection::db();
        if ($db === null) {
            return ['groups' => [], 'lifetime' => ['occurrences' => 0, 'distinct' => 0]];
        }

        $window = StatsQuery::range_window($range);

        try {
            $store = new \Engine\Atomic\Telemetry\Stats\Adapters\DB();
            $rows = $store->exception_groups($window['from'], self::MAX_GROUPS);
            $distinct = $store->exception_groups_count();
            $series_by_tag = $store->series_by_tag('exceptions.occurrences', $window['from'], $window['to']);
        } catch (\Throwable $e) {
            return ['groups' => [], 'lifetime' => ['occurrences' => 0, 'distinct' => 0]];
        }

        $now = time();
        $groups = [];
        foreach ($rows as $row) {
            $fingerprint = (string)$row['fingerprint'];
            $tag_hash = TagHash::of(['fp' => $fingerprint]);
            $buckets = $series_by_tag[$tag_hash] ?? [];

            $usage = StatsQuery::fold($buckets, $window);
            $count_in_range = array_sum($usage);

            $first_seen = (int)$row['first_seen'];
            $last_seen = (int)$row['last_seen'];

            $groups[] = [
                'id' => $fingerprint,
                'class' => (string)$row['class'],
                'message' => (string)$row['message'],
                'file' => $row['file'] !== '' ? (string)$row['file'] : null,
                'line' => (int)$row['line'] > 0 ? (int)$row['line'] : null,
                'code' => (int)$row['code'],
                'level' => (string)$row['level'],
                'count' => $count_in_range,
                'total' => (int)$row['total'],
                'first_seen_h' => max(0, (int)round(($now - $first_seen) / 3600)),
                'last_seen_m' => max(0, (int)round(($now - $last_seen) / 60)),
                'usage' => $usage,
                'path' => (string)$row['path'],
                'dump_id' => $row['dump_id'] !== null ? (string)$row['dump_id'] : null,
                'trace' => $row['trace'] !== null ? (string)$row['trace'] : '',
            ];
        }

        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'groups' => $groups,
            'lifetime' => [
                'occurrences' => StatsQuery::lifetime('exceptions.occurrences'),
                'distinct' => $distinct,
            ],
        ];
    }

    public function snapshot(): array
    {
        $distinct = 0;
        $count_24h = 0;
        $new_today = 0;
        try {
            $data = $this->report('24h');
        } catch (\Throwable $e) {
            $data = ['groups' => [], 'lifetime' => ['occurrences' => 0, 'distinct' => 0]];
        }
        $distinct = (int)($data['lifetime']['distinct'] ?? 0);
        try {
            $count_24h = array_sum(StatsQuery::series('exceptions.occurrences', [], '24h'));
        } catch (\Throwable $e) {
            $count_24h = 0;
        }
        foreach ($data['groups'] as $group) {
            if ($group['first_seen_h'] <= 24) {
                $new_today++;
            }
        }

        return ['distinct' => $distinct, 'count_24h' => $count_24h, 'new_today' => $new_today];
    }
}
