<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Exceptions\JobCancelledException;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Worker\Worker;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\Support\Wait;

#[RequiresPhpExtension('pcntl')]
final class WorkerTest extends TestCase
{
    private array $originalState = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireWorkerSignalSupport();

        $atomic = App::instance();
        $this->originalState = [
            'QUEUE_DRIVER' => $atomic->get('QUEUE_DRIVER'),
            'QUEUE_NAME' => $atomic->get('QUEUE_NAME'),
            'QUEUE' => $atomic->get('QUEUE'),
            'ATOMIC_QUEUE_CURRENT_UUID' => $atomic->get('ATOMIC_QUEUE_CURRENT_UUID'),
            'ATOMIC_QUEUE_CURRENT_BATCH_UUID' => $atomic->get('ATOMIC_QUEUE_CURRENT_BATCH_UUID'),
            'ATOMIC_QUEUE_CURRENT_NAME' => $atomic->get('ATOMIC_QUEUE_CURRENT_NAME'),
        ];

        $atomic->set('QUEUE_DRIVER', 'db');
        $atomic->set('QUEUE_NAME', 'worker_test');
        $atomic->set('QUEUE', [
            'db' => [
                'queues' => [
                    'worker_test' => [
                        'worker_cnt' => 1,
                        'timeout' => 5,
                        'memory_limit_mb' => 128,
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        \pcntl_alarm(0);
        foreach ($this->originalState as $key => $value) {
            App::instance()->set($key, $value);
        }
        parent::tearDown();
    }

    public function test_successful_job_is_marked_completed_and_context_is_cleared(): void
    {
        $manager = new WorkerFakeManager();
        $job = $this->job();

        $this->processSingleJob($manager, $job);

        $this->assertSame([$job['uuid']], $manager->processed);
        $this->assertSame([$job['uuid']], $manager->completed);
        $this->assertSame([], $manager->failed);
        $this->assertSame([], $manager->released);
        $this->assertQueueContextCleared();
    }

    public function test_retryable_exception_releases_job(): void
    {
        $manager = new WorkerFakeManager(new \RuntimeException('retry me'));
        $job = $this->job(['attempts' => 1, 'max_attempts' => 3, 'retry_delay' => 7]);

        $this->processSingleJob($manager, $job);

        $this->assertSame([$job['uuid']], $manager->processed);
        $this->assertSame([], $manager->completed);
        $this->assertSame([], $manager->failed);
        $this->assertSame([[$job['uuid'], 7]], $manager->released);
        $this->assertQueueContextCleared();
    }

    public function test_exhausted_exception_marks_job_failed(): void
    {
        $manager = new WorkerFakeManager(new \RuntimeException('done trying'));
        $job = $this->job(['attempts' => 3, 'max_attempts' => 3]);

        $this->processSingleJob($manager, $job);

        $this->assertSame([$job['uuid']], $manager->processed);
        $this->assertSame([], $manager->completed);
        $this->assertSame([$job['uuid']], \array_column($manager->failed, 'uuid'));
        $this->assertSame([], $manager->released);
        $this->assertQueueContextCleared();
    }

    public function test_job_cancelled_exception_marks_cancelled(): void
    {
        $manager = new WorkerFakeManager(new JobCancelledException('cancel now'));
        $job = $this->job();

        $this->processSingleJob($manager, $job);

        $this->assertSame([$job['uuid']], $manager->processed);
        $this->assertSame([[$job['uuid'], 'cancel now']], $manager->cancelled);
        $this->assertSame([], $manager->completed);
        $this->assertSame([], $manager->failed);
        $this->assertSame([], $manager->released);
        $this->assertQueueContextCleared();
    }

    public function test_exception_after_cancel_request_marks_cancelled(): void
    {
        $manager = new WorkerFakeManager(new \RuntimeException('interrupted'));
        $manager->supportsCancel = true;
        $manager->cancelRequested = true;
        $job = $this->job();

        $this->processSingleJob($manager, $job);

        $this->assertSame([$job['uuid']], $manager->processed);
        $this->assertSame([[$job['uuid'], 'interrupted']], $manager->cancelled);
        $this->assertSame([], $manager->completed);
        $this->assertSame([], $manager->failed);
        $this->assertSame([], $manager->released);
        $this->assertQueueContextCleared();
    }

    public function test_set_pid_failure_skips_job(): void
    {
        $manager = new WorkerFakeManager();
        $manager->setPidResult = false;
        $job = $this->job();

        $this->processSingleJob($manager, $job);

        $this->assertSame([], $manager->processed);
        $this->assertSame([], $manager->completed);
        $this->assertSame([], $manager->failed);
        $this->assertSame([], $manager->released);
        $this->assertSame([], $manager->cancelled);
    }

    public function test_spawn_worker_registers_child_pid_without_running_child_loop(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());
        $worker->forkResults = [4321];

        $this->invokeWorkerMethod($worker, 'spawn_worker', [7]);

        $this->assertSame([4321 => 7], $this->workerProperty($worker, 'worker_pids'));
        $this->assertSame([[4321, 4321]], $worker->setProcessGroupCalls);
    }

    public function test_spawn_worker_handles_fork_failure_without_registering_pid(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());
        $worker->forkResults = [-1];

        $this->invokeWorkerMethod($worker, 'spawn_worker', [7]);

        $this->assertSame([], $this->workerProperty($worker, 'worker_pids'));
        $this->assertSame([], $worker->setProcessGroupCalls);
    }

    public function test_unknown_master_signal_does_not_trigger_shutdown(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());

        $worker->handle_signal(SIGUSR1);

        $this->assertFalse($this->workerProperty($worker, 'shutdown'));
    }

    public function test_sigchld_records_signal_terminated_worker_for_respawn(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());
        $this->setWorkerProperty($worker, 'worker_pids', [1234 => 2]);
        $worker->waitResults = [[1234, 0], [0, 0]];
        $worker->wasSignaled = true;
        $worker->termSignal = SIGKILL;

        $worker->handle_sigchld(SIGCHLD);

        $this->assertSame([], $this->workerProperty($worker, 'worker_pids'));
        $this->assertSame([2], $this->workerProperty($worker, 'pending_respawns'));
    }

