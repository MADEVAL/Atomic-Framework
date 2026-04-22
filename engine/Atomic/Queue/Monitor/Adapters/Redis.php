<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Monitor\Adapters;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;

trait Redis {
    public function load_stuck_jobs(array $exclude, string $queue = '*'): array
    {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = App::instance()->get('REDIS.prefix');
        $now = \time();
        $stuck_jobs = [];
        $exclude_json = $this->serialize($exclude);

        try {
            if (!isset($this->script_shas['load_stuck'])) {
                if (!$this->reload_lua_script('load_stuck')) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->error("Failed to load Lua script: load_stuck");
                    return [];
                }
            }

            $queue_names = ($queue === '*')
                ? ($redis->sMembers($prefix . 'meta.queues') ?: [])
                : [$queue];

            foreach ($queue_names as $q) {
                $result = $redis->evalSha(
                    $this->script_shas['load_stuck'],
                    [
                        $prefix . $q . '.idx.running',
                        $prefix,
                        $now,
                        $exclude_json
                    ],
                    1
                );

                if (!empty($result) && is_array($result)) {
                    foreach ($result as $job_json) {
                        $stuck_jobs[] = $this->deserialize($job_json);
                    }
                }
            }

            return $stuck_jobs;
        } catch (\Exception $e) {
            Log::channel(LogChannel::QUEUE_MONITOR)->error("Error loading stuck jobs: " . $e->getMessage());
            return [];
        }
    }

    public function load_jobs_in_progress(string $queue = '*'): array
    {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = App::instance()->get('REDIS.prefix');
        $res = [];

        try {
            if (!isset($this->script_shas['load_in_progress_monitor'])) {
                if (!$this->reload_lua_script('load_in_progress_monitor')) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->error("Failed to load Lua script: load_in_progress_monitor");
                    return [];
                }
            }

            $result = $redis->evalSha(
                $this->script_shas['load_in_progress_monitor'],
                [
                    $prefix . 'meta.pid_map',
                    $prefix
                ],
                1
            );

            if (!empty($result) && is_array($result)) {
                foreach ($result as $job_json) {
                    $job = $this->deserialize($job_json);
                    if (isset($job['payload']) && is_string($job['payload'])) {
                        $job['payload'] = $this->deserialize($job['payload']);
                    }
                    
                    if ($queue === '*' || (isset($job['queue']) && $job['queue'] === $queue)) {
                        $res[] = $job;
                    }
                }
            }
            
            return $res;
        } catch (\Exception $e) {
            Log::channel(LogChannel::QUEUE_MONITOR)->error("Error loading jobs in progress: " . $e->getMessage());
            return [];
        }
    }

    public function handle_incomplete_job(array $job): bool {
        try {
            $max_attempts = $job['max_attempts'];
            if($job['attempts'] >= $max_attempts) {
                Log::channel(LogChannel::QUEUE_MONITOR)->warning("Job with ID {$job['uuid']} exceeded the maximum number of attempts ({$max_attempts})");
                return $this->mark_failed($job, new \Exception("Job exceeded the maximum number of attempts"));
            } else {
                $retry_delay = $job['retry_delay'];
                $result = $this->release($job, (int)$retry_delay);
                Log::channel(LogChannel::QUEUE_MONITOR)->warning("Job with ID {$job['uuid']} returned to queue for retry");
                return $result;
            }
        } catch (\Throwable $th) {
            Log::channel(LogChannel::QUEUE_MONITOR)->error("Error handling incomplete job: " . $th->getMessage());
            return false;
        }
    }

    public function exists_in_jobs_table(string $uuid, int $pid): bool
    {
        $redis = $this->connection_manager->get_redis();
        $prefix = App::instance()->get('REDIS.prefix');

        try {
            $stored_pid = $redis->hGet($prefix . 'registry.' . $uuid, 'pid');
            return $stored_pid !== false && (int)$stored_pid === $pid;
        } catch (\Exception $e) {
            Log::channel(LogChannel::QUEUE_MONITOR)->error("Error checking if job exists in Redis registry: " . $e->getMessage());
            return false;
        }
    }
}
