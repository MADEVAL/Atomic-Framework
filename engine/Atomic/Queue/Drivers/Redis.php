<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Drivers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Interfaces\Base;
use Engine\Atomic\Queue\Interfaces\Management;
use Engine\Atomic\Queue\Interfaces\Telemetry;
use Engine\Atomic\Queue\Managers\ProcessManager;
use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Queue\Monitor\Adapters\Redis as RedisMonitorAdapter;
use Engine\Atomic\Telemetry\Queue\Adapters\Redis as RedisTelemetryAdapter;

class Redis implements Base, Management, Telemetry
{
    use RedisMonitorAdapter;
    use RedisTelemetryAdapter;

    private const LUA_PUSH = 'push';
    private const LUA_PUSH_TELEMETRY = 'push_telemetry';
    private const LUA_LOAD_BATCH = 'load_batch';
    private const LUA_LOAD_EVENTS = 'load_events';
    private const LUA_LOAD_JOBS_BY_STATE = 'load_jobs_by_state';
    private const LUA_LOAD_ACTIVE_MONITOR = 'load_active_monitor';
    private const LUA_LOAD_STUCK = 'load_stuck';
    private const LUA_RELEASE = 'release';
    private const LUA_MARK_FINISHED = 'mark_finished';
    private const LUA_CANCEL = 'cancel';
    private const LUA_MARK_CANCEL_REQUESTED = 'mark_cancel_requested';
    private const LUA_MARK_CANCELLED = 'mark_cancelled';
    private const LUA_SET_PID = 'set_pid';
    private const LUA_RETRY_ALL = 'retry_all';
    private const LUA_RETRY_BY_UUID = 'retry_by_uuid';
    private const LUA_DELETE_JOB = 'delete_job';

    private ProcessManager $process_manager;
    private ConnectionManager $connection_manager;
    private array $script_shas = [];
    private ?string $lua_dir = null;

    public function __construct() {
        $this->process_manager = new ProcessManager(LogChannel::QUEUE_WORKER);
        $this->connection_manager = ConnectionManager::instance();
        if (!$this->load_lua_scripts()) {
            throw new \Exception("Failed to load Lua scripts into Redis");
        }
    }

    public function init_state(): void {
        $this->connection_manager = ConnectionManager::instance();
        $this->load_lua_scripts();
    }

    public function reset_state(): void {}


    private function get_prefix(): string {
        return App::instance()->get('REDIS.prefix');
    }

    private function load_lua_scripts(): bool 
    {
        $lua_dir = realpath(__DIR__ . '/lua');
        if ($lua_dir === false || !is_dir($lua_dir)) {
            Log::channel(LogChannel::QUEUE_WORKER)->warning('Lua scripts directory not found: ' . __DIR__ . '/lua');
            return false;
        }

        $this->lua_dir = $lua_dir;
        
        $scripts = glob($lua_dir . '/*.lua');
        if (empty($scripts)) {
            Log::channel(LogChannel::QUEUE_WORKER)->warning("No Lua scripts found in directory: $lua_dir");
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
                Log::channel(LogChannel::QUEUE_WORKER)->warning("Error checking script existence: " . $e->getMessage());
            }
        }
        