    public function test_sigchld_queues_respawn_for_exited_worker(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());
        $this->setWorkerProperty($worker, 'worker_pids', [1234 => 2]);
        $worker->waitResults = [[1234, 0], [0, 0]];
        $worker->exitStatus = 12;

        $worker->handle_sigchld(SIGCHLD);

        $this->assertSame([], $this->workerProperty($worker, 'worker_pids'));
        $this->assertSame([2], $this->workerProperty($worker, 'pending_respawns'));
    }

    public function test_sigchld_does_not_respawn_during_shutdown(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());
        $this->setWorkerProperty($worker, 'worker_pids', [1234 => 2]);
        $this->setWorkerProperty($worker, 'shutdown', true);
        $worker->waitResults = [[1234, 0], [0, 0]];

        $worker->handle_sigchld(SIGCHLD);

        $this->assertSame([], $this->workerProperty($worker, 'worker_pids'));
        $this->assertSame([], $this->workerProperty($worker, 'pending_respawns'));
    }

    public function test_drain_workers_sends_sigterm_and_removes_reaped_worker(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());
        $this->setWorkerProperty($worker, 'worker_pids', [2222 => 1]);
        $worker->waitResults = [[2222, 0]];

        $this->invokeWorkerMethod($worker, 'drain_workers');

        $this->assertSame([[2222, SIGTERM]], $worker->signals);
        $this->assertSame([], $this->workerProperty($worker, 'worker_pids'));
        $this->assertSame([[SIGCHLD, SIG_DFL]], $worker->registeredSignals);
    }

    public function test_drain_workers_escalates_to_sigkill_after_timeout(): void
    {
        $worker = new WorkerProcessFake(new WorkerFakeManager());
        $this->setWorkerProperty($worker, 'worker_pids', [3333 => 1]);
        $worker->waitResults = [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [-1, 0]];

        $this->invokeWorkerMethod($worker, 'drain_workers');

        $this->assertSame([[3333, SIGTERM], [3333, SIGKILL]], $worker->signals);
        $this->assertSame([], $this->workerProperty($worker, 'worker_pids'));
    }

    public function test_real_drain_workers_terminates_and_reaps_child_process(): void
    {
        $this->requireRealWorkerProcessSupport();

        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork worker drain child.');
        }

        if ($pid === 0) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGTERM, static function (): void {
                exit(0);
            });
            while (true) {
                \usleep(50_000);
            }
        }

        $worker = new Worker(new WorkerFakeManager());
        $this->setWorkerProperty($worker, 'worker_pids', [$pid => 1]);

        try {
            $this->invokeWorkerMethod($worker, 'drain_workers');
            $this->assertSame([], $this->workerProperty($worker, 'worker_pids'));
            $this->assertFalse(@\posix_kill($pid, 0));
        } finally {
            $this->stopChild($pid);
        }
    }

    private function processSingleJob(WorkerFakeManager $manager, array $job): void
    {
        $worker = new Worker($manager);
        $method = new \ReflectionMethod(Worker::class, 'process_single_job');
        $method->invoke($worker, $job, 1);
    }

    private function job(array $overrides = []): array
    {
        return \array_merge([
            'uuid' => \bin2hex(\random_bytes(8)),
            'queue' => 'worker_test',
            'payload' => ['uuid_batch' => \bin2hex(\random_bytes(8))],
            'timeout' => 5,
            'attempts' => 1,
            'max_attempts' => 3,
            'retry_delay' => 0,
        ], $overrides);
    }

    private function assertQueueContextCleared(): void
    {
        $atomic = App::instance();
        $this->assertNull($atomic->get('ATOMIC_QUEUE_CURRENT_UUID'));
        $this->assertNull($atomic->get('ATOMIC_QUEUE_CURRENT_BATCH_UUID'));
        $this->assertNull($atomic->get('ATOMIC_QUEUE_CURRENT_NAME'));
    }

    private function requireWorkerSignalSupport(): void
    {
        foreach (['pcntl_signal', 'pcntl_alarm'] as $fn) {
            if (!\function_exists($fn)) {
                $this->markTestSkipped("Worker test requires {$fn}().");
            }
        }
    }

    private function requireRealWorkerProcessSupport(): void
    {
        foreach (['pcntl_fork', 'pcntl_signal', 'pcntl_waitpid', 'pcntl_async_signals', 'posix_kill'] as $fn) {
            if (!\function_exists($fn)) {
                $this->markTestSkipped("Worker process test requires {$fn}().");
            }
        }
    }

    private function invokeWorkerMethod(Worker $worker, string $methodName, array $args = []): mixed
    {
        $method = new \ReflectionMethod(Worker::class, $methodName);
        return $method->invokeArgs($worker, $args);
    }

    private function workerProperty(Worker $worker, string $propertyName): mixed
    {
        $property = new \ReflectionProperty(Worker::class, $propertyName);
        return $property->getValue($worker);
    }

    private function setWorkerProperty(Worker $worker, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty(Worker::class, $propertyName);
        $property->setValue($worker, $value);
    }

    private function stopChild(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        @\posix_kill($pid, SIGTERM);
        Wait::until(fn (): bool => !@\posix_kill($pid, 0), 1, 20_000);
        if (@\posix_kill($pid, 0)) {
            @\posix_kill($pid, SIGKILL);
        }

        while (\pcntl_waitpid($pid, $status, WNOHANG) > 0) {
        }
    }
}

