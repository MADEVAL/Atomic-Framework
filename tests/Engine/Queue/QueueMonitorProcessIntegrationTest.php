<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\ProcessManager;
use Engine\Atomic\Queue\Monitor\Monitor;
use Engine\Atomic\Queue\Monitor\PosixProcessProbe;
use Engine\Atomic\Queue\Monitor\ProcessProbeInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\Wait;

final class QueueMonitorProcessIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(false);
        }
        parent::tearDown();
    }

    public function test_placeholder_pid_is_handled_incomplete(): void
    {
        $manager = new MonitorFakeManager([MonitorFakeManager::job(['pid' => -1])]);
        (new Monitor(null, $manager, new MonitorFakeProcessManager(), new MonitorFakeProbe()))->check_stuck_jobs();

        $this->assertCount(1, $manager->handled);
    }

    public function test_invalid_pid_is_handled_after_double_check(): void
    {
        $job = MonitorFakeManager::job(['pid' => 0]);
        $manager = new MonitorFakeManager([$job]);
        (new Monitor(null, $manager, new MonitorFakeProcessManager(), new MonitorFakeProbe()))->check_stuck_jobs();

        $this->assertSame([$job['uuid']], \array_column($manager->handled, 'uuid'));
    }

    public function test_permission_denied_stuck_job_is_skipped(): void
    {
        $manager = new MonitorFakeManager([MonitorFakeManager::job(['pid' => 42])]);
        $probe = new MonitorFakeProbe([42 => ['exists' => true, 'error' => 1, 'is_permission_error' => true]]);

        (new Monitor(null, $manager, new MonitorFakeProcessManager(), $probe))->check_stuck_jobs();

        $this->assertSame([], $manager->handled);
        $this->assertSame([], $probe->signals);
    }

    public function test_active_stuck_job_is_skipped_when_processes_cannot_be_checked(): void
    {
        $manager = new MonitorFakeManager([MonitorFakeManager::job(['pid' => 42])]);
        $probe = new MonitorFakeProbe([42 => ['exists' => true, 'error' => null, 'is_permission_error' => false]]);

        (new Monitor(null, $manager, new MonitorFakeProcessManager(true, false), $probe))->check_stuck_jobs();

        $this->assertSame([], $manager->handled);
        $this->assertSame([], $probe->signals);
    }

    public function test_active_foreign_process_is_not_killed_and_is_handled(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42, 'process_start_ticks' => 100]);
        $manager = new MonitorFakeManager([$job]);
        $probe = new MonitorFakeProbe([42 => ['exists' => true, 'error' => null, 'is_permission_error' => false]]);

        (new Monitor(null, $manager, new MonitorFakeProcessManager(false), $probe))->check_stuck_jobs();

        $this->assertSame([$job['uuid']], \array_column($manager->handled, 'uuid'));
        $this->assertSame([], $probe->signals);
    }

    public function test_active_owned_process_receives_sigterm_and_is_handled_after_exit(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42, 'process_start_ticks' => 100]);
        $manager = new MonitorFakeManager([$job]);
        $probe = new MonitorFakeProbe([
            42 => [
                ['exists' => true, 'error' => null, 'is_permission_error' => false],
                ['exists' => false, 'error' => 3, 'is_permission_error' => false],
            ],
        ]);

        (new Monitor(null, $manager, new MonitorFakeProcessManager(true), $probe))->check_stuck_jobs();

        $this->assertSame([[42, SIGTERM]], $probe->signals);
        $this->assertSame([$job['uuid']], \array_column($manager->handled, 'uuid'));
    }

    public function test_real_owned_child_process_receives_sigterm_and_is_handled(): void
    {
        $this->requireProcessIntegrationSupport();

        $marker = \tempnam(\sys_get_temp_dir(), 'atomic_monitor_term_');
        \pcntl_signal(SIGCHLD, SIG_IGN);
        $pid = $this->forkSigtermAwareChild($marker);

        try {
            $this->waitForProcStat($pid);
            $this->assertTrue($this->waitForFileContents($marker, 'ready'));

            $job = MonitorFakeManager::job(['pid' => $pid, 'process_start_ticks' => 1]);
            $manager = new MonitorFakeManager([$job]);
            $monitor = new Monitor(null, $manager, new MonitorFakeProcessManager(true), new PosixProcessProbe());

            $monitor->check_stuck_jobs();

            $this->assertTrue($this->waitForFileContents($marker, 'term'));
            $this->assertSame([$job['uuid']], \array_column($manager->handled, 'uuid'));
        } finally {
            \pcntl_signal(SIGCHLD, SIG_DFL);
            $this->stopChild($pid);
            @\unlink($marker);
        }
    }

    public function test_real_foreign_child_process_is_not_killed(): void
    {
        $this->requireProcessIntegrationSupport();

        $marker = \tempnam(\sys_get_temp_dir(), 'atomic_monitor_foreign_');
        $pid = $this->forkSigtermAwareChild($marker);

        try {
            $this->waitForProcStat($pid);
            $this->assertTrue($this->waitForFileContents($marker, 'ready'));

            $job = MonitorFakeManager::job(['pid' => $pid, 'process_start_ticks' => 1]);
            $manager = new MonitorFakeManager([$job]);
            $monitor = new Monitor(null, $manager, new MonitorFakeProcessManager(false), new PosixProcessProbe());

            $monitor->check_stuck_jobs();

            $this->assertSame('ready', (string)@\file_get_contents($marker));
            $this->assertTrue(@\posix_kill($pid, 0));
            $this->assertSame([$job['uuid']], \array_column($manager->handled, 'uuid'));
        } finally {
            $this->stopChild($pid);
            @\unlink($marker);
        }
    }

    public function test_owned_process_that_survives_sigterm_becomes_unkillable(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42, 'process_start_ticks' => 100]);
        $manager = new MonitorFakeManager([$job]);
        $probe = new MonitorFakeProbe([42 => ['exists' => true, 'error' => null, 'is_permission_error' => false]]);
        $monitor = new Monitor(null, $manager, new MonitorFakeProcessManager(true), $probe);

        $monitor->check_stuck_jobs();

        $this->assertSame([$job['uuid']], \array_keys($this->unkillableJobs($monitor)));
        $this->assertSame([], $manager->handled);
    }

    public function test_unkillable_retry_succeeds_after_sigkill(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42, 'process_start_ticks' => 100]);
        $manager = new MonitorFakeManager([$job]);
        $probe = new MonitorFakeProbe([
            42 => [
                ['exists' => true, 'error' => null, 'is_permission_error' => false],
                ['exists' => true, 'error' => null, 'is_permission_error' => false],
                ['exists' => true, 'error' => null, 'is_permission_error' => false],
                ['exists' => false, 'error' => 3, 'is_permission_error' => false],
            ],
        ]);
        $monitor = new TestableMonitor(null, $manager, new MonitorFakeProcessManager(true), $probe);

        $monitor->check_stuck_jobs();
        $monitor->retryUnkillableForTesting();

        $this->assertSame([[42, SIGTERM], [42, SIGKILL]], $probe->signals);
        $this->assertSame([$job['uuid']], \array_column($manager->handled, 'uuid'));
        $this->assertSame([], $this->unkillableJobs($monitor));
    }

    public function test_unkillable_retry_gives_up_after_max_attempts(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42, 'process_start_ticks' => 100]);
        $manager = new MonitorFakeManager([$job]);
        $probe = new MonitorFakeProbe([42 => ['exists' => true, 'error' => null, 'is_permission_error' => false]]);
        $monitor = new TestableMonitor(null, $manager, new MonitorFakeProcessManager(true), $probe);

        $monitor->check_stuck_jobs();
        $monitor->retryUnkillableForTesting();
        $monitor->retryUnkillableForTesting();
        $monitor->retryUnkillableForTesting();

        $this->assertCount(1, $manager->failed);
        $this->assertStringContainsString('Failed to terminate process', $manager->failed[0]->getMessage());
        $this->assertSame([], $this->unkillableJobs($monitor));
    }

    public function test_unkillable_retry_permission_denied_marks_failed(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42, 'process_start_ticks' => 100]);
        $manager = new MonitorFakeManager([$job]);
        $probe = new MonitorFakeProbe([
            42 => [
                ['exists' => true, 'error' => null, 'is_permission_error' => false],
                ['exists' => true, 'error' => null, 'is_permission_error' => false],
                ['exists' => true, 'error' => 1, 'is_permission_error' => true],
            ],
        ]);
        $monitor = new TestableMonitor(null, $manager, new MonitorFakeProcessManager(true), $probe);

        $monitor->check_stuck_jobs();
        $monitor->retryUnkillableForTesting();

        $this->assertCount(1, $manager->failed);
        $this->assertStringContainsString('insufficient permissions', $manager->failed[0]->getMessage());
        $this->assertSame([], $this->unkillableJobs($monitor));
    }

    public function test_unknown_monitor_signal_does_not_shutdown(): void
    {
        $monitor = new Monitor(null, new MonitorFakeManager(), new MonitorFakeProcessManager(), new MonitorFakeProbe());
        $this->setMonitorShutdown(false);

        $monitor->handle_signal(SIGUSR1);

        $this->assertFalse($this->monitorShutdown());
    }

    public function test_run_cleanup_closes_connections_and_restores_runtime_state(): void
    {
        $this->requireSignalSupport();

        $termHandler = static function (): void {};
        $intHandler = static function (): void {};
        \pcntl_signal(SIGTERM, $termHandler);
        \pcntl_signal(SIGINT, $intHandler);
        \pcntl_async_signals(false);

        $manager = new MonitorFakeManager();
        $monitor = new AutoShutdownMonitor(null, $manager, new MonitorFakeProcessManager(), new MonitorFakeProbe());
        $this->setMonitorShutdown(false);

        try {
            $monitor->run();

            $this->assertSame(1, $manager->closeCalls);
            $this->assertFalse($this->monitorShutdown());
            $this->assertFalse(\pcntl_async_signals());
            $this->assertSame($termHandler, \pcntl_signal_get_handler(SIGTERM));
            $this->assertSame($intHandler, \pcntl_signal_get_handler(SIGINT));
        } finally {
            \pcntl_signal(SIGTERM, SIG_DFL);
            \pcntl_signal(SIGINT, SIG_DFL);
            \pcntl_async_signals(false);
            $this->setMonitorShutdown(false);
        }
    }

    public function test_active_inactive_process_is_handled(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42]);
        $manager = new MonitorFakeManager([], [$job]);
        $probe = new MonitorFakeProbe([42 => ['exists' => false, 'error' => 3, 'is_permission_error' => false]]);

        (new Monitor(null, $manager, new MonitorFakeProcessManager(), $probe))->check_active_jobs();

        $this->assertSame([$job['uuid']], \array_column($manager->handled, 'uuid'));
    }

    public function test_active_permission_denied_process_is_left_alone(): void
    {
        $manager = new MonitorFakeManager([], [MonitorFakeManager::job(['pid' => 42])]);
        $probe = new MonitorFakeProbe([42 => ['exists' => true, 'error' => 1, 'is_permission_error' => true]]);

        (new Monitor(null, $manager, new MonitorFakeProcessManager(), $probe))->check_active_jobs();

        $this->assertSame([], $manager->handled);
    }

    public function test_double_check_skip_when_job_disappears_before_handling(): void
    {
        $job = MonitorFakeManager::job(['pid' => 42]);
        $manager = new MonitorFakeManager([$job], [], false);
        $probe = new MonitorFakeProbe([42 => ['exists' => false, 'error' => 3, 'is_permission_error' => false]]);

        (new Monitor(null, $manager, new MonitorFakeProcessManager(), $probe))->check_stuck_jobs();

        $this->assertSame([], $manager->handled);
    }

    private function requireProcessIntegrationSupport(): void
    {
        $this->requireSignalSupport();

        foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill'] as $fn) {
            if (!\function_exists($fn)) {
                $this->markTestSkipped("Monitor process integration test requires {$fn}().");
            }
        }

        if (!\is_dir('/proc') || !\is_readable('/proc')) {
            $this->markTestSkipped('Monitor process integration test requires readable /proc.');
        }
    }

    private function requireSignalSupport(): void
    {
        foreach (['pcntl_signal', 'pcntl_async_signals'] as $fn) {
            if (!\function_exists($fn)) {
                $this->markTestSkipped("Monitor process integration test requires {$fn}().");
            }
        }

        if (!\function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('Monitor process integration test requires pcntl_signal_get_handler().');
        }
    }

    private function forkSigtermAwareChild(string $marker): int
    {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork child process.');
        }

        if ($pid === 0) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGTERM, static function () use ($marker): void {
                \file_put_contents($marker, 'term');
                exit(0);
            });
            \file_put_contents($marker, 'ready');

            while (true) {
                \usleep(50_000);
            }
        }

        return $pid;
    }

    private function waitForProcStat(int $pid): void
    {
        if (Wait::until(fn (): bool => \is_readable("/proc/{$pid}/stat"), 2, 20_000)) {
            return;
        }

        $this->fail("Timed out waiting for /proc/{$pid}/stat.");
    }

    private function waitForFileContents(string $path, string $expected): bool
    {
        return Wait::until(fn (): bool => (string)@\file_get_contents($path) === $expected, 2, 20_000);
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

    private function unkillableJobs(Monitor $monitor): array
    {
        $property = new \ReflectionProperty(Monitor::class, 'unkillable_pids');
        return $property->getValue($monitor);
    }

    private function monitorShutdown(): bool
    {
        $property = new \ReflectionProperty(Monitor::class, 'shutdown');
        return $property->getValue();
    }

    private function setMonitorShutdown(bool $value): void
    {
        $property = new \ReflectionProperty(Monitor::class, 'shutdown');
        $property->setValue(null, $value);
    }
}