        Log::channel(LogChannel::QUEUE_WORKER)->info("Script '$script_name' not found in Redis memory, reloading");
        return $this->load_single_lua_script($script_name);
    }

    private function lua_sha(string $script_name): ?string {
        if (!isset($this->script_shas[$script_name]) && !$this->reload_lua_script($script_name)) {
            return null;
        }

        return $this->script_shas[$script_name] ?? null;
    }

    private function eval_lua(string $script_name, array $args, int $num_keys): mixed {
        $redis = $this->connection_manager->get_redis(true);
        $sha = $this->lua_sha($script_name);
        if ($sha === null) {
            return false;
        }

        try {
            $result = $this->eval_lua_sha($redis, $sha, $args, $num_keys);
            if ($result === false && !$this->redis_script_exists($redis, $sha) && $this->load_single_lua_script($script_name)) {
                return $this->eval_lua_sha($redis, $this->script_shas[$script_name], $args, $num_keys);
            }
            return $result;
        } catch (\Throwable $e) {
            if (!$this->is_no_script_error($e) || !$this->load_single_lua_script($script_name)) {
                throw $e;
            }

            return $this->eval_lua_sha($redis, $this->script_shas[$script_name], $args, $num_keys);
        }
    }

    private function eval_lua_sha(\Redis $redis, string $sha, array $args, int $num_keys): mixed {
        $this->clear_redis_error($redis);
        $result = $redis->evalSha($sha, $args, $num_keys);
        if ($result !== false) {
            return $result;
        }

        $error = $redis->getLastError();
        if (is_string($error) && $error !== '') {
            throw new \RuntimeException($error);
        }

        return $result;
    }

    private function clear_redis_error(\Redis $redis): void {
        if (method_exists($redis, 'clearLastError')) {
            $redis->clearLastError();
        }
    }

    private function redis_script_exists(\Redis $redis, string $sha): bool {
        try {
            $exists = $redis->script('EXISTS', $sha);
            return \is_array($exists) && isset($exists[0]) && (int)$exists[0] === 1;
        } catch (\Throwable) {
            return true;
        }
    }

    private function is_no_script_error(\Throwable $e): bool {
        $message = $e->getMessage();
        return \stripos($message, 'NOSCRIPT') !== false
            || \stripos($message, 'No matching script') !== false;
    }
    
    private function load_single_lua_script(string $script_name): bool {
        $redis = $this->connection_manager->get_redis(true);

        $lua_dir = $this->lua_dir ?? realpath(__DIR__ . '/lua');
        if ($lua_dir === false || $lua_dir === null) {
            Log::channel(LogChannel::QUEUE_WORKER)->error('Cannot resolve Lua scripts directory: ' . __DIR__ . '/lua');
            return false;
        }

        $script_path = $lua_dir . '/' . $script_name . '.lua';
        
        if (!file_exists($script_path)) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Lua script file not found: $script_path");
            return false;
        }
        
        $script_content = Filesystem::instance()->read($script_path);
        if ($script_content === false) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Failed to read Lua script: $script_path");
            return false;
        }
        
        try {
            $sha = $redis->script('LOAD', $script_content);
            $this->script_shas[$script_name] = $sha;
            return true;
        } catch (\Exception $e) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Failed to load Lua script '$script_name': " . $e->getMessage());
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

        $payload_data = [
            'handler'    => $handler,
            'data'       => $payload['data'] ?? [],
            'uuid_batch' => $options['uuid_batch'],
        ];
        if (isset($options['cancel_handler'])) {
            $payload_data['cancel_handler'] = $options['cancel_handler'];
        }
        $payload_json = $this->serialize($payload_data);

        try {
            return (bool)$this->eval_lua(
                self::LUA_PUSH,
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
            Log::channel(LogChannel::QUEUE_WORKER)->error("Error adding job to queue: " . $e->getMessage() . " at line " . $e->getLine());
            return false;
        }
    }

    public function pop_batch(string $queue, int $limit): array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();
        $now = \time();
        $jobs = [];

        if (!isset($this->script_shas[self::LUA_LOAD_BATCH])) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Lua script '" . self::LUA_LOAD_BATCH . "' not loaded");
            if (!$this->reload_lua_script(self::LUA_LOAD_BATCH)) {
                return [];
            }
        }
        
        try {
            $result = $this->eval_lua(
                self::LUA_LOAD_BATCH,
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
            Log::channel(LogChannel::QUEUE_WORKER)->error("Error extracting jobs from queue: " . $e->getMessage());
        }
        return $jobs;
    }

    public function release(array $job, int $delay): bool 
    {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas[self::LUA_RELEASE])) {
                Log::channel(LogChannel::QUEUE_WORKER)->error("Lua script '" . self::LUA_RELEASE . "' not loaded");
                if (!$this->reload_lua_script(self::LUA_RELEASE)) {
                    return false;
                }
            }

            $queue = $job['queue'];
            $job_uuid = $job['uuid'];
            $pid = $job['pid'];
            $available_at = time() + $delay;

            $result = $this->eval_lua(
                self::LUA_RELEASE,
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
            Log::channel(LogChannel::QUEUE_WORKER)->error("Error trying to release job: " . $th->getMessage() . " at line " . $th->getLine());
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

            $result = $this->eval_lua(
                self::LUA_MARK_FINISHED,
                [
                    $prefix . 'registry.' . $job_uuid,
                    $prefix . $queue . '.idx.running',
                    $prefix . $queue . '.idx.cancel_requested',
                    $prefix . $queue . '.idx.' . ($failed ? 'failed' : 'completed'),
                    $prefix . 'meta.pid_map',
                    $job_uuid,
                    (int)$failed,
                    $timestamp,
                    $exception_json,
                    (int)$ttl
                ],
                5
            );

            return (bool)$result;
        } catch (\Exception $e) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Error marking job as finished: " . $e->getMessage());
            return false;
        }
    }

    public function mark_failed(array $job, \Throwable $exception): bool {
        Log::channel(LogChannel::QUEUE_WORKER)->error("Job with UUID " . ($job['uuid'] ?? 'N/A') . " failed: " . $exception->getMessage());
        $job['exception'] = Redactor::redact([
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace_string' => $exception->getTraceAsString()
        ]);

        return $this->mark_finished($job, true);
    }

    public function mark_completed(array $job): bool {
        return $this->mark_finished($job, false);
    }

    public function find_by_uuid(string $uuid): ?array {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();
        $data = $redis->hGetAll($prefix . 'registry.' . $uuid);
        if (!$data) {
            return null;
        }
        if (empty($data['state']) || !\is_string($data['state'])) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Malformed Redis queue registry entry for UUID {$uuid}: missing state.");
            return null;
        }
        if (isset($data['payload']) && \is_string($data['payload'])) {
            $data['payload'] = $this->deserialize($data['payload']);
        }
        return $data;
    }

    public function cancel(string $uuid): ?array {
        $prefix = $this->get_prefix();
        $existing_job = $this->find_by_uuid($uuid);
        if (!$existing_job) {
            return null;
        }
        $queue = (string)($existing_job['queue'] ?? 'default');

        try {
            $result = $this->eval_lua(
                self::LUA_CANCEL,
                [
                    $prefix . 'registry.' . $uuid,
                    $prefix . 'meta.pid_map',
                    $prefix . $queue . '.idx.pending',
                    $prefix . $queue . '.idx.running',
                    $prefix . $queue . '.idx.cancel_requested',
                    $prefix . $queue . '.idx.cancelled',
                    $uuid,
                    $prefix,
                    \time(),
                    State::PENDING->value,
                    State::RUNNING->value,
                    State::CANCEL_REQUESTED->value,
                    State::COMPLETED->value,
                    State::FAILED->value,
                    State::CANCELLED->value,
                ],
                6
            );

            $action = \is_array($result) ? (string)($result[0] ?? '') : '';
            if ($action === '') {
                return null;
            }

            $job = $this->find_by_uuid($uuid);
            if (!$job) {
                return null;
            }

            return ['action' => $action, 'job' => $job];
        } catch (\Throwable $e) {
            Log::channel(LogChannel::QUEUE_WORKER)->error('Redis cancel error: ' . $e->getMessage());
            return null;
        }
    }

    public function mark_cancel_requested(string $uuid): bool {
        $prefix = $this->get_prefix();
        $job = $this->find_by_uuid($uuid);
        if (!$job) {
            return false;
        }
        $queue = (string)($job['queue'] ?? 'default');

        try {
            $result = $this->eval_lua(
                self::LUA_MARK_CANCEL_REQUESTED,
                [
                    $prefix . 'registry.' . $uuid,
                    $prefix . $queue . '.idx.running',
                    $prefix . $queue . '.idx.cancel_requested',
                    $uuid,
                    \time(),
                    State::RUNNING->value,
                    State::CANCEL_REQUESTED->value,
                ],
                3
            );

            return \is_array($result) ? (bool)($result[0] ?? false) : (bool)$result;
        } catch (\Throwable $e) {
            Log::channel(LogChannel::QUEUE_WORKER)->error('Redis mark_cancel_requested error: ' . $e->getMessage());
            return false;
        }
    }

    public function is_cancel_requested(string $uuid): bool {
        $job = $this->find_by_uuid($uuid);
        return ($job['state'] ?? null) === State::CANCEL_REQUESTED->value;
    }

    public function mark_cancelled(array $job, ?string $reason = null): bool {
        $prefix = $this->get_prefix();
        $uuid = $job['uuid'];
        $queue = $job['queue'];

        try {
            $atomic = App::instance();
            $driver_name = $atomic->get('QUEUE_DRIVER');
            $ttl = $atomic->get("QUEUE.{$driver_name}.queues.{$queue}.ttl", 0);

            $result = $this->eval_lua(
                self::LUA_MARK_CANCELLED,
                [
                    $prefix . 'registry.' . $uuid,
                    $prefix . $queue . '.idx.' . State::PENDING->value,
                    $prefix . $queue . '.idx.' . State::RUNNING->value,
                    $prefix . $queue . '.idx.' . State::FAILED->value,
                    $prefix . $queue . '.idx.' . State::COMPLETED->value,
                    $prefix . $queue . '.idx.' . State::CANCELLED->value,
                    $prefix . $queue . '.idx.' . State::CANCEL_REQUESTED->value,
                    $prefix . 'meta.pid_map',
                    $uuid,
                    \time(),
                    $reason ?? '',
                    (int)$ttl,
                    State::COMPLETED->value,
                    State::FAILED->value,
                    State::CANCELLED->value,
                ],
                8
            );

            return (bool)$result;
        } catch (\Throwable $e) {
            Log::channel(LogChannel::QUEUE_WORKER)->error('Redis mark_cancelled error: ' . $e->getMessage());
            return false;
        }
    }

    public function set_pid(array $job): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            $job_uuid = $job['uuid'];

            if ($job_uuid === null) {
                Log::channel(LogChannel::QUEUE_WORKER)->error("Error while trying to set PID: Job UUID not set");
                return false;
            }

            if (!isset($this->script_shas[self::LUA_SET_PID])) {
                Log::channel(LogChannel::QUEUE_WORKER)->error("Lua script '" . self::LUA_SET_PID . "' not loaded");
                if (!$this->reload_lua_script(self::LUA_SET_PID)) {
                    return false;
                }
            }

            $pid = \getmypid();
            $process_start_ticks = $this->process_manager->get_process_start_ticks($pid);

            $result = $this->eval_lua(
                self::LUA_SET_PID,
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
            Log::channel(LogChannel::QUEUE_WORKER)->error("Error trying to set PID for job: " . $th->getMessage() . " at line " . $th->getLine());
            return false;
        }
    }

    public function retry(string $queue = 'default'): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas[self::LUA_RETRY_ALL])) {
                Log::channel(LogChannel::QUEUE_WORKER)->error("Lua script '" . self::LUA_RETRY_ALL . "' not loaded");
                if (!$this->reload_lua_script(self::LUA_RETRY_ALL)) {
                    return false;
                }
            }

            $now = time();

            $retried_count = $this->eval_lua(
                self::LUA_RETRY_ALL,
                [
                    $prefix . $queue . '.idx.failed',
                    $prefix . $queue . '.idx.pending',
                    $prefix . $queue . '.meta.sequence',
                    $prefix,
                    $now
                ],
                3
            );

            Log::channel(LogChannel::QUEUE_WORKER)->info("Retry: retried {$retried_count} failed jobs for queue '{$queue}'");
            return true;
        } catch (\Throwable $exception) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Redis retry error: " . $exception->getMessage());
            return false;
        }
    }

    public function retry_by_uuid(string $uuid): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas[self::LUA_RETRY_BY_UUID])) {
                Log::channel(LogChannel::QUEUE_WORKER)->error("Lua script '" . self::LUA_RETRY_BY_UUID . "' not loaded");
                if (!$this->reload_lua_script(self::LUA_RETRY_BY_UUID)) {
                    return false;
                }
            }

            $now = time();

            $result = $this->eval_lua(
                self::LUA_RETRY_BY_UUID,
                [
                    $prefix . 'registry.' . $uuid,
                    $uuid,
                    $prefix,
                    $now
                ],
                1
            );

            if ($result) {
                Log::channel(LogChannel::QUEUE_WORKER)->info("Retry: retried failed job with UUID '{$uuid}'");
                return true;
            } else {
                Log::channel(LogChannel::QUEUE_WORKER)->warning("Retry: failed job with UUID '{$uuid}' not found or not in failed state");
                return false;
            }
        } catch (\Throwable $exception) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Redis retry_by_uuid error: " . $exception->getMessage());
            return false;
        }
    }

    public function delete_job(string $uuid): bool {
        $redis = $this->connection_manager->get_redis(true);
        $prefix = $this->get_prefix();

        try {
            if (!isset($this->script_shas[self::LUA_DELETE_JOB])) {
                Log::channel(LogChannel::QUEUE_WORKER)->error("Lua script '" . self::LUA_DELETE_JOB . "' not loaded");
                if (!$this->reload_lua_script(self::LUA_DELETE_JOB)) {
                    return false;
                }
            }

            $result = $this->eval_lua(
                self::LUA_DELETE_JOB,
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
                Log::channel(LogChannel::QUEUE_WORKER)->info("Deleted job with UUID '{$uuid}'");
            }
            return (bool)$result;
        } catch (\Throwable $exception) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Redis delete_job error: " . $exception->getMessage());
            return false;
        }
    }
}
