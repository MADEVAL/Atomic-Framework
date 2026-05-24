<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Queue\Adapters;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Enums\Driver;
use Engine\Atomic\Telemetry\Queue\EventType;

trait Redis
{
    public function push_telemetry(array $entry): bool
    {
        try {
            $redis = $this->connection_manager->get_redis(true);
            $prefix = $this->get_prefix();

            $uuid_job = $entry['uuid_job'];
            $uuid_batch = $entry['uuid_batch'];
            $ttl = $entry['ttl'];

            unset($entry['uuid_job'], $entry['uuid_batch'], $entry['ttl']);

            return (bool) $this->eval_lua(
                self::LUA_PUSH_TELEMETRY,
                [
                    $prefix . 'telemetry.jobs',
                    $prefix . 'telemetry.batch.' . $uuid_batch,
                    $uuid_job,
                    $uuid_batch,
                    $this->serialize($entry),
                    $ttl,
                ],
                2
            );
        } catch (\Exception $e) {
            Log::error("Error adding telemetry record to queue for uuid {$uuid_job}: " . $e->getMessage());
            return false;
        }
    }

    public function fetch_completed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->fetch_indexed_jobs_by_state($queue, State::COMPLETED->value, $page, $per_page);
    }

    public function fetch_failed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->fetch_indexed_jobs_by_state($queue, State::FAILED->value, $page, $per_page);
    }

    public function fetch_cancelled_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->fetch_indexed_jobs_by_state($queue, State::CANCELLED->value, $page, $per_page);
    }

    public function fetch_cancel_requested_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->fetch_indexed_jobs_by_state($queue, State::CANCEL_REQUESTED->value, $page, $per_page);
    }

    public function fetch_running_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->fetch_indexed_jobs_by_state($queue, State::RUNNING->value, $page, $per_page);
    }

    private function fetch_indexed_jobs_by_state(string $queue, string $state, int $page = 1, int $per_page = 50): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        $jobs = [];
        $total = 0;
        $offset = ($page - 1) * $per_page;

        try {
            $queue_names = ($queue === '*')
                ? ($redis->sMembers($prefix . 'meta.queues') ?: [])
                : [$queue];

            foreach ($queue_names as $q) {
                $res = $this->eval_lua(
                    self::LUA_LOAD_JOBS_BY_STATE,
                    [
                        $prefix . $q . '.idx.' . $state,
                        $prefix,
                        (string)$offset,
                        (string)$per_page,
                    ],
                    1
                );

                if (\is_array($res) && count($res) === 2) {
                    $total += (int)$res[0];
                    if (\is_array($res[1])) {
                        $this->process_indexed_jobs($res[1], $jobs, $state);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error fetching indexed jobs from queue: " . $e->getMessage());
        }
        return ['items' => $jobs, 'total' => $total];
    }

    private function process_indexed_jobs(array $res, array &$jobs, string $state): void {
        foreach ($res as $job) {
            $key = $job[0];
            $job_data = $this->deserialize($job[1]);
            $jobs[$key] = $job_data;
            
            // Payload is already a string in registry
            if (is_array($job_data['payload'] ?? null)) {
                $jobs[$key]['payload'] = $this->serialize($job_data['payload']);
            }
            
            if (!empty($job_data['exception'] ?? null)) {
                $jobs[$key]['exception'] = $this->deserialize($job_data['exception']);
            }
            
            $jobs[$key]['state'] = $state;
            $created_at = $jobs[$key]['created_at'] ?? time();
            $jobs[$key]['created_at_formatted'] = date('Y-m-d H:i:s', (int)$created_at);
            $this->add_cancellation_timestamps($jobs[$key]);
            $jobs[$key]['driver'] = Driver::REDIS->value;
        }
    }

    public function fetch_active_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        $pending = $this->fetch_pending_jobs($queue, $page, $per_page);
        $running = $this->fetch_running_jobs($queue, $page, $per_page);
        $cancel_requested = $this->fetch_cancel_requested_jobs($queue, $page, $per_page);

        return [
            'items' => \array_merge($running['items'], $cancel_requested['items'], $pending['items']),
            'total' => (int)($running['total'] ?? 0) + (int)($cancel_requested['total'] ?? 0) + (int)($pending['total'] ?? 0),
        ];
    }

    public function fetch_pending_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        $jobs = [];
        $total = 0;
        $offset = ($page - 1) * $per_page;
        $end = $offset + $per_page - 1;

        try {
            $queue_names = ($queue === '*')
                ? ($redis->sMembers($prefix . 'meta.queues') ?: [])
                : [$queue];

            foreach ($queue_names as $q) {
                $pending_index = $prefix . $q . '.idx.pending';
                $total += (int)$redis->zCard($pending_index);

                $uuids = $redis->zRange($pending_index, $offset, $end);
                if (!\is_array($uuids)) {
                    continue;
                }

                foreach ($uuids as $uuid) {
                    $raw = $redis->hGetAll($prefix . 'registry.' . $uuid);
                    if ($raw === false || $raw === []) {
                        continue;
                    }
                    $job = $this->normalize_registry_job_for_telemetry($raw);
                    if ($job === null) continue;
                    $job['state'] = State::PENDING->value;
                    $jobs[(string)$uuid] = $job;
                }
            }
        } catch (\Exception $e) {
            Log::error("Error fetching pending jobs from queue: " . $e->getMessage());
        }

        return ['items' => $jobs, 'total' => $total];
    }

    public function search_jobs_by_uuid(string $queue, string $uuid): array {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return ['items' => [], 'total' => 0];
        }

        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();
        $keyUuid = \strtolower($uuid);

        try {
            $raw = $redis->hGetAll($prefix . 'registry.' . $keyUuid);
            if ($raw === [] || $raw === false) {
                return ['items' => [], 'total' => 0];
            }
            $job = $this->normalize_registry_job_for_telemetry($raw);
            if ($job === null) {
                return ['items' => [], 'total' => 0];
            }
            if (!$this->telemetry_job_matches_queue($queue, $job)) {
                return ['items' => [], 'total' => 0];
            }
            return ['items' => [$keyUuid => $job], 'total' => 1];
        } catch (\Exception $e) {
            Log::error('Error searching queue jobs by UUID (Redis): ' . $e->getMessage());
            return ['items' => [], 'total' => 0];
        }
    }

    private function telemetry_job_matches_queue(string $queue, array $job): bool {
        return $queue === '*' || ($job['queue'] ?? '') === $queue;
    }

    private function normalize_registry_job_for_telemetry(array $job_data): ?array {
        $out = $job_data;
        $uuid = (string)($out['uuid'] ?? '');
        if (empty($out['state']) || !\is_string($out['state'])) {
            Log::error("Malformed Redis queue registry entry for UUID {$uuid}: missing state.");
            return null;
        }

        $p = $out['payload'] ?? null;
        if (\is_array($p)) {
            $out['payload'] = $this->serialize($p);
        } elseif (\is_string($p) && $p !== '') {
            $decoded = \json_decode($p, true);
            if (\is_array($decoded)) {
                $out['payload'] = $this->serialize($decoded);
            }
        }

        $state = $out['state'];
        if (
            \in_array($state, [State::COMPLETED->value, State::FAILED->value], true)
            && !empty($out['exception'] ?? null)
            && \is_string($out['exception'])
        ) {
            $out['exception'] = $this->deserialize($out['exception']);
        }

        $out['state'] = $state;

        $created_at = (int)($out['created_at'] ?? \time());
        $out['created_at_formatted'] = \date('Y-m-d H:i:s', $created_at);
        $this->add_cancellation_timestamps($out);
        $out['driver'] = Driver::REDIS->value;
        return $out;
    }

    private function add_cancellation_timestamps(array &$job): void {
        foreach (['cancel_requested_at', 'cancelled_at'] as $field) {
            $value = $job[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $job[$field . '_formatted'] = \is_numeric($value)
                ? \date('Y-m-d H:i:s', (int)$value)
                : (string)$value;
        }
    }

    public function fetch_events(string $queue, string $uuid): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        $events = [];

        try {
            $batches = $this->eval_lua(
                self::LUA_LOAD_EVENTS,
                [
                    $prefix . 'telemetry.jobs',
                    $uuid,
                    $prefix
                ],
                1
            );
            
            if (\is_array($batches)) {
                foreach ($batches as $batch) {
                    $batch_uuid = $batch[0];
                    $batch_events = array_map(function($event) use ($batch_uuid) {
                        $decoded_event = $this->deserialize($event);

                        $decoded_event['uuid_batch'] = $batch_uuid;
                        $decoded_event['event_description'] = $decoded_event['event_type_id'] ? EventType::from($decoded_event['event_type_id'])->description() : $decoded_event['message'];
                        $decoded_event['created_at_formatted'] = date('Y-m-d H:i:s', $decoded_event['created_at']);

                        return $decoded_event;
                    }, $batch[1]);
                    $events = array_merge($events, $batch_events);
                }
            }
        } catch (\Exception $e) {
            Log::error("Error fetching telemetry events from queue: " . $e->getMessage());
            return [];
        }

        return $events;
    }
}
