<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Drivers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Queue\Interfaces\Base;
use Engine\Atomic\Queue\Interfaces\Management;
use Engine\Atomic\Queue\Interfaces\Telemetry;
use Engine\Atomic\Queue\Managers\ProcessManager;
use Engine\Atomic\Queue\Monitor\Adapters\Redis as RedisMonitorAdapter;
use Engine\Atomic\Telemetry\Queue\Adapters\Redis as RedisTelemetryAdapter;

class Redis implements Base, Management, Telemetry
{
    use RedisMonitorAdapter;
    use RedisTelemetryAdapter;

    private ProcessManager $process_manager;
    private ConnectionManager $connection_manager;
    private array $script_shas = [];
    private ?string $lua_dir = null;

    public function __construct() {
        $this->process_manager = new ProcessManager();
        $this->connection_manager = new ConnectionManager();
        if (!$this->load_lua_scripts()) {
            throw new \Exception("Failed to load Lua scripts into Redis");
        }
    }

    public function open_connection(): void {
        $this->connection_manager->close();
        $this->connection_manager = new ConnectionManager();
        $this->load_lua_scripts();
    }

    public function close_connection(): void {
        $this->connection_manager->close_redis();
    }

    private function get_prefix(): string {
        return App::instance()->get('REDIS.ATOMIC_REDIS_QUEUE_PREFIX');
    }

    private function load_lua_scripts(): bool 
    {
        $lua_dir = realpath(__DIR__ . '/lua');
        if ($lua_dir === false || !is_dir($lua_dir)) {
            Log::warning('Lua scripts directory not found: ' . __DIR__ . '/lua');
            return false;
        }

        $this->lua_dir = $lua_dir;
        
        $scripts = glob($lua_dir . '/*.lua');
        if (empty($scripts)) {
            Log::warning("No Lua scripts found in directory: $lua_dir");
            return false;
        }
        
        foreach ($scripts as $script_path) {
            $script_name = \basename($script_path, '.lua');
            if (!$this->load_single_lua_script($script_name)) {
                return false;
            }
        }
        return true;
    }

    private function reload_lua_script(string $script_name): bool {
        $redis = $this->connection_manager->get_redis(true);
        
        $sha = $this->script_shas[$script_name] ?? null;
        
        if ($sha) {
            try {
                $exists = $redis->script('EXISTS', $sha);
                if (is_array($exists) && isset($exists[0]) && $exists[0] === 1) {
                    return true;
                }
            } catch (\Exception $e) {
                Log::warning("Error checking script existence: " . $e->getMessage());
            }
        }
        
        Log::info("Script '$script_name' not found in Redis memory, reloading");
        return $this->load_single_lua_script($script_name);
    }
    
    private function load_single_lua_script(string $script_name): bool {
        $redis = $this->connection_manager->get_redis(true);

        $lua_dir = $this->lua_dir ?? realpath(__DIR__ . '/lua');
        if ($lua_dir === false || $lua_dir === null) {
            Log::error('Cannot resolve Lua scripts directory: ' . __DIR__ . '/lua');
            return false;
        }

        $script_path = $lua_dir . '/' . $script_name . '.lua';
        
        if (!file_exists($script_path)) {
            Log::error("Lua script file not found: $script_path");
            return false;
        }
        
        $script_content = file_get_contents($script_path);
        if ($script_content === false) {
            Log::error("Failed to read Lua script: $script_path");
            return false;
        }
        
        try {
            $sha = $redis->script('LOAD', $script_content);
            $this->script_shas[$script_name] = $sha;
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to load Lua script '$script_name': " . $e->getMessage());
            return false;
        }
    }

