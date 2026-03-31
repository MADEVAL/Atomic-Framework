<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Queue\Adapters;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Telemetry\Queue\EventType;

trait DB
{
    private ?Cortex $queue_telemetry_mapper = null;

    public function push_telemetry(array $entry): bool
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->queue_telemetry_mapper) {
            $this->queue_telemetry_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry');
        }

        try {
            $this->queue_telemetry_mapper->reset();
            $this->queue_telemetry_mapper->uuid = ID::uuid_v4();
            $this->queue_telemetry_mapper->uuid_batch = $entry['uuid_batch'];
            $this->queue_telemetry_mapper->uuid_job = $entry['uuid_job'];
            $this->queue_telemetry_mapper->event_type_id = $entry['event_type_id'];
            $this->queue_telemetry_mapper->message = $entry['message'];
            $this->queue_telemetry_mapper->created_at = \time();

            $this->queue_telemetry_mapper->save();
            return true;
        } catch (\Exception $e) {
            Log::error("Error adding telemetry record to queue: " . $e->getMessage());
            return false;
        }
    }

    public function fetch_completed_jobs(string $queue = '*', array $filters = []): array {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_completed_mapper) {
            $this->jobs_completed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_completed');
        }

        $jobs = [];
        try {
            $conditions = [];
            $params = [];
            
            if (!empty($queue) && $queue !== '*') {
                $conditions[] = 'queue = ?';
                $params[] = $queue;
            }
            
            $where_clause = empty($conditions) ? [] : [\implode(' AND ', $conditions), ...$params];
            $completed_jobs = $this->jobs_completed_mapper->find($where_clause, ['order' => 'created_at DESC']);

            if ($completed_jobs === false) {
                return [];
            }

            foreach ($completed_jobs as $job) {
                $jobs[$job->uuid] = $job->cast();
                $jobs[$job->uuid]['created_at_formatted'] = date('Y-m-d H:i:s', $job->created_at);
                $jobs[$job->uuid]['status'] = 'completed';
                $jobs[$job->uuid]['driver'] = 'database';
            }

            return $jobs;
        } catch (\Exception $e) {
            Log::error("Error fetching completed jobs from queue: " . $e->getMessage());
            return [];
        }
    }

    public function fetch_failed_jobs(string $queue = '*', array $filters = []): array {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_failed_mapper) {
            $this->jobs_failed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed');
        }

        $jobs = [];
        try {
            $conditions = [];
            $params = [];
            
            if (!empty($queue) && $queue !== '*') {
                $conditions[] = 'queue = ?';
                $params[] = $queue;
            }
            
            $where_clause = empty($conditions) ? [] : [\implode(' AND ', $conditions), ...$params];
            $failed_jobs = $this->jobs_failed_mapper->find($where_clause, ['order' => 'created_at DESC']);
            
            if ($failed_jobs === false) {
                return [];
            }

            foreach ($failed_jobs as $job) {
                $uuid = $job->uuid;
                $job_data = $job->cast();
                $job_data['uuid'] = $uuid;
                $job_data['exception'] = $this->deserialize($job->exception);
                $job_data['created_at_formatted'] = date('Y-m-d H:i:s', $job->created_at);
                $job_data['status'] = 'failed';
                $job_data['driver'] = 'database';
                $jobs[$uuid] = $job_data;
            }

            return $jobs;
        } catch (\Exception $e) {
            Log::error("Error fetching failed jobs from queue: " . $e->getMessage());
            return [];
        }
    }

    public function fetch_in_progress_jobs(string $queue = '*', array $filters = []): array {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }

        $jobs = [];
        try {
            $conditions = [];
            $params = [];

            if (!empty($queue) && $queue !== '*') {
                $conditions[] = 'queue = ?';
                $params[] = $queue;
            }
            
            $where_clause = empty($conditions) ? [] : [\implode(' AND ', $conditions), ...$params];
            $in_progress_jobs = $this->jobs_mapper->find($where_clause, ['order' => 'created_at DESC']);

            if ($in_progress_jobs === false) {
                return [];
            }

            foreach ($in_progress_jobs as $job) {
                $jobs[$job->uuid] = $job->cast();
                $jobs[$job->uuid]['created_at_formatted'] = date('Y-m-d H:i:s', $job->created_at);
                $jobs[$job->uuid]['status'] = isset($job->process_start_ticks) && $job->process_start_ticks ? 'running' : 'queued';
                $jobs[$job->uuid]['driver'] = 'database';
            }

            return $jobs;
        } catch (\Exception $e) {
            Log::error("Error fetching in-progress jobs from queue: " . $e->getMessage());
            return [];
        }
    }

    public function fetch_events(string $queue, string $uuid): array {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->queue_telemetry_mapper) {
            $this->queue_telemetry_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry');
        }

        try {
            $events_data = $this->queue_telemetry_mapper->find(['uuid_job = ?', $uuid], ['order' => 'id ASC']);

            if ($events_data === false) {
                Log::error("Error fetching telemetry events from queue: query returned false");
                return [];
            }

            $events = [];
            foreach ($events_data as $row) {
                $event = $row->cast();
                $event['uuid'] = $row->uuid;
                $event['uuid_batch'] = $row->uuid_batch;
                $event['uuid_job'] = $row->uuid_job;
                $event['event_description'] = $row->event_type_id ? EventType::from($row->event_type_id)->description() : $row->message;
                $event['created_at_formatted'] = date('Y-m-d H:i:s', $row->created_at);
                $events[] = $event;
            }

            return $events;
        } catch (\Exception $e) {
            Log::error("Error fetching telemetry events from queue: " . $e->getMessage());
            return [];
        }
    }
}