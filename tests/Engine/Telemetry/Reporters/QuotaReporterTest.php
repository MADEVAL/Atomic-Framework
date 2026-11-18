<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Reporters;

use Engine\Atomic\Telemetry\Reporters\QuotaReporter;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class QuotaReporterTest extends StatsDbTestCase
{
    public function test_scope_denied_series_counts_denials_of_every_reason(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->observe_quota_scope('user:1', 'free');
        $recorder->record('quota.denied', 2, ['scope' => 'user:1', 'reason' => 'pacing']);
        $recorder->record('quota.denied', 3, ['scope' => 'user:1', 'reason' => 'insufficient_balance']);
        $recorder->flush();

        $scope = $this->scope((new QuotaReporter())->report('24h'), 'user:1');

        self::assertSame(5, array_sum($scope['denied']));
    }


    private function scope(array $report, string $name): array
    {
        foreach ($report['scopes'] as $scope) {
            if ($scope['scope'] === $name) {
                return $scope;
            }
        }
        self::fail("Scope {$name} missing from report");
    }
}