    protected function serialize(array $data): string {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    protected function deserialize(string $data): array {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    public function push(array $payload, array $options = []): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        $available_at = time() + (int)$options['delay'];
        $created_at = time();
        $handler = $payload['handler'];

        $payload_json = $this->serialize([
            'handler'    => $handler,
            'data'       => $payload['data'] ?? [],
            'uuid_batch' => $options['uuid_batch'],
        ]);

        try {
            return (bool)$redis->evalSha(
                $this->script_shas['push'],
                [
                    $prefix . 'registry.' . $options['uuid'],
                    $prefix . $options['queue'] . '.idx.pending',
                    $prefix . $options['queue'] . '.meta.sequence',
                    $prefix . 'meta.queues',
                    $options['uuid'],
                    (string)$available_at,
                    (string)(int)$options['priority'],
                    $options['queue'],
                    (string)(int)$options['max_attempts'],
                    (string)(int)($options['attempts'] ?? 0),
                    (string)(int)$options['timeout'],
                    (string)(int)$options['retry_delay'],
                    (string)$created_at,
                    $handler,
                    $payload_json,
                ],
                4
            );
        } catch (\Exception $e) {
            Log::error("Error adding job to queue: " . $e->getMessage() . " at line " . $e->getLine());
            return false;
        }
    }

    public function pop_batch(string $queue, int $limit): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();
        $now = \time();
        $jobs = [];

        if (!isset($this->script_shas['load_batch'])) {
            Log::error("Lua script 'load_batch' not loaded");
            if (!$this->reload_lua_script('load_batch')) {
                return [];
            }
        }
        
        try {
            $result = $redis->evalSha(
                $this->script_shas['load_batch'],
                [
                    $prefix . $queue . '.idx.pending',
                    $prefix . $queue . '.idx.running',
                    $prefix,
                    $now,
                    $limit
                ],
                2
            );

            if (\is_array($result)) {
                $jobs = \array_map(fn ($item) => $this->deserialize($item), $result);
            }
        } catch (\Exception $e) {
            Log::error("Error extracting jobs from queue: " . $e->getMessage());
        }
        return $jobs;
    }