final class TestableMonitor extends Monitor
{
    public function retryUnkillableForTesting(): void
    {
        $method = new \ReflectionMethod(Monitor::class, 'retry_unkillable_processes');
        $method->invoke($this);
    }
}

final class AutoShutdownMonitor extends Monitor
{
    public function check_stuck_jobs(): void
    {
        $this->handle_signal(SIGTERM);
    }

    public function check_active_jobs(): void
    {
    }
}

final class MonitorFakeManager extends Manager
{
    public array $handled = [];
    public array $failed = [];
    public int $closeCalls = 0;

    public function __construct(
        private array $stuck = [],
        private array $activeJobs = [],
        private bool $exists = true,
    ) {
    }

    public static function job(array $overrides = []): array
    {
        return \array_merge([
            'uuid' => \bin2hex(\random_bytes(8)),
            'queue' => 'monitor_test',
            'pid' => 0,
            'attempts' => 1,
            'max_attempts' => 3,
            'retry_delay' => 0,
            'payload' => ['uuid_batch' => \bin2hex(\random_bytes(8))],
        ], $overrides);
    }

    public function load_stuck_jobs(array $exclude, string $queue = '*'): array
    {
        return \array_values(\array_filter(
            $this->stuck,
            static fn (array $job): bool => !\in_array($job['uuid'], $exclude, true)
        ));
    }

