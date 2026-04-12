<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Drivers;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Queue\Interfaces\Base;
use Engine\Atomic\Queue\Interfaces\Management;
use Engine\Atomic\Queue\Interfaces\Telemetry;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\ProcessManager;
use Engine\Atomic\Queue\Monitor\Adapters\DB as DBMonitorAdapter;
use Engine\Atomic\Telemetry\Queue\Adapters\DB as DBTelemetryAdapter;

class DB implements Base, Management, Telemetry
{
    use DBTelemetryAdapter;
    use DBMonitorAdapter;

    private const PID_PLACEHOLDER = -1;

    private ProcessManager $process_manager;
    private ?ConnectionManager $connection_manager;

    private ?Cortex $jobs_mapper = null;
    private ?Cortex $jobs_failed_mapper = null;
    private ?Cortex $jobs_completed_mapper = null;

    public function __construct() {
        $this->process_manager = new ProcessManager();
        $this->connection_manager = new ConnectionManager();
    }

    public function open_connection(): void {
        $this->connection_manager = new ConnectionManager();
        $this->jobs_mapper = null;
        $this->jobs_failed_mapper = null;
        $this->jobs_completed_mapper = null;
    }
    
    public function close_connection(): void {
        $this->connection_manager->close_sql();
        $this->jobs_mapper = null;
        $this->jobs_failed_mapper = null;
        $this->jobs_completed_mapper = null;
    }

    protected function serialize(array $jobData): string {
        return \json_encode($jobData, JSON_THROW_ON_ERROR);
    }

    protected function deserialize(string $jobData): array {
        return \json_decode($jobData, true, 512, JSON_THROW_ON_ERROR);
    }

