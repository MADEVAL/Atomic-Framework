<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Queue\Adapters;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Sanitizer;
use Engine\Atomic\Queue\Enums\Status;
use Engine\Atomic\Queue\Enums\Driver;
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

    public function fetch_completed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
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
            $offset = ($page - 1) * $per_page;
            $completed_jobs = $this->jobs_completed_mapper->find($where_clause, [
                'order'  => 'created_at DESC',
                'limit'  => $per_page,
                'offset' => $offset,
            ]);

            $total = $this->jobs_completed_mapper->count($where_clause);

            if ($completed_jobs === false) {
                return ['items' => [], 'total' => 0];
            }

            foreach ($completed_jobs as $job) {
                $jobs[$job->uuid] = $job->cast();
                $jobs[$job->uuid]['created_at_formatted'] = date('Y-m-d H:i:s', $job->created_at);
                $jobs[$job->uuid]['status'] = Status::COMPLETED->value;
                $jobs[$job->uuid]['driver'] = Driver::DATABASE->value;
            }

            return ['items' => $jobs, 'total' => $total];
        } catch (\Exception $e) {
            Log::error("Error fetching completed jobs from queue: " . $e->getMessage());
            return ['items' => [], 'total' => 0];
        }
    }

    public function fetch_failed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        Sanitizer::syncFromHive(App::atomic());
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
            $offset = ($page - 1) * $per_page;
            $failed_jobs = $this->jobs_failed_mapper->find($where_clause, [
                'order'  => 'created_at DESC',
                'limit'  => $per_page,
                'offset' => $offset,
            ]);

            $total = $this->jobs_failed_mapper->count($where_clause);

            if ($failed_jobs === false) {
                return ['items' => [], 'total' => 0];
            }

            foreach ($failed_jobs as $job) {
                $uuid = $job->uuid;
                $job_data = $job->cast();
                $job_data['uuid'] = $uuid;
                $job_data['exception'] = Sanitizer::normalize($this->deserialize($job->exception));
                $job_data['created_at_formatted'] = date('Y-m-d H:i:s', $job->created_at);
                $job_data['status'] = Status::FAILED->value;
                $job_data['driver'] = Driver::DATABASE->value;
                $jobs[$uuid] = $job_data;
            }

            return ['items' => $jobs, 'total' => $total];
        } catch (\Exception $e) {
            Log::error("Error fetching failed jobs from queue: " . $e->getMessage());
            return ['items' => [], 'total' => 0];
        }
    }

    public function fetch_in_progress_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
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
            $offset = ($page - 1) * $per_page;
            $in_progress_jobs = $this->jobs_mapper->find($where_clause, [
                'order'  => 'created_at DESC',
                'limit'  => $per_page,
                'offset' => $offset,
            ]);

            $total = $this->jobs_mapper->count($where_clause);

            if ($in_progress_jobs === false) {
                return ['items' => [], 'total' => 0];
            }

            foreach ($in_progress_jobs as $job) {
                $jobs[$job->uuid] = $job->cast();
                $jobs[$job->uuid]['created_at_formatted'] = date('Y-m-d H:i:s', $job->created_at);
                $jobs[$job->uuid]['status'] = isset($job->process_start_ticks) && $job->process_start_ticks
                    ? Status::RUNNING->value
                    : Status::QUEUED->value;
                $jobs[$job->uuid]['driver'] = Driver::DATABASE->value;
            }

            return ['items' => $jobs, 'total' => $total];
        } catch (\Exception $e) {
            Log::error("Error fetching in-progress jobs from queue: " . $e->getMessage());
            return ['items' => [], 'total' => 0];
        }
    }

    public function fetch_pending_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }

        $jobs = [];
        try {
            $conditions = ['(process_start_ticks IS NULL OR process_start_ticks = 0)'];
            $params = [];

            if (!empty($queue) && $queue !== '*') {
                $conditions[] = 'queue = ?';
                $params[] = $queue;
            }

            $where_sql = \implode(' AND ', $conditions);
            $where_clause = [$where_sql, ...$params];
            $offset = ($page - 1) * $per_page;

            $pending_jobs = $this->jobs_mapper->find($where_clause, [
                'order'  => 'created_at DESC',
                'limit'  => $per_page,
                'offset' => $offset,
            ]);

            $total = $this->jobs_mapper->count($where_clause);

            if ($pending_jobs === false) {
                return ['items' => [], 'total' => 0];
            }

            foreach ($pending_jobs as $job) {
                $jobs[$job->uuid] = $job->cast();
                $jobs[$job->uuid]['created_at_formatted'] = date('Y-m-d H:i:s', $job->created_at);
                $jobs[$job->uuid]['status'] = Status::QUEUED->value;
                $jobs[$job->uuid]['driver'] = Driver::DATABASE->value;
            }

            return ['items' => $jobs, 'total' => $total];
        } catch (\Exception $e) {
            Log::error("Error fetching pending jobs from queue: " . $e->getMessage());
            return ['items' => [], 'total' => 0];
        }
    }

    public function search_jobs_by_uuid(string $queue, string $uuid): array {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return ['items' => [], 'total' => 0];
        }

        Sanitizer::syncFromHive(App::atomic());
        [$sql] = $this->connection_manager->get_db(true, true);

        $prefix = App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX');
        $qj = $sql->quotekey($prefix . 'jobs');
        $qf = $sql->quotekey($prefix . 'jobs_failed');
        $qc = $sql->quotekey($prefix . 'jobs_completed');

        $queueScoped = $queue !== '*' && $queue !== '';
        if ($queueScoped) {
            $params = [$uuid, $queue, $uuid, $queue, $uuid, $queue];
            $whereJobs = 'j.uuid = ? AND j.queue = ?';
            $whereFailed = 'f.uuid = ? AND f.queue = ?';
            $whereCompleted = 'c.uuid = ? AND c.queue = ?';
        } else {
            $params = [$uuid, $uuid, $uuid];
            $whereJobs = 'j.uuid = ?';
            $whereFailed = 'f.uuid = ?';
            $whereCompleted = 'c.uuid = ?';
        }

        $query =
            'SELECT * FROM (' .
            "SELECT j.id, j.uuid, j.queue, j.priority, j.payload, j.max_attempts, j.attempts, j.timeout, j.retry_delay, j.created_at, " .
            'j.available_at, j.pid, j.process_start_ticks, NULL AS exception, ' .
            "CASE WHEN j.process_start_ticks IS NOT NULL AND j.process_start_ticks <> 0 THEN '" . Status::RUNNING->value . "' ELSE '" . Status::QUEUED->value . "' END AS job_status " .
            "FROM {$qj} AS j WHERE {$whereJobs} " .
            'UNION ALL ' .
            "SELECT f.id, f.uuid, f.queue, f.priority, f.payload, f.max_attempts, f.attempts, f.timeout, f.retry_delay, f.created_at, " .
            "NULL AS available_at, NULL AS pid, NULL AS process_start_ticks, f.exception, '" . Status::FAILED->value . "' AS job_status " .
            "FROM {$qf} AS f WHERE {$whereFailed} " .
            'UNION ALL ' .
            "SELECT c.id, c.uuid, c.queue, c.priority, c.payload, c.max_attempts, c.attempts, c.timeout, c.retry_delay, c.created_at, " .
            "NULL AS available_at, NULL AS pid, NULL AS process_start_ticks, NULL AS exception, '" . Status::COMPLETED->value . "' AS job_status " .
            "FROM {$qc} AS c WHERE {$whereCompleted}" .
            ') AS combined ORDER BY combined.created_at DESC LIMIT 500';

        try {
            $rows = $sql->exec($query, $params);
            if (!\is_array($rows)) {
                return ['items' => [], 'total' => 0];
            }

            $jobs = [];
            foreach ($rows as $row) {
                $status = $row['job_status'];
                unset($row['job_status']);

                if ($status === Status::FAILED->value) {
                    $row['exception'] = Sanitizer::normalize($this->deserialize((string)$row['exception']));
                } else {
                    unset($row['exception']);
                }

                $createdAt = (int)($row['created_at'] ?? 0);
                $row['created_at_formatted'] = date('Y-m-d H:i:s', $createdAt);
                $row['status'] = $status;
                $row['driver'] = Driver::DATABASE->value;
                $jobs[$row['uuid']] = $row;
            }

            return ['items' => $jobs, 'total' => \count($jobs)];
        } catch (\Exception $e) {
            Log::error('Error searching queue jobs by UUID (database): ' . $e->getMessage());
            return ['items' => [], 'total' => 0];
        }
    }

    public function fetch_events(string $queue, string $uuid): array {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->queue_telemetry_mapper) {
            $this->queue_telemetry_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry');
        }

        try {
            $events_data = $this->queue_telemetry_mapper->find(
                ['uuid_job = ?', $uuid],
                ['order' => 'created_at DESC, id DESC']
            );

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
