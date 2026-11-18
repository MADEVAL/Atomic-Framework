<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Telemetry\Data\QuotaReport;
use Engine\Atomic\Telemetry\Data\Range;
use Engine\Atomic\Core\App;
use Engine\Atomic\Quota\QuotaLimiter;
use Engine\Atomic\Quota\QuotaPlan;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsQuery;
use Engine\Atomic\Telemetry\Stats\TagHash;

class QuotaReporter implements SnapshotProviderInterface
{
    private const MAX_SCOPES = 50;

    /** @return array{tiers:list<array<string,mixed>>, scopes:list<array<string,mixed>>, lifetime:array<string,int>} */
    public function report(string $range = '24h'): array
    {
        return [
            'tiers' => $this->tiers(),
            'scopes' => $this->scopes($range),
            'lifetime' => [
                'denied' => StatsQuery::lifetime('quota.denied'),
                'renewals' => StatsQuery::lifetime('quota.renewal'),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function tiers(): array
    {
        $declared = App::instance()->get('QUOTA.operations');
        $declared = is_array($declared) ? array_values($declared) : [];
        $quotas = App::instance()->get('QUOTA.quotas');
        $out = [];
        foreach ((is_array($quotas) ? $quotas : []) as $name => $config) {
            try {
                $plan = QuotaPlan::from_array((string)$name, (array)$config, $declared);
            } catch (\Throwable $e) {
                continue;
            }
            $operations = [];
            foreach ($plan->operations as $op_name => $spec) {
                $operations[$op_name] = [
                    'cost' => $spec->cost,
                    'pacing' => array_map(static fn ($w): array => ['limit' => $w->limit, 'window' => $w->window], $spec->pacing),
                ];
            }
            $out[] = [
                'name' => $plan->name,
                'credits' => $plan->credits,
                'period' => $plan->period,
                'reservation_ttl' => $plan->reservation_ttl,
                'pacing' => array_map(static fn ($w): array => ['limit' => $w->limit, 'window' => $w->window], $plan->pacing),
                'operations' => $operations,
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function scopes(string $range): array
    {
        $db = StatsConnection::db();
        if ($db === null) {
            return [];
        }

        try {
            $registry = (new \Engine\Atomic\Telemetry\Stats\Adapters\DB())
                ->quota_scopes_list(null, self::MAX_SCOPES);
        } catch (\Throwable $e) {
            return [];
        }
        if ($registry === []) {
            return [];
        }

        $tier_credits = [];
        foreach ($this->tiers() as $tier) {
            $tier_credits[$tier['name']] = (int)$tier['credits'];
        }

        $window = StatsQuery::range_window($range);
        $denied_by_tag = [];
        try {
            $store = new \Engine\Atomic\Telemetry\Stats\Adapters\DB();
            $denied_by_tag = $store->series_by_tag('quota.denied', $window['from'], $window['to']);
        } catch (\Throwable $e) {
            $denied_by_tag = [];
        }

        // Denials are tagged {scope, reason}, so a scope's series sums every reason tag set seen for it.
        $denials_by_tag = [];
        $denied_hashes = [];
        try {
            foreach (StatsQuery::lifetime_by_tag('quota.denied', 500) as $row) {
                $scope = (string)($row['tags']['scope'] ?? '');
                $reason = (string)($row['tags']['reason'] ?? 'unknown');
                if ($scope !== '') {
                    $denials_by_tag[$scope][$reason] = ($denials_by_tag[$scope][$reason] ?? 0) + $row['value'];
                    $denied_hashes[$scope][] = TagHash::of($row['tags']);
                }
            }
        } catch (\Throwable $e) {
            $denials_by_tag = [];
            $denied_hashes = [];
        }


        $limiter = null;
        try {
            $limiter = QuotaLimiter::from_config();
        } catch (\Throwable $e) {
            $limiter = null;
        }

        $out = [];
        foreach ($registry as $row) {
            $scope = $row['scope'];
            $tier = $row['tier'];
            $credits = $tier_credits[$tier] ?? 0;
            $balance = $limiter !== null ? $limiter->quota_get($scope) : 0;
            $ttl = $limiter !== null ? $limiter->quota_ttl($scope) : 0;

            $denied = array_fill(0, $window['buckets'], 0);
            foreach ($denied_hashes[$scope] ?? [] as $hash) {
                foreach ($this->bucketize($denied_by_tag[$hash] ?? [], $window) as $i => $value) {
                    $denied[$i] += $value;
                }
            }

            $reason_totals = $denials_by_tag[$scope] ?? [];
            $denials = [
                'insufficient_balance' => (int)($reason_totals['insufficient_balance'] ?? 0),
                'pacing' => (int)($reason_totals['pacing'] ?? 0),
                'not_entitled' => (int)($reason_totals['not_entitled'] ?? 0),
                'duplicate_reservation' => (int)($reason_totals['duplicate_reservation'] ?? 0),
            ];

            $recent = [];
            foreach (StatsQuery::recent_events('quota.denial', 10, ['scope' => $scope]) as $event) {
                $recent[] = [
                    'ts' => date('Y-m-d H:i:s', (int)$event['created_at']),
                    'op' => (string)($event['data']['operation'] ?? ''),
                    'reason' => (string)($event['data']['reason'] ?? ''),
                ];
            }

            $out[] = [
                'scope' => $scope,
                'tier' => $tier,
                'balance' => $balance,
                'credits' => $credits,
                'ttl' => $ttl,
                'denials' => $denials,
                'pacing' => $this->pacing_windows($limiter, $scope, $tier),
                'recent' => $recent,
                'denied' => $denied,
            ];
        }

        return $out;
    }

    /** @param array<int,int> $rows bucket_start => value */
    private function bucketize(array $rows, array $window): array
    {
        return StatsQuery::fold($rows, $window);
    }

    /** @return list<array{key:string,window:int,limit:int,used:int}> */
    private function pacing_windows(?QuotaLimiter $limiter, string $scope, string $tier): array
    {
        $windows = [];
        $quotas = App::instance()->get('QUOTA.quotas.' . $tier);
        if (!is_array($quotas)) {
            return [];
        }
        $plan_pacing = is_array($quotas['pacing'] ?? null) ? $quotas['pacing'] : [];
        foreach ($plan_pacing as $spec) {
            $limit = (int)($spec['limit'] ?? 0);
            $window = (int)($spec['window'] ?? 0);
            if ($limit <= 0 || $window <= 0) {
                continue;
            }
            $windows[] = [
                'key' => QuotaPlan::TIER,
                'window' => $window,
                'limit' => $limit,
                'used' => $this->pacing_used($limiter, $scope, QuotaPlan::TIER, $window),
            ];
        }
        $operations = is_array($quotas['operations'] ?? null) ? $quotas['operations'] : [];
        foreach ($operations as $op_name => $spec) {
            foreach ((is_array($spec['pacing'] ?? null) ? $spec['pacing'] : []) as $w) {
                $limit = (int)($w['limit'] ?? 0);
                $window = (int)($w['window'] ?? 0);
                if ($limit <= 0 || $window <= 0) {
                    continue;
                }
                $windows[] = [
                    'key' => (string)$op_name,
                    'window' => $window,
                    'limit' => $limit,
                    'used' => $this->pacing_used($limiter, $scope, (string)$op_name, $window),
                ];
            }
        }

        return $windows;
    }

    private function pacing_used(?QuotaLimiter $limiter, string $scope, string $name, int $window): int
    {
        if ($limiter === null) {
            return 0;
        }
        try {
            $key = sprintf('{%s}.pacing.%s.%d%s', $scope, $name, $window, '.used');

            return $limiter->store()->get($key);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function quota(Range $range): QuotaReport
    {
        return QuotaReport::from_array((array)Redactor::redact_secrets($this->report($range->value)));
    }

    public function snapshot(): array
    {
        $denied_24h = 0;
        try {
            $series = StatsQuery::series('quota.denied', [], '24h');
            $denied_24h = array_sum($series);
        } catch (\Throwable $e) {
            $denied_24h = 0;
        }

        return ['denied_24h' => $denied_24h];
    }
}
