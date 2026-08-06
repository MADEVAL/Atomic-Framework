<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Worker;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Exceptions\JobCancelledException;
use Engine\Atomic\Queue\Managers\Manager;

class Worker
{
    private Manager $queue_manager;
    private int $worker_count;

    /** @var array<int, int>  pid -> worker_id (master only) */
    private array $worker_pids = [];

    /** @var int[] worker IDs that need respawning (queued by SIGCHLD, processed in main loop) */
    private array $pending_respawns = [];

    private bool $shutdown = false;
    private bool $job_cancelled_by_signal = false;

    private const IDLE_SLEEP_MICROSECONDS_DEFAULT = 200000;
    private const DEFAULT_MEMORY_LIMIT_MB = 128;
    private const MIN_DRAIN_TIMEOUT_SECONDS = 5;
    private const ERROR_BACKOFF_MAX_SECONDS = 30;
    private const SHUTDOWN_STAGGER_MICROSECONDS = 100000;

    public function __construct(Manager $queue_manager)
    {
        $atomic = App::instance();
        $this->queue_manager = $queue_manager;
        $worker_count = $atomic->get(
            'QUEUE.' . $atomic->get('QUEUE_DRIVER') . '.queues.' . $queue_manager->get_queue() . '.worker_cnt'
        );
        if (!\is_int($worker_count) || $worker_count <= 0) {
            throw new \UnexpectedValueException(
                'Queue worker_cnt must be configured as a positive integer for queue: ' . $queue_manager->get_queue()
            );
        }

        $this->worker_count = $worker_count;
    }