    public function load_active_jobs(string $queue = '*'): array
    {
        return $this->activeJobs;
    }

    public function exists_in_jobs_table(string $uuid, int $pid): bool
    {
        return $this->exists;
    }

    public function handle_incomplete_job(array $job): void
    {
        $this->handled[] = $job;
    }

    public function mark_failed(array $job, \Throwable $exception): bool
    {
        $this->failed[] = $exception;
        return true;
    }

    public function close_all_connections(): void
    {
        $this->closeCalls++;
    }
}

final class MonitorFakeProcessManager extends ProcessManager
{
    public function __construct(
        private bool $isOurProcess = true,
        private bool $canCheckProcesses = true,
    )
    {
    }

    public function can_check_processes(): bool
    {
        return $this->canCheckProcesses;
    }

    public function is_our_process(int $pid, array $job): bool
    {
        return $this->isOurProcess;
    }
}

final class MonitorFakeProbe implements ProcessProbeInterface
{
    public array $signals = [];

    public function __construct(private array $statuses = [])
    {
    }

    public function exists(int $pid): array
    {
        $status = $this->statuses[$pid] ?? ['exists' => false, 'error' => 3, 'is_permission_error' => false];
        if (isset($status[0]) && \is_array($status[0])) {
            $next = \array_shift($status);
            $this->statuses[$pid] = $status ?: $next;
            return $next;
        }

        return $status;
    }

    public function signal(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];
        return true;
    }

    public function sleep(int $seconds): void
    {
    }

    public function usleep(int $microseconds): void
    {
    }
}