    public function push(array $payload, array $options = []): bool
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }

        $queue        = $options['queue'];
        $uuid         = $options['uuid'];
        $max_attempts = $options['max_attempts'];
        $retry_delay  = $options['retry_delay'];
        $timeout      = $options['timeout'];
        $delay        = $options['delay'];
        $priority     = $options['priority'];
        $available_at = \time() + (int)$delay;
        $jobData = [
            'handler'      => $payload['handler'],
            'data'         => $payload['data'] ?? [],
            'uuid_batch'   => $options['uuid_batch'],
        ];

        try {
            $this->jobs_mapper->reset();
            $this->jobs_mapper->uuid = $uuid;
            $this->jobs_mapper->queue = $queue;
            $this->jobs_mapper->priority = $priority;
            $this->jobs_mapper->payload = $this->serialize($jobData);
            $this->jobs_mapper->max_attempts = $max_attempts;
            $this->jobs_mapper->attempts = 0;
            $this->jobs_mapper->timeout = $timeout;
            $this->jobs_mapper->retry_delay = $retry_delay;
            $this->jobs_mapper->created_at = time();
            $this->jobs_mapper->available_at = $available_at;
            $this->jobs_mapper->save();

            return true;
        } catch (\Throwable $th) {
            Log::error("Error adding task to queue: " . $th->getMessage());
            return false;
        }
    }

    public function pop_batch(string $queue, int $limit): array
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        $table = App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs';
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, $table);
        }

        $now = \time();
        $jobs = [];

        try {
            $sql->begin();

            $available_jobs = $sql->exec(
                'SELECT * FROM `' . $table . '` WHERE queue = ? AND available_at <= ? AND pid IS NULL ORDER BY priority ASC, available_at ASC LIMIT ? FOR UPDATE SKIP LOCKED',
                [$queue, $now, $limit]
            );

            if ($available_jobs) {
                foreach ($available_jobs as $row) {
                    $timeout = (int)$row['timeout'];
                    $available_at = $now + $timeout;

                    $claimed = (int)$sql->exec(
                        'UPDATE `' . $table . '` SET pid = ?, attempts = attempts + 1, available_at = ?, process_start_ticks = NULL WHERE id = ? AND pid IS NULL',
                        [self::PID_PLACEHOLDER, $available_at, $row['id']]
                    );
                    if ($claimed !== 1) {
                        continue;
                    }

                    $row['attempts'] = (int)$row['attempts'] + 1;
                    $row['available_at'] = $available_at;
                    $row['pid'] = self::PID_PLACEHOLDER;
                    $row['payload'] = $this->deserialize($row['payload']);
                    $jobs[] = $row;
                }
            }

            $sql->commit();
        } catch (\Exception $e) {
            $sql->rollback();
            Log::error("SQLQueueDriver popBatch error: " . $e->getMessage() . " - " . $e->getFile() . " - " . $e->getLine() . " | Queue: $queue | Limit: $limit");
            $jobs = [];
        }
        return $jobs;
    }

    public function release(array $job, int $delay): bool
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        $table = App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs';
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, $table);
        }

        try {
            $uuid = $job['uuid'] ?? null;
            if (!\is_string($uuid) || $uuid === '') {
                return false;
            }

            $available_at = \time() + $delay;
            $query = 'UPDATE `' . $table . '` SET available_at = ?, pid = NULL, process_start_ticks = NULL WHERE uuid = ?';
            $params = [$available_at, $uuid];

            if ($job['pid'] === null) {
                $query .= ' AND pid IS NULL';
            } else {
                $query .= ' AND pid = ?';
                $params[] = (int)$job['pid'];
            }

            $released = (int)$sql->exec($query, $params);
            if ($released === 1) {
                Log::debug("Releasing job with ID: " . $uuid . " - setting available_at to " . $available_at);
                return true;
            }

            Log::warning("Failed to release job {$uuid}: ownership mismatch or job is no longer active.");
            return false;
        } catch (\Exception $e) {
            Log::error("Error occurred while trying to release the job: " . $e->getMessage());
            return false;
        }
    }

    public function mark_failed(array $job, \Throwable $exception): bool
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        $jobs_table = App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs';
        if ($reconnected || !$this->jobs_failed_mapper) {
            $this->jobs_failed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed');
        }

        unset($job['payload']['uuid_batch']);

        $error_data = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace_string' => $exception->getTraceAsString()
        ];

        try {
            $sql->begin();

            if (!$this->delete_claimed_job($sql, $jobs_table, $job)) {
                $sql->rollback();
                Log::warning("Skipping mark_failed for job {$job['uuid']}: ownership mismatch or job already moved.");
                return false;
            }

            $this->jobs_failed_mapper->reset();
            $this->jobs_failed_mapper->uuid = $job['uuid'];
            $this->jobs_failed_mapper->queue = $job['queue'];
            $this->jobs_failed_mapper->priority = $job['priority'];
            $this->jobs_failed_mapper->payload = $this->serialize($job['payload']);
            $this->jobs_failed_mapper->max_attempts = $job['max_attempts'];
            $this->jobs_failed_mapper->attempts = $job['attempts'];
            $this->jobs_failed_mapper->timeout = $job['timeout'];
            $this->jobs_failed_mapper->retry_delay = $job['retry_delay'];
            $this->jobs_failed_mapper->exception = $this->serialize($error_data);
            $this->jobs_failed_mapper->created_at = \time();

            $this->jobs_failed_mapper->save();

            $sql->commit();
            return true;
        } catch (\Exception $e) {
            $sql->rollback();
            Log::error("Error when trying to mark job as failed: " . $e->getMessage());
            return false;
        }
    }

    public function mark_completed(array $job): bool {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        $jobs_table = App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs';
        if ($reconnected || !$this->jobs_completed_mapper) {
            $this->jobs_completed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_completed');
        }

        try {
            $sql->begin();
            if (!$this->delete_claimed_job($sql, $jobs_table, $job)) {
                $sql->rollback();
                Log::warning("Skipping mark_completed for job {$job['uuid']}: ownership mismatch or job already moved.");
                return false;
            }

            unset($job['payload']['uuid_batch']);

            $this->jobs_completed_mapper->reset();
            $this->jobs_completed_mapper->uuid = $job['uuid'];
            $this->jobs_completed_mapper->queue = $job['queue'];
            $this->jobs_completed_mapper->priority = $job['priority'];
            $this->jobs_completed_mapper->payload = $this->serialize($job['payload']);
            $this->jobs_completed_mapper->max_attempts = $job['max_attempts'];
            $this->jobs_completed_mapper->attempts = $job['attempts'];
            $this->jobs_completed_mapper->timeout = $job['timeout'];
            $this->jobs_completed_mapper->retry_delay = $job['retry_delay'];
            $this->jobs_completed_mapper->created_at = $job['created_at'];

            $this->jobs_completed_mapper->save();
            $sql->commit();
            return true;
        } catch (\Exception $e) {
            $sql->rollback();
            Log::error("Error when trying to mark job as completed: " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $uuid): bool {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }

        try {
            $this->jobs_mapper->load(['uuid = ?', $uuid]);
            if (!$this->jobs_mapper->dry()) {
                return (bool)$this->jobs_mapper->erase();
            }
            return false;
        } catch (\Exception $e) {
            Log::error("Error while trying to delete job: " . $e->getMessage());
            return false;
        }
    }

    public function set_pid(array $job): bool
    {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        $table = App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs';
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, $table);
        }

        try {
            $uuid = $job['uuid'] ?? null;
            if (!\is_string($uuid) || $uuid === '') {
                return false;
            }

            $pid = \getmypid();
            $process_start_ticks = $this->process_manager->get_process_start_ticks($pid);

            $updated = (int)$sql->exec(
                'UPDATE `' . $table . '` SET pid = ?, process_start_ticks = ? WHERE uuid = ? AND pid = ?',
                [$pid, $process_start_ticks, $uuid, self::PID_PLACEHOLDER]
            );

            if ($updated === 1) {
                return true;
            }

            Log::warning("Failed to set PID for job {$uuid}: job is no longer reserved by this worker.");
            return false;
        } catch (\Throwable $th) {
            Log::error("Error trying to set PID for job: " . $th->getMessage());
            return false;
        }
    }

    private function delete_claimed_job(\DB\SQL $sql, string $table, array $job): bool
    {
        $uuid = $job['uuid'] ?? null;
        if (!\is_string($uuid) || $uuid === '') {
            return false;
        }

        $query = 'DELETE FROM `' . $table . '` WHERE uuid = ?';
        $params = [$uuid];

        if (\array_key_exists('pid', $job)) {
            if ($job['pid'] === null) {
                $query .= ' AND pid IS NULL';
            } else {
                $query .= ' AND pid = ?';
                $params[] = (int)$job['pid'];
            }
        }

        $query .= ' LIMIT 1';

        $deleted = (int)$sql->exec($query, $params);
        return $deleted === 1;
    }

    public function retry(string $queue = '*'): bool {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_failed_mapper) {
            $this->jobs_failed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed');
        }

        try {
            $sql->begin();

            $conditions = $queue !== '*' ? ['queue = ?', $queue] : [];
            $failed_jobs = $this->jobs_failed_mapper->find($conditions);

            if (!$failed_jobs) {
                $sql->commit();
                return true;
            }

            $count = 0;
            foreach ($failed_jobs as $failed_job) {
                $payload = $this->deserialize($failed_job->payload);
                list($class, $method) = \explode('@', $payload['handler']);
                
                (new Manager($failed_job->queue))->push(
                    [$class, $method],
                    $payload['data'] ?? [],
                    [
                        'delay' => 0,
                        'priority' => $failed_job->priority,
                        'max_attempts' => $failed_job->max_attempts,
                        'retry_delay' => $failed_job->retry_delay,
                        'timeout' => $failed_job->timeout,
                        'attempts' => 0,
                    ],
                    $failed_job->uuid
                );
                $failed_job->erase();
                $count++;
            }

            $sql->commit();
            Log::info("Retry: retried {$count} failed jobs for queue '{$queue}'");
            return true;
        } catch (\Throwable $exception) {
            $sql->rollback();
            Log::error("DatabaseQueueDriver retry error: " . $exception->getMessage());
            return false;
        }
    }

    public function retry_by_uuid(string $uuid): bool {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_failed_mapper) {
            $this->jobs_failed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed');
        }

        try {
            $sql->begin();

            $this->jobs_failed_mapper->load(['uuid = ?', $uuid]);
            
            if ($this->jobs_failed_mapper->dry()) {
                $sql->rollback();
                Log::warning("Retry: failed job with UUID '{$uuid}' not found");
                return false;
            }

            $failed_job = $this->jobs_failed_mapper;
            $payload = $this->deserialize($failed_job->payload);
            list($class, $method) = \explode('@', $payload['handler']);
            
            (new Manager($failed_job->queue))->push(
                [$class, $method],
                $payload['data'] ?? [],
                [
                    'delay' => 0,
                    'priority' => $failed_job->priority,
                    'max_attempts' => $failed_job->max_attempts,
                    'retry_delay' => $failed_job->retry_delay,
                    'timeout' => $failed_job->timeout,
                    'attempts' => 0,
                ],
                $failed_job->uuid
            );

            $failed_job->erase();
            $sql->commit();
            
            Log::info("Retry: retried failed job with UUID '{$uuid}'");
            return true;
        } catch (\Throwable $exception) {
            $sql->rollback();
            Log::error("DatabaseQueueDriver retry_by_uuid error: " . $exception->getMessage());
            return false;
        }
    }

    public function delete_job(string $uuid): bool {
        list($sql, $reconnected) = $this->connection_manager->get_db(true, true);
        if ($reconnected || !$this->jobs_mapper) {
            $this->jobs_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
        }
        if ($reconnected || !$this->jobs_failed_mapper) {
            $this->jobs_failed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed');
        }
        if ($reconnected || !$this->jobs_completed_mapper) {
            $this->jobs_completed_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_completed');
        }

        if ($reconnected || !$this->queue_telemetry_mapper) {
            $this->queue_telemetry_mapper = new Cortex($sql, App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry');
        }

        try {
            $deleted = false;

            $this->jobs_mapper->load(['uuid = ?', $uuid]);
            if (!$this->jobs_mapper->dry()) {
                if (!empty($this->jobs_mapper->pid)) {
                    return false;
                }
                $this->jobs_mapper->erase();
                $deleted = true;
            }

            $this->jobs_failed_mapper->load(['uuid = ?', $uuid]);
            if (!$this->jobs_failed_mapper->dry()) {
                $this->jobs_failed_mapper->erase();
                $deleted = true;
            }

            $this->jobs_completed_mapper->load(['uuid = ?', $uuid]);
            if (!$this->jobs_completed_mapper->dry()) {
                $this->jobs_completed_mapper->erase();
                $deleted = true;
            }

            $telemetry_deleted = $this->queue_telemetry_mapper->erase(['uuid_job = ?', $uuid]);
            if ($telemetry_deleted) {
                $deleted = true;
            }

            if ($deleted) {
                Log::info("Deleted job with UUID '{$uuid}'");
            }
            return $deleted;
        } catch (\Exception $e) {
            Log::error("Error while trying to delete job: " . $e->getMessage());
            return false;
        }
    }
}
