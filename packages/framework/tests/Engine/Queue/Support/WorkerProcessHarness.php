<?php
declare(strict_types=1);

namespace Tests\Engine\Queue\Support;

use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Worker\Worker;
use PHPUnit\Framework\Assert;
use Tests\Support\Wait;

final class WorkerProcessHarness
{
    /** @var array<int, string> */
    private array $masters = [];

    private string $markerDir;

    public function __construct(?string $markerDir = null)
    {
        $this->markerDir = $markerDir ?: \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_worker_' . \bin2hex(\random_bytes(6));
        if (!\is_dir($this->markerDir) && !@\mkdir($this->markerDir, 0777, true) && !\is_dir($this->markerDir)) {
            throw new \RuntimeException('Unable to create worker marker directory: ' . $this->markerDir);
        }
    }

    public function markerDir(): string
    {
        return $this->markerDir;
    }

    public function startWorker(string $queue): int
    {
        ConnectionManager::instance()->close();

        $pid = \pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork worker master.');
        }

        if ($pid === 0) {
            \pcntl_async_signals(true);
            $manager = new Manager($queue);
            $worker = new Worker($manager);
            $worker->run();
            exit(0);
        }

        $this->masters[$pid] = $queue;
        return $pid;
    }

    public function stopAll(float $deadlineSeconds = 8.0): void
    {
        foreach (\array_keys($this->masters) as $pid) {
            $this->terminateProcess($pid, SIGTERM, $deadlineSeconds);
        }

        foreach (\array_keys($this->masters) as $pid) {
            if ($this->isAlive($pid)) {
                $this->terminateProcess($pid, SIGKILL, 2.0);
            }
            unset($this->masters[$pid]);
        }
    }

    public function waitUntil(callable $condition, float $deadlineSeconds, string $message): mixed
    {
        $last = null;

        if (Wait::until(static function () use ($condition, &$last): bool {
            $last = $condition();
            return (bool)$last;
        }, (int)\ceil($deadlineSeconds), 50_000)) {
            return $last;
        }

        Assert::fail($message);
    }

    public function assertMasterExited(int $pid, float $deadlineSeconds = 3.0): void
    {
        $this->waitUntil(
            fn (): bool => !$this->isAlive($pid) || $this->reap($pid),
            $deadlineSeconds,
            "Worker master {$pid} did not exit."
        );
        unset($this->masters[$pid]);
    }

    public function signal(int $pid, int $signal): void
    {
        if ($this->isAlive($pid)) {
            \posix_kill($pid, $signal);
        }
    }

    public function markerRows(string $type): array
    {
        $path = $this->markerDir . DIRECTORY_SEPARATOR . $type . '.jsonl';
        if (!\is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rows[] = \json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        return $rows;
    }

    public function markerCount(string $type): int
    {
        return \count($this->markerRows($type));
    }

    public function uniqueMarkerIds(string $type): array
    {
        $ids = [];
        foreach ($this->markerRows($type) as $row) {
            if (isset($row['id'])) {
                $ids[(string)$row['id']] = true;
            }
        }
        return \array_keys($ids);
    }

    public function release(string $id): void
    {
        \file_put_contents($this->markerDir . DIRECTORY_SEPARATOR . 'release-' . $this->safeName($id), '1');
    }

    public function cleanupMarkers(): void
    {
        $this->removeDirectory($this->markerDir);
    }

    private function terminateProcess(int $pid, int $signal, float $deadlineSeconds): void
    {
        if (!$this->isAlive($pid)) {
            $this->reap($pid);
            return;
        }

        @\posix_kill($pid, $signal);
        Wait::until(fn (): bool => $this->reap($pid) || !$this->isAlive($pid), (int)\ceil($deadlineSeconds), 50_000);
    }

    private function isAlive(int $pid): bool
    {
        return $pid > 0 && @\posix_kill($pid, 0);
    }

    private function reap(int $pid): bool
    {
        $result = \pcntl_waitpid($pid, $status, WNOHANG);
        return $result === $pid || $result === -1;
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = \scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }

    private function safeName(string $id): string
    {
        return \preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) ?: 'marker';
    }
}