final class WorkerProcessFake extends Worker
{
    public array $forkResults = [];
    public array $waitResults = [];
    public array $signals = [];
    public array $setProcessGroupCalls = [];
    public array $registeredSignals = [];
    public bool $wasSignaled = false;
    public int $termSignal = SIGTERM;
    public int $exitStatus = 0;
    private int $time = 0;

    protected function fork_process(): int
    {
        return \array_shift($this->forkResults) ?? -1;
    }

    protected function wait_pid(int $pid, mixed &$status, int $flags = 0): int
    {
        [$result, $status] = \array_shift($this->waitResults) ?? [0, 0];
        return $result;
    }

    protected function was_signaled(int $status): bool
    {
        return $this->wasSignaled;
    }

    protected function term_signal(int $status): int
    {
        return $this->termSignal;
    }

    protected function exit_status(int $status): int
    {
        return $this->exitStatus;
    }

    protected function signal_process(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];
        return true;
    }

    protected function set_process_group(int $pid, int $pgid): bool
    {
        $this->setProcessGroupCalls[] = [$pid, $pgid];
        return true;
    }

    protected function register_signal(int $signal, callable|int $handler): bool
    {
        $this->registeredSignals[] = [$signal, $handler];
        return true;
    }

    protected function pause_microseconds(int $microseconds): void
    {
    }

    protected function current_time(): int
    {
        return $this->time++;
    }
}

final class WorkerFakeManager extends Manager
{
    public bool $setPidResult = true;
    public bool $supportsCancel = false;
    public bool $cancelRequested = false;
    public array $processed = [];
    public array $completed = [];
    public array $failed = [];
    public array $released = [];
    public array $cancelled = [];

    public function __construct(private ?\Throwable $processException = null)
    {
    }

    public function get_queue(): string
    {
        return 'worker_test';
    }

    public function set_pid(array $job): bool
    {
        return $this->setPidResult;
    }

    public function process_job(array $job)
    {
        $this->processed[] = $job['uuid'];
        if ($this->processException) {
            throw $this->processException;
        }
    }

    public function mark_completed(array $job): bool
    {
        $this->completed[] = $job['uuid'];
        return true;
    }

    public function mark_failed(array $job, \Throwable $exception): bool
    {
        $this->failed[] = ['uuid' => $job['uuid'], 'exception' => $exception];
        return true;
    }

    public function release(array $job, int $delay): bool
    {
        $this->released[] = [$job['uuid'], $delay];
        return true;
    }

    public function mark_cancelled(array $job, ?string $reason = null): bool
    {
        $this->cancelled[] = [$job['uuid'], $reason];
        return true;
    }

    public function supports_cancel(): bool
    {
        return $this->supportsCancel;
    }

    public function is_cancel_requested(string $uuid): bool
    {
        return $this->cancelRequested;
    }
}
