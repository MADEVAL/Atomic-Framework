<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Queue\Adapters;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Sanitizer;
use Engine\Atomic\Telemetry\Queue\EventType;

trait Redis
{
    public function push_telemetry(array $entry): bool
    {
        try {
            $redis = $this->connection_manager->get_redis(true);
            $prefix = App::instance()->get('REDIS.ATOMIC_REDIS_QUEUE_PREFIX');

            $uuid_job = $entry['uuid_job'];
            $uuid_batch = $entry['uuid_batch'];
            $ttl = $entry['ttl'];

            unset($entry['uuid_job'], $entry['uuid_batch'], $entry['ttl']);

            return (bool) $redis->evalSha(
                $this->script_shas['push_telemetry'],
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
        return $this->fetch_finished_jobs($queue, true, $page, $per_page);
    }

    public function fetch_failed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->fetch_finished_jobs($queue, false, $page, $per_page);
    }

    private function fetch_finished_jobs(string $queue, bool $completed, int $page = 1, int $per_page = 50): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = App::instance()->get('REDIS.ATOMIC_REDIS_QUEUE_PREFIX');

        $jobs = [];
        $total = 0;
        $state = $completed ? 'completed' : 'failed';
        $offset = ($page - 1) * $per_page;

        try {
            $queue_names = ($queue === '*')
                ? ($redis->sMembers($prefix . 'meta.queues') ?: [])
                : [$queue];

            foreach ($queue_names as $q) {
                $res = $redis->evalSha(
                    $this->script_shas['load_finished'],
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
                        $this->process_finished_jobs($res[1], $jobs, $completed);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error fetching finished jobs from queue: " . $e->getMessage());
        }
        return ['items' => $jobs, 'total' => $total];
    }

    private function process_finished_jobs(array $res, array &$jobs, bool $completed): void {
        Sanitizer::syncFromHive(App::atomic());
        foreach ($res as $job) {
            $key = $job[0];
            $job_data = $this->deserialize($job[1]);
            $jobs[$key] = $job_data;
            
            // Payload is already a string in registry
            if (is_array($job_data['payload'] ?? null)) {
                $jobs[$key]['payload'] = $this->serialize($job_data['payload']);
            }
            
            if (!empty($job_data['exception'] ?? null)) {
                $jobs[$key]['exception'] = Sanitizer::normalize($this->deserialize($job_data['exception']));
            }
            
            $jobs[$key]['status'] = $completed ? 'completed' : 'failed';
            $created_at = $jobs[$key]['created_at'] ?? time();
            $jobs[$key]['created_at_formatted'] = date('Y-m-d H:i:s', (int)$created_at);
            $jobs[$key]['driver'] = 'redis';
        }
    }

    public function fetch_in_progress_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = App::instance()->get('REDIS.ATOMIC_REDIS_QUEUE_PREFIX');

        $jobs = [];
        $total = 0;
        $offset = ($page - 1) * $per_page;

        try {
            $queue_names = ($queue === '*')
                ? ($redis->sMembers($prefix . 'meta.queues') ?: [])
                : [$queue];

            foreach ($queue_names as $q) {
                $result = $redis->evalSha(
                    $this->script_shas['load_in_progress'],
                    [
                        $prefix . $q . '.idx.pending',
                        $prefix . $q . '.idx.running',
                        $prefix,
                        (string)$offset,
                        (string)$per_page,
                    ],
                    2
                );

                if (\is_array($result) && count($result) === 2) {
                    $total += (int)$result[0];
                    if (\is_array($result[1])) {
                        foreach ($result[1] as $row) {
                            $uuid = $row[0];
                            $job_data = $this->deserialize($row[1]);
                            $jobs[$uuid] = $job_data;
                            $jobs[$uuid]['created_at_formatted'] = date('Y-m-d H:i:s', (int)($job_data['created_at'] ?? \time()));
                            $jobs[$uuid]['driver'] = 'redis';
                            if (\is_array($job_data['payload'] ?? null)) {
                                $jobs[$uuid]['payload'] = $this->serialize($job_data['payload']);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error fetching in-progress jobs from queue: " . $e->getMessage());
        }
        return ['items' => $jobs, 'total' => $total];
    }

    public function fetch_events(string $queue, string $uuid): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = App::instance()->get('REDIS.ATOMIC_REDIS_QUEUE_PREFIX');

        $events = [];

        try {
            $batches = $redis->evalSha(
                $this->script_shas['load_events'],
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
