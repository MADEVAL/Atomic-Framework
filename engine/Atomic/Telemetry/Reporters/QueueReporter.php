<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Managers\TelemetryManager;

class QueueReporter implements SnapshotProviderInterface
{
    public function __construct(private readonly ?TelemetryManager $manager = null)
    {
    }

    /** @return array<string, array<string, mixed>> uuid => job row */
    public function jobs(int $per_page = 200): array
    {
        try {
            $result = ($this->manager ?? new TelemetryManager())->fetch_all_jobs('*', [], 1, max(1, $per_page));
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];

            $out = [];
            foreach ($items as $uuid => $job) {
                if (!is_array($job)) {
                    continue;
                }
                $state = (string)($job['state'] ?? '');
                $job['state'] = $state === 'canceled' ? 'cancelled' : $state;
                $out[(string)$uuid] = $job;
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function snapshot(): array
    {
        $totals = array_fill_keys(['failed', 'pending', 'running', 'completed', 'cancel_requested', 'cancelled'], 0);
        try {
            $result = ($this->manager ?? new TelemetryManager())->fetch_all_jobs('*', [], 1, 1);
            $state_totals = is_array($result['state_totals'] ?? null) ? $result['state_totals'] : [];
            foreach (array_keys($totals) as $state) {
                $totals[$state] = (int)($state_totals[$state] ?? 0);
            }
        } catch (\Throwable $e) {
        }

        return $totals;
    }
}