    public function release(array $job, int $delay): bool 
    {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas['release'])) {
                Log::error("Lua script 'release' not loaded");
                if (!$this->reload_lua_script('release')) {
                    return false;
                }
            }

            $queue = $job['queue'];
            $job_uuid = $job['uuid'];
            $pid = $job['pid'];
            $available_at = time() + $delay;

            $result = $redis->evalSha(
                $this->script_shas['release'],
                [
                    $prefix . 'registry.' . $job_uuid,
                    $prefix . $queue . '.idx.running',
                    $prefix . $queue . '.idx.pending',
                    $prefix . 'meta.pid_map',
                    $prefix . $queue . '.meta.sequence',
                    $job_uuid,
                    $queue,
                    $pid,
                    $available_at,
                ],
                5
            );

            return (bool)$result;
        } catch (\Throwable $th) {
            Log::error("Error trying to release job: " . $th->getMessage() . " at line " . $th->getLine());
            return false;
        }
    }

    private function mark_finished(array $job, bool $failed): bool 
    {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        $job_uuid = $job['uuid'];
        $queue = $job['queue'];

        try {
            $timestamp = \time();
            $exception_json = '';
            
            if ($failed && isset($job['exception'])) {
                $exception_json = $this->serialize($job['exception']);
            }

            $atomic = App::instance();
            $driver_name = $atomic->get('QUEUE_DRIVER');
            $ttl = $atomic->get("QUEUE.{$driver_name}.queues.{$queue}.ttl", 0);

            $result = $redis->evalSha(
                $this->script_shas['mark_finished'],
                [
                    $prefix . 'registry.' . $job_uuid,
                    $prefix . $queue . '.idx.running',
                    $prefix . $queue . '.idx.' . ($failed ? 'failed' : 'completed'),
                    $prefix . 'meta.pid_map',
                    $job_uuid,
                    (int)$failed,
                    $timestamp,
                    $exception_json,
                    (int)$ttl
                ],
                4
            );

            return (bool)$result;
        } catch (\Exception $e) {
            Log::error("Error marking job as finished: " . $e->getMessage());
            return false;
        }
    }

    public function mark_failed(array $job, \Throwable $exception): bool {
        Log::error("Job with UUID " . ($job['uuid'] ?? 'N/A') . " failed: " . $exception->getMessage());
        $job['exception'] = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace_string' => $exception->getTraceAsString()
        ];

        return $this->mark_finished($job, true);
    }

    public function mark_completed(array $job): bool {
        return $this->mark_finished($job, false);
    }

    public function set_pid(array $job): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            $job_uuid = $job['uuid'];

            if ($job_uuid === null) {
                Log::error("Error while trying to set PID: Job UUID not set");
                return false;
            }

            if (!isset($this->script_shas['set_pid'])) {
                Log::error("Lua script 'set_pid' not loaded");
                if (!$this->reload_lua_script('set_pid')) {
                    return false;
                }
            }

            $pid = \getmypid();
            $process_start_ticks = $this->process_manager->get_process_start_ticks($pid);

            $result = $redis->evalSha(
                $this->script_shas['set_pid'],
                [
                    $prefix . 'registry.' . $job_uuid,
                    $prefix . 'meta.pid_map',
                    $job_uuid,
                    $pid,
                    $process_start_ticks
                ],
                2
            );

            return (bool)$result;
        } catch (\Throwable $th) {
            Log::error("Error trying to set PID for job: " . $th->getMessage() . " at line " . $th->getLine());
            return false;
        }
    }

    public function retry(string $queue = 'default'): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas['retry_all'])) {
                Log::error("Lua script 'retry_all' not loaded");
                if (!$this->reload_lua_script('retry_all')) {
                    return false;
                }
            }

            $now = time();

            $retried_count = $redis->evalSha(
                $this->script_shas['retry_all'],
                [
                    $prefix . $queue . '.idx.failed',
                    $prefix . $queue . '.idx.pending',
                    $prefix . $queue . '.meta.sequence',
                    $prefix,
                    $now
                ],
                3
            );

            Log::info("Retry: retried {$retried_count} failed jobs for queue '{$queue}'");
            return true;
        } catch (\Throwable $exception) {
            Log::error("Redis retry error: " . $exception->getMessage());
            return false;
        }
    }

    public function retry_by_uuid(string $uuid): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas['retry_by_uuid'])) {
                Log::error("Lua script 'retry_by_uuid' not loaded");
                if (!$this->reload_lua_script('retry_by_uuid')) {
                    return false;
                }
            }

            $now = time();

            $result = $redis->evalSha(
                $this->script_shas['retry_by_uuid'],
                [
                    $prefix . 'registry.' . $uuid,
                    $uuid,
                    $prefix,
                    $now
                ],
                1
            );

            if ($result) {
                Log::info("Retry: retried failed job with UUID '{$uuid}'");
                return true;
            } else {
                Log::warning("Retry: failed job with UUID '{$uuid}' not found or not in failed state");
                return false;
            }
        } catch (\Throwable $exception) {
            Log::error("Redis retry_by_uuid error: " . $exception->getMessage());
            return false;
        }
    }

    public function delete_job(string $uuid): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas['delete_job'])) {
                Log::error("Lua script 'delete_job' not loaded");
                if (!$this->reload_lua_script('delete_job')) {
                    return false;
                }
            }

            $result = $redis->evalSha(
                $this->script_shas['delete_job'],
                [
                    $prefix . 'registry.' . $uuid,
                    $prefix . 'telemetry.jobs',
                    $prefix . 'meta.pid_map',
                    $uuid,
                    $prefix
                ],
                3
            );

            if ($result) {
                Log::info("Deleted job with UUID '{$uuid}'");
            }
            return (bool)$result;
        } catch (\Throwable $exception) {
            Log::error("Redis delete_job error: " . $exception->getMessage());
            return false;
        }
    }
}
