<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Telemetry\Data\Job;
use Engine\Atomic\Telemetry\Data\JobEvent;

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

    /** @return list<Job> */
    public function job_list(int $per_page = 200): array
    {
        $jobs = [];
        foreach ((array)Redactor::redact_secrets($this->jobs($per_page)) as $uuid => $row) {
            $jobs[] = Job::from_row((string)$uuid, $row);
        }

        return $jobs;
    }

    /** @return list<JobEvent> a job's telemetry timeline, redacted like GET /telemetry/events */
    public function events(string $driver, string $uuid): array
    {
        $rows = Redactor::redact_secrets(($this->manager ?? new TelemetryManager())->fetch_events($driver, 'default', $uuid));

        return array_map(static fn (array $row): JobEvent => JobEvent::from_row($row), is_array($rows) ? array_values(array_filter($rows, 'is_array')) : []);
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