    public function handle_signal(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                Log::channel(LogChannel::QUEUE_WORKER)->info("Shutdown signal received in master. Will stagger SIGTERM to " . \count($this->worker_pids) . " worker(s).");
                $this->shutdown = true;
                break;
            default:
                Log::channel(LogChannel::QUEUE_WORKER)->warning(__CLASS__ . " master received unknown signal: $signal");
                break;
        }
    }

    public function handle_sigchld(int $signal): void
    {
        while (($pid = $this->wait_pid(-1, $status, WNOHANG)) > 0) {
            $worker_id = $this->worker_pids[$pid] ?? 0;
            unset($this->worker_pids[$pid]);

            if ($this->shutdown) {
                Log::channel(LogChannel::QUEUE_WORKER)->info("Worker #$worker_id (PID $pid) exited during shutdown.");
                continue;
            }

            if ($this->was_signaled($status)) {
                $sig = $this->term_signal($status);
                Log::channel(LogChannel::QUEUE_WORKER)->warning("Worker #$worker_id (PID $pid) killed by signal $sig. Queuing respawn.");
            } else {
                $exit_code = $this->exit_status($status);
                Log::channel(LogChannel::QUEUE_WORKER)->warning("Worker #$worker_id (PID $pid) exited with code $exit_code. Queuing respawn.");
            }

            $this->pending_respawns[] = $worker_id;
        }
    }


    public function run(): void
    {
        \pcntl_async_signals(true);
        \pcntl_signal(SIGTERM, [$this, 'handle_signal']);
        \pcntl_signal(SIGINT,  [$this, 'handle_signal']);
        \pcntl_signal(SIGCHLD, [$this, 'handle_sigchld']);

        Log::channel(LogChannel::QUEUE_WORKER)->info("Starting persistent worker pool ({$this->worker_count} workers) for queue: " . $this->queue_manager->get_queue());

        $this->queue_manager->close_all_connections();

        for ($i = 1; $i <= $this->worker_count; $i++) {
            $this->spawn_worker($i);
        }

        while (!$this->shutdown) {
            if (!empty($this->pending_respawns)) {
                $to_respawn = $this->pending_respawns;
                $this->pending_respawns = [];
                foreach ($to_respawn as $worker_id) {
                    $this->spawn_worker($worker_id);
                }
            }
            $this->pause_microseconds(500000);
        }

        $this->drain_workers();
        ConnectionManager::instance()->close();
        Log::channel(LogChannel::QUEUE_WORKER)->info("Worker pool shut down completely.");
    }

    protected function spawn_worker(int $worker_id): void
    {
        if ($this->shutdown) {
            return;
        }

        $pid = $this->fork_process();

        if ($pid === -1) {
            Log::channel(LogChannel::QUEUE_WORKER)->error("Failed to fork worker #$worker_id.");
            return;
        }

        if ($pid === 0) {
            $this->set_process_group(0, 0);
            $this->worker_loop($worker_id);
            exit(0);
        }

        $this->worker_pids[$pid] = $worker_id;
        $this->set_process_group($pid, $pid);
        Log::channel(LogChannel::QUEUE_WORKER)->info("Spawned worker #$worker_id (PID $pid).");
    }

    protected function drain_workers(): void
    {
        // Disable SIGCHLD handler to avoid races - we reap manually below
        $this->register_signal(SIGCHLD, SIG_DFL);

        $timeout = (int) App::instance()->get(
            'QUEUE.' . App::instance()->get('QUEUE_DRIVER') . '.queues.' . $this->queue_manager->get_queue() . '.timeout'
        );
        $timeout = \max($timeout, self::MIN_DRAIN_TIMEOUT_SECONDS);

        $pids_snapshot = $this->worker_pids;

        foreach ($pids_snapshot as $pid => $worker_id) {
            if (!isset($this->worker_pids[$pid])) {
                continue;
            }

            Log::channel(LogChannel::QUEUE_WORKER)->info("Sending SIGTERM to worker #$worker_id (PID $pid).");
            $this->signal_process($pid, SIGTERM);

            $start = $this->current_time();
            while (($this->current_time() - $start) < $timeout) {
                $result = $this->wait_pid($pid, $status, WNOHANG);
                if ($result === $pid || $result === -1) {
                    unset($this->worker_pids[$pid]);
                    Log::channel(LogChannel::QUEUE_WORKER)->info("Worker #$worker_id (PID $pid) exited during drain.");
                    break;
                }
                $this->pause_microseconds(self::SHUTDOWN_STAGGER_MICROSECONDS);
            }

            if (isset($this->worker_pids[$pid])) {
                Log::channel(LogChannel::QUEUE_WORKER)->warning("Worker #$worker_id (PID $pid) did not exit within {$timeout}s. Sending SIGKILL.");
                $this->signal_process($pid, SIGKILL);
                $this->wait_pid($pid, $status);
                unset($this->worker_pids[$pid]);
            }
        }
    }

    private function get_memory_limit_bytes(): int
    {
        $atomic = App::instance();
        $driver = $atomic->get('QUEUE_DRIVER');
        $queue = $this->queue_manager->get_queue();
        $limit_mb = $atomic->get("QUEUE.{$driver}.queues.{$queue}.memory_limit_mb");

        if (!\is_numeric($limit_mb) || (int)$limit_mb <= 0) {
            $limit_mb = self::DEFAULT_MEMORY_LIMIT_MB;
        }

        return (int)$limit_mb * 1024 * 1024;
    }

    private function worker_loop(int $worker_id): void
    {
        $this->worker_pids = [];

        \pcntl_async_signals(true);

        $shutdown = false;

        $graceful_shutdown = function (int $signal) use (&$shutdown, $worker_id): void {
            $name = $signal === SIGTERM ? 'SIGTERM' : 'SIGINT';
            Log::channel(LogChannel::QUEUE_WORKER)->info("Worker #$worker_id received $name. Will finish current job and exit.");
            $shutdown = true;
        };

        $this->job_cancelled_by_signal = false;
        $cancel_job = function () use ($worker_id): void {
            Log::channel(LogChannel::QUEUE_WORKER)->info("Worker #$worker_id received job cancellation signal. Will attempt to cancel current job.");
            $this->job_cancelled_by_signal = true;
        };

        \pcntl_signal(SIGTERM, $graceful_shutdown);
        \pcntl_signal(SIGINT,  $graceful_shutdown);
        \pcntl_signal(SIGUSR1, $cancel_job);
        \pcntl_signal(SIGCHLD, SIG_DFL);
        \pcntl_signal(SIGALRM, SIG_IGN);

        $this->queue_manager->open_all_connections();

        $consecutive_errors = 0;
        $memory_limit = $this->get_memory_limit_bytes();

        while (!$shutdown) {
            if (\memory_get_usage(true) > $memory_limit) {
                Log::channel(LogChannel::QUEUE_WORKER)->warning(
                    "Worker #$worker_id exceeded memory limit ("
                    . \round(\memory_get_usage(true) / 1024 / 1024, 1)
                    . "MB / " . \round($memory_limit / 1024 / 1024, 1) . "MB). Exiting for respawn."
                );
                break;
            }

            try {
                $jobs = $this->queue_manager->pop_batch();
                $consecutive_errors = 0;

                if (empty($jobs)) {
                    \usleep(self::IDLE_SLEEP_MICROSECONDS_DEFAULT);
                    continue;
                }

                foreach ($jobs as $job) {
                    if ($shutdown) {
                        break;
                    }
                    $this->process_single_job($job, $worker_id);
                }
            } catch (\Throwable $e) {
                $consecutive_errors++;
                $backoff = \min(
                    self::ERROR_BACKOFF_MAX_SECONDS,
                    (int)\pow(2, \min($consecutive_errors - 1, 5))
                );
                Log::channel(LogChannel::QUEUE_WORKER)->error("Worker #$worker_id loop error (attempt #$consecutive_errors, backoff {$backoff}s): " . $e->getMessage());
                $this->queue_manager->close_all_connections();
                \sleep($backoff);
                $this->queue_manager->open_all_connections();
            }
        }

        $this->queue_manager->close_all_connections();
        Log::channel(LogChannel::QUEUE_WORKER)->info("Worker #$worker_id (PID " . \getmypid() . ") exiting gracefully.");
        exit(0);
    }

    private function process_single_job(array $job, int $worker_id): void
    {
        $job['pid'] = \getmypid();

        if ($this->queue_manager->set_pid($job) === false) {
            Log::channel(LogChannel::QUEUE_WORKER)->warning("Failed to set PID for job {$job['uuid']} in worker #$worker_id - job likely claimed by monitor, skipping.");
            return;
        }

        $atomic = App::instance();

        $timed_out = false;
        \pcntl_signal(SIGALRM, function () use (&$timed_out): void {
            $timed_out = true;
        });
        \pcntl_alarm((int)$job['timeout']);

        try {
            if ($timed_out) {
                throw new \RuntimeException("Job {$job['uuid']} timed out after {$job['timeout']}s");
            }

            if ($this->job_cancelled_by_signal) {
                throw new JobCancelledException('Job cancellation requested');
            }

            $atomic->set('ATOMIC_QUEUE_CURRENT_UUID', $job['uuid']);
            $atomic->set('ATOMIC_QUEUE_CURRENT_BATCH_UUID', $job['payload']['uuid_batch']);
            $atomic->set('ATOMIC_QUEUE_CURRENT_NAME', $job['queue']);

            $this->queue_manager->process_job($job);
            $this->queue_manager->mark_completed($job);
            Log::channel(LogChannel::QUEUE_WORKER)->debug("Job {$job['uuid']} processed successfully by worker #$worker_id.");

        } catch (JobCancelledException $e) {
            $this->queue_manager->mark_cancelled($job, $e->getMessage());
            Log::channel(LogChannel::QUEUE_WORKER)->info("Job {$job['uuid']} cancelled by worker #$worker_id.");
        } catch (\Throwable $e) {
            if ($this->queue_manager->supports_cancel() && $this->queue_manager->is_cancel_requested($job['uuid'])) {
                $this->queue_manager->mark_cancelled($job, $e->getMessage());
                Log::channel(LogChannel::QUEUE_WORKER)->info("Job {$job['uuid']} cancelled after cancel request.");
                return;
            }
            if ($timed_out) {
                $this->queue_manager->close_all_connections();
                $this->queue_manager->open_all_connections();
            }

            $error_data = [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'code'    => $e->getCode(),
                'trace'   => $e->getTraceAsString(),
                'class'   => \get_class($e),
            ];
            Log::channel(LogChannel::QUEUE_WORKER)->debug("Job {$job['uuid']} failed: " . \json_encode($error_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if ($job['attempts'] >= $job['max_attempts']) {
                Log::channel(LogChannel::QUEUE_WORKER)->debug("Job {$job['uuid']} exceeded max attempts ({$job['max_attempts']}). Marked as failed.");
                $this->queue_manager->mark_failed($job, $e);
            } else {
                Log::channel(LogChannel::QUEUE_WORKER)->debug("Job {$job['uuid']} returned to queue for retry.");
                $this->queue_manager->release($job, (int)$job['retry_delay']);
            }

        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(SIGALRM, SIG_IGN);
            $this->job_cancelled_by_signal = false;

            $atomic->set('ATOMIC_QUEUE_CURRENT_UUID', null);
            $atomic->set('ATOMIC_QUEUE_CURRENT_BATCH_UUID', null);
            $atomic->set('ATOMIC_QUEUE_CURRENT_NAME', null);
        }
    }

    protected function fork_process(): int
    {
        return \pcntl_fork();
    }

    protected function wait_pid(int $pid, mixed &$status, int $flags = 0): int
    {
        return \pcntl_waitpid($pid, $status, $flags);
    }

    protected function was_signaled(int $status): bool
    {
        return \pcntl_wifsignaled($status);
    }

    protected function term_signal(int $status): int
    {
        return \pcntl_wtermsig($status);
    }

    protected function exit_status(int $status): int
    {
        return \pcntl_wexitstatus($status);
    }

    protected function signal_process(int $pid, int $signal): bool
    {
        return \posix_kill($pid, $signal);
    }

    protected function set_process_group(int $pid, int $pgid): bool
    {
        return \posix_setpgid($pid, $pgid);
    }

    protected function register_signal(int $signal, callable|int $handler): bool
    {
        return \pcntl_signal($signal, $handler);
    }

    protected function pause_microseconds(int $microseconds): void
    {
        \usleep($microseconds);
    }

    protected function current_time(): int
    {
        return \time();
    }
}
