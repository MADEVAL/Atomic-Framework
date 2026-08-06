<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Managers\ProcessManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\Wait;

final class ProcessManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(false);
        }
        parent::tearDown();
    }

    public function test_reads_current_process_start_ticks(): void
    {
        $this->requireProcessIntegrationSupport();

        $start_ticks = (new ProcessManager(LogChannel::QUEUE_MONITOR))->get_process_start_ticks(\getmypid());

        $this->assertIsInt($start_ticks);
        $this->assertGreaterThan(0, $start_ticks);
    }

    public function test_missing_pid_returns_null(): void
    {
        $this->assertNull((new ProcessManager(LogChannel::QUEUE_MONITOR))->get_process_start_ticks(999999999));
    }

    public function test_current_process_ownership_check_requires_start_ticks(): void
    {
        $this->requireProcessIntegrationSupport();

        $pm = new ProcessManager(LogChannel::QUEUE_MONITOR);
        $ticks = $pm->get_process_start_ticks(\getmypid());

        $this->assertTrue($pm->is_our_process(\getmypid(), ['process_start_ticks' => $ticks]));
        $this->assertFalse($pm->is_our_process(\getmypid(), ['process_start_ticks' => $ticks + 1]));
        $this->assertFalse($pm->is_our_process(\getmypid(), []));
    }

    public function test_real_sigusr1_cancellation_signal(): void
    {
        $this->requireProcessIntegrationSupport();

        $marker = \tempnam(\sys_get_temp_dir(), 'atomic_sigusr1_');
        $pid = $this->forkSignalAwareChild($marker, SIGUSR1);

        try {
            $this->waitForProcStat($pid);
            $this->assertTrue($this->waitForFileContents($marker, 'ready'));
            $pm = new ProcessManager(LogChannel::QUEUE_MONITOR);
            $ticks = $pm->get_process_start_ticks($pid);

            $this->assertIsInt($ticks);
            $this->assertTrue($pm->send_cancellation_signal(['pid' => $pid, 'process_start_ticks' => $ticks]));
            $this->assertTrue($this->waitForFileContents($marker, 'signal'));
        } finally {
            $this->stopChild($pid);
            @\unlink($marker);
        }
    }

    public function test_mismatched_start_ticks_do_not_signal(): void
    {
        $this->requireProcessIntegrationSupport();

        $marker = \tempnam(\sys_get_temp_dir(), 'atomic_no_signal_');
        $pid = $this->forkSignalAwareChild($marker, SIGUSR1);

        try {
            $this->waitForProcStat($pid);
            $this->assertTrue($this->waitForFileContents($marker, 'ready'));
            $pm = new ProcessManager(LogChannel::QUEUE_MONITOR);
            $ticks = $pm->get_process_start_ticks($pid);

            $this->assertIsInt($ticks);
            $this->assertFalse($pm->send_cancellation_signal(['pid' => $pid, 'process_start_ticks' => $ticks + 1]));
            $this->assertFalse(Wait::until(
                fn (): bool => (string)@\file_get_contents($marker) !== 'ready',
                1,
                20_000
            ));
            $this->assertSame('ready', (string)@\file_get_contents($marker));
        } finally {
            $this->stopChild($pid);
            @\unlink($marker);
        }
    }

    public function test_parser_edge_cases(): void
    {
        $this->assertSame(123456, ProcessManager::parse_start_ticks_from_stat($this->statLine('php', 123456)));
        $this->assertSame(234567, ProcessManager::parse_start_ticks_from_stat($this->statLine('php worker', 234567)));
        $this->assertSame(345678, ProcessManager::parse_start_ticks_from_stat($this->statLine('php) worker', 345678)));
        $this->assertNull(ProcessManager::parse_start_ticks_from_stat('1234 php S 1 2 3'));
        $this->assertNull(ProcessManager::parse_start_ticks_from_stat('1234 (php) S 1 2 3'));
    }

    private function requireProcessIntegrationSupport(): void
    {
        foreach (['pcntl_fork', 'pcntl_signal', 'pcntl_waitpid', 'pcntl_async_signals', 'posix_kill'] as $fn) {
            if (!\function_exists($fn)) {
                $this->markTestSkipped("Process integration test requires {$fn}().");
            }
        }

        if (!\is_dir('/proc') || !\is_readable('/proc')) {
            $this->markTestSkipped('Process integration test requires readable /proc.');
        }

        $pm = new ProcessManager(LogChannel::QUEUE_MONITOR);
        if ($pm->get_process_start_ticks(\getmypid()) === null) {
            $this->markTestSkipped('Process integration test cannot read current process start ticks.');
        }
    }

    private function forkSignalAwareChild(string $marker, int $signal): int
    {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork child process.');
        }

        if ($pid === 0) {
            \pcntl_async_signals(true);
            \pcntl_signal($signal, static function () use ($marker): void {
                \file_put_contents($marker, 'signal');
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

    private function statLine(string $name, int $startTicks): string
    {
        $fields = ['S'];
        for ($i = 1; $i <= 18; $i++) {
            $fields[] = (string)$i;
        }
        $fields[] = (string)$startTicks;
        $fields[] = '0';

        return '1234 (' . $name . ') ' . \implode(' ', $fields);
    }
}
