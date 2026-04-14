<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Monitor;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\ProcessManager;

if (!defined( 'ATOMIC_START' ) ) exit;


class Monitor
{
    private static bool $shutdown = false;

    private const PID_PLACEHOLDER = -1;

    private const DEFAULT_INTERVAL = 5;
    private const MAX_KILL_ATTEMPTS = 3;
    private const DOUBLE_CHECK_DELAY_US = 200000;

    private const EPERM = 1;
    private const ESRCH = 3;

    private array $unkillable_pids = [];
    private array $kill_attempts = [];

    private ProcessManager $process_manager;
    private Manager $queue_manager;

    public function __construct(string $queue = 'default') {
        $this->queue_manager = new Manager($queue);
        $this->process_manager = new ProcessManager(LogChannel::QUEUE_MONITOR);

        if (!$this->process_manager->can_check_processes()) {
            Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] QueueMonitor cannot read process information. Signals will not be sent.");
        }
    }

    public function handle_signal($signal): void 
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                Log::channel(LogChannel::QUEUE_MONITOR)->info("[QueueMonitor] Shutdown signal received. Stopping " . __CLASS__ . ".");
                self::$shutdown = true;
                break;
            default:
                Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] " . __CLASS__ . " received unknown signal: $signal");
                break;
        }
    }

    private function check_process_exists(int $pid): array
    {
        $result = \posix_kill($pid, 0);
        $error = \posix_get_last_error();
        
        if ($result === true) {
            return ['exists' => true, 'error' => null, 'is_permission_error' => false];
        }
        
        if ($error === self::EPERM) {
            Log::channel(LogChannel::QUEUE_MONITOR)->critical("[QueueMonitor] CRITICAL: Insufficient permissions to signal PID $pid. The monitor lacks privileges to manage this process.");
            return ['exists' => true, 'error' => $error, 'is_permission_error' => true];
        }
        
        if ($error === self::ESRCH) {
            return ['exists' => false, 'error' => $error, 'is_permission_error' => false];
        }
        
        Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Unexpected posix_kill error for PID $pid: error code $error");
        return ['exists' => false, 'error' => $error, 'is_permission_error' => false];
    }

    private function double_check_before_handle(array $job): bool
    {
        $pid = isset($job['pid']) ? (int)$job['pid'] : 0;
        $uuid = $job['uuid'] ?? '';
        
        if ($pid <= 0 || empty($uuid)) {
            return true;
        }
        
        \usleep(self::DOUBLE_CHECK_DELAY_US);
        
        if (!$this->queue_manager->exists_in_jobs_table($uuid, $pid)) {
            Log::channel(LogChannel::QUEUE_MONITOR)->debug("[QueueMonitor] Job with UUID {$uuid} no longer exists with PID {$pid} after double-check. Skipping - likely already completed.");
            return false;
        }
        
        return true;
    }

    public function run(): void
    {
        \pcntl_async_signals(true);
        \pcntl_signal(SIGTERM, [$this, 'handle_signal']);
        \pcntl_signal(SIGINT,  [$this, 'handle_signal']);

        try {
            while (self::$shutdown === false) {
                try {
                    $this->check_stuck_jobs();
                    $this->check_in_progress_jobs();
                } catch (\Throwable $e) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->error("Error while checking queue: " . $e->getMessage());
                }
                for ($i = 0; $i < self::DEFAULT_INTERVAL; $i++) {
                    if(self::$shutdown) break;
                    \sleep(1);
                }
                try {
                    $this->retry_unkillable_processes();
                } catch (\Throwable $e) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->error("Error while retrying unkillable processes: " . $e->getMessage());
                }
            }
        } finally {
            Log::channel(LogChannel::QUEUE_MONITOR)->info("[QueueMonitor] Shutting down " . __CLASS__ . ".");
            $this->queue_manager->close_connection();
        }
    }

    public function check_stuck_jobs(): void 
    {
        $exclude_uuids = array_map(fn($job) => $job['uuid'], $this->unkillable_pids);
        $stuck_jobs = $this->queue_manager->load_stuck_jobs($exclude_uuids, '*');
        
        if (empty($stuck_jobs)) {
            return;
        }

        foreach ($stuck_jobs as $job) {
            $pid = isset($job['pid']) ? (int) $job['pid'] : 0;
            $uuid = $job['uuid'];
            $queue = $job['queue'];

            if ($pid === self::PID_PLACEHOLDER) {
                Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Orphaned job UUID: {$job['uuid']}, Queue: {$job['queue']} - process died before setting PID, handling as incomplete");
                $this->queue_manager->handle_incomplete_job($job);
                continue;
            }
            
            if ($pid <= 0) {
                Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Stuck job with invalid PID {$pid} (Job UUID: {$uuid}, Queue: {$queue}) - handling as incomplete (PID was never set).");
                if ($this->double_check_before_handle($job)) {
                    $this->queue_manager->handle_incomplete_job($job);
                }
                continue;
            }
            
            $process_check = $this->check_process_exists($pid);
            
            if ($process_check['exists']) {
                if ($process_check['is_permission_error']) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Skipping stuck job with PID {$pid} (Job UUID: {$uuid}, Queue: {$queue}) - insufficient permissions to signal");
                    continue;
                }
                
                if (!$this->process_manager->can_check_processes()) {
                    continue;
                }

                if (!$this->process_manager->is_our_process($pid, $job)) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Stuck job with PID {$pid} (Job UUID: {$uuid}, Queue: {$queue}) does not belong to our monitor");
                    if ($this->double_check_before_handle($job)) {
                        $this->queue_manager->handle_incomplete_job($job);
                    }
                    continue;
                }

                Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Stuck job with PID {$pid} (Job UUID: {$uuid}, Queue: {$queue}) is running, attempting to terminate");
                if (!\posix_kill($pid, SIGTERM)) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->error("[QueueMonitor] Failed to terminate stuck job with PID {$pid} (Job UUID: {$uuid}, Queue: {$queue})" . " - posix_kill error: " . \posix_get_last_error());
                }
                \sleep(1);
                
                $recheck = $this->check_process_exists($pid);
                if ($recheck['exists']) {
                    $this->unkillable_pids[$uuid] = $job;
                    $this->kill_attempts[$uuid] = 0;
                    continue;
                } else {
                    Log::channel(LogChannel::QUEUE_MONITOR)->info("[QueueMonitor] Stuck job with PID {$pid} (Job UUID: {$uuid}, Queue: {$queue}) successfully terminated");
                    if ($this->double_check_before_handle($job)) {
                        $this->queue_manager->handle_incomplete_job($job);
                    }
                }
            } else {
                Log::channel(LogChannel::QUEUE_MONITOR)->info("[QueueMonitor] Stuck job with PID {$pid} (Job UUID: {$uuid}, Queue: {$queue}) is inactive");
                if ($this->double_check_before_handle($job)) {
                    $this->queue_manager->handle_incomplete_job($job);
                }
            }
        }
    }

    private function retry_unkillable_processes(): void
    {
        foreach ($this->unkillable_pids as $uuid => $job) {
            $pid = (int) $job['pid'];
            
            $process_check = $this->check_process_exists($pid);
            
            if (!$process_check['exists']) {
                Log::channel(LogChannel::QUEUE_MONITOR)->info("[QueueMonitor] Previously unkillable process $pid is now absent");
                unset($this->unkillable_pids[$uuid], $this->kill_attempts[$uuid]);
                if ($this->double_check_before_handle($job)) {
                    $this->queue_manager->handle_incomplete_job($job);
                }
                continue;
            }

            if ($process_check['is_permission_error']) {
                Log::channel(LogChannel::QUEUE_MONITOR)->critical("[QueueMonitor] Cannot terminate unkillable process $pid due to permission issues. Marking job as failed.");
                $exception = new \Exception("Cannot terminate process with PID $pid due to insufficient permissions. The monitor lacks privileges to signal this process.");
                $this->queue_manager->mark_failed($job, $exception);
                unset($this->unkillable_pids[$uuid], $this->kill_attempts[$uuid]);
                continue;
            }

            if (!$this->process_manager->is_our_process($pid, $job)) {
                Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Process $pid no longer belongs to our monitor, removing from unkillable list");
                unset($this->unkillable_pids[$uuid], $this->kill_attempts[$uuid]);
                if ($this->double_check_before_handle($job)) {
                    $this->queue_manager->handle_incomplete_job($job);
                }
                continue;
            }

            $this->kill_attempts[$uuid]++;
            $attempts = $this->kill_attempts[$uuid];

            Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Attempt #{$attempts} to terminate stuck process with PID $pid");
            \posix_kill($pid, SIGKILL);
            \usleep(500000);

            $recheck = $this->check_process_exists($pid);
            if (!$recheck['exists']) {
                Log::channel(LogChannel::QUEUE_MONITOR)->info("[QueueMonitor] Successfully terminated PID $pid after {$attempts} attempts");
                unset($this->unkillable_pids[$uuid], $this->kill_attempts[$uuid]);
                if ($this->double_check_before_handle($job)) {
                    $this->queue_manager->handle_incomplete_job($job);
                }
                continue;
            }

            if ($this->kill_attempts[$uuid] >= self::MAX_KILL_ATTEMPTS) {
                Log::channel(LogChannel::QUEUE_MONITOR)->error("[QueueMonitor] Failed to terminate process $pid after " . self::MAX_KILL_ATTEMPTS . " attempts. Giving up and marking job as failed.");
                $exception = new \Exception("Failed to terminate process with PID $pid after " . self::MAX_KILL_ATTEMPTS . " kill attempts. The process appears to be unkillable and could not be stopped even with SIGKILL.");
                $this->queue_manager->mark_failed($job, $exception);
                unset($this->unkillable_pids[$uuid], $this->kill_attempts[$uuid]);
            }
        }
    }

    public function check_in_progress_jobs(): void 
    {
        $in_progress = $this->queue_manager->load_jobs_in_progress('*');
        foreach ($in_progress as $job) {
            $pid = isset($job['pid']) ? (int) $job['pid'] : 0;

            if ($pid === self::PID_PLACEHOLDER) {
                Log::channel(LogChannel::QUEUE_MONITOR)->warning("[QueueMonitor] Orphaned job UUID: {$job['uuid']}, Queue: {$job['queue']} - process died before setting PID, handling as incomplete");
                $this->queue_manager->handle_incomplete_job($job);
                continue;
            }

            if ($pid <= 0) {
                continue;
            }
            
            $process_check = $this->check_process_exists($pid);
            
            if ($process_check['exists']) {
                if ($process_check['is_permission_error']) {
                    Log::channel(LogChannel::QUEUE_MONITOR)->debug("[QueueMonitor] Job with PID {$pid} (Job UUID: {$job['uuid']}) - cannot verify process status due to permissions");
                }
                continue;
            }
            
            Log::channel(LogChannel::QUEUE_MONITOR)->info("[QueueMonitor] Job with PID {$pid} (Job UUID: {$job['uuid']}, Queue: {$job['queue']}) is inactive, handling incomplete job");
            if ($this->double_check_before_handle($job)) {
                $this->queue_manager->handle_incomplete_job($job);
            }
        }
    }
}