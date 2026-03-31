<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Monitor\Adapters;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;

trait DB 
{
    public function load_stuck_jobs(array $exclude, string $queue = '*'): array
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }

        try {
            $conditions = ['available_at < ? AND pid != ?', \time(), null];
            
            if ($queue !== '*' && !empty($queue)) {
                $conditions[0] .= " AND queue = ?";
                $conditions[] = $queue;
            }
            
            if (!empty($exclude)) {
                $placeholders = implode(',', array_fill(0, count($exclude), '?'));
                $conditions[0] .= " AND uuid NOT IN ($placeholders)";
                $conditions = array_merge($conditions, $exclude);
            }
            
            $jobs = $this->jobs_mapper->find($conditions);

            $result = [];
            if ($jobs && count($jobs)) {
                foreach ($jobs as $job) {
                    $job = $job->cast();
                    $job['payload'] = $this->deserialize($job['payload']);
                    $result[] = $job;
                }
            }
            return $result;
        } catch (\Exception $e) {
            Log::error("Error while trying to load overdue jobs: " . $e->getMessage());
            return [];
        }
    }


    public function load_jobs_in_progress(string $queue = '*'): array
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }

        try {
            $conditions = ['available_at > ? AND pid != ?', \time(), null];
            
            if ($queue !== '*' && !empty($queue)) {
                $conditions[0] .= " AND queue = ?";
                $conditions[] = $queue;
            }
            
            $jobs = $this->jobs_mapper->find($conditions);

            $result = [];
            if ($jobs && count($jobs)) {
                foreach ($jobs as $job) {
                    $job = $job->cast();
                    $job['payload'] = $this->deserialize($job['payload']);
                    $result[] = $job;
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error("DatabaseQueueDriver load_jobs_in_progress error: " . $e->getMessage());
            return [];
        }
    }

    public function handle_incomplete_job(array $job): bool
    {
        try {
            $max_attempts = $job['max_attempts'];
            $current_attempts = $job['attempts'];

            if ($current_attempts >= $max_attempts) {
                Log::warning("Job with ID {$job['uuid']} exceeded the maximum number of attempts ({$max_attempts})");
                return $this->mark_failed($job, new \Exception("Job exceeded the maximum number of attempts"));
            } else {
                $retry_delay = $job['retry_delay'];
                Log::warning("Job with ID {$job['uuid']} has been released back to the queue for retry (attempt {$current_attempts}/{$max_attempts})");
                return $this->release($job, $retry_delay);
            }
        } catch (\Exception $e) {
            Log::error("Error while handling incomplete job with ID {$job['uuid']}: " . $e->getMessage());
            return false;
        }
    }

    public function exists_in_jobs_table(string $uuid, int $pid): bool
    {
        $sql = $this->connection_manager->get_db();
        if (!$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }

        try {
            $this->jobs_mapper->load(['uuid = ? AND pid = ?', $uuid, $pid]);
            return !$this->jobs_mapper->dry();
        } catch (\Exception $e) {
            Log::error("Error checking if job exists in jobs table: " . $e->getMessage());
            return false;
        }
    }
}