<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Tests;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Queue\Exceptions\JobCancelledException;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\TelemetryManager;

class TestJob {
    public static array $cancelled = [];

    public static function reset(): void {
        self::$cancelled = [];
    }

    public function success(array $params, $smth) {
    }
    public function cancelled(array $params, $smth, array $job) {
        self::$cancelled[] = [
            'params' => $params,
            'smth' => $smth,
            'uuid' => $job['uuid'] ?? null,
        ];
    }
    public function failure(array $params, $smth) {
        throw new \Exception("Job failed as expected");
    }
    public function timeout(array $params, $smth) {
        sleep(60);
    }
    public function self_cancel(array $params, $smth) {
        throw new JobCancelledException("Job cancelled as expected");
    }
    public function self_request_cancel(array $params, $smth) {
        $uuid = (string)\Base::instance()->get('ATOMIC_QUEUE_CURRENT_UUID');
        if ($uuid === '') {
            throw new JobCancelledException("Job cancellation requested without current UUID");
        }

        if ((new Manager())->cancel($uuid)) {
            throw new JobCancelledException("Job cancellation requested by test job");
        }

        throw new \RuntimeException("Unable to request cancellation for test job {$uuid}");
    }
    public function event(array $params, $smth) {
        $telemetry_manager = new TelemetryManager();
        $telemetry_manager->push_telemetry("Custom event from job");
    }

    public function record_success(string $marker_dir, string $id, string $queue = ''): void
    {
        $this->append_marker($marker_dir, 'success', [
            'id' => $id,
            'queue' => $queue,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);
    }

    public function slow_success(string $marker_dir, string $id, float $seconds = 1.0): void
    {
        $this->append_marker($marker_dir, 'running', [
            'id' => $id,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);

        $deadline = \microtime(true) + $seconds;
        while (\microtime(true) < $deadline) {
            \usleep(50000);
        }

        $this->record_success($marker_dir, $id);
    }

    public function fail_once_then_success(string $marker_dir, string $id): void
    {
        $attempt = $this->increment_attempt($marker_dir, $id);
        if ($attempt === 1) {
            throw new \RuntimeException('intentional first-attempt failure for ' . $id);
        }

        $this->append_marker($marker_dir, 'success', [
            'id' => $id,
            'attempt' => $attempt,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);
    }

    public function always_fail(string $marker_dir, string $id): void
    {
        $attempt = $this->increment_attempt($marker_dir, $id);
        $this->append_marker($marker_dir, 'failure', [
            'id' => $id,
            'attempt' => $attempt,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);

        throw new \RuntimeException('intentional permanent failure for ' . $id . ' secret=should-be-redacted');
    }

    public function block_until_released(string $marker_dir, string $id, float $max_seconds = 5.0): void
    {
        $this->append_marker($marker_dir, 'running', [
            'id' => $id,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);

        $release_file = $marker_dir . DIRECTORY_SEPARATOR . 'release-' . $this->safe_name($id);
        $deadline = \microtime(true) + $max_seconds;
        while (!\is_file($release_file) && \microtime(true) < $deadline) {
            \usleep(50000);
        }

        $this->record_success($marker_dir, $id);
    }

    public function record_cancellation(string $marker_dir, string $id, array $job = [], mixed ...$extra): void
    {
        $this->append_marker($marker_dir, 'cancelled', [
            'id' => $id,
            'uuid' => $job['uuid'] ?? null,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);
    }

    public function memory_burn(string $marker_dir, string $id, int $megabytes = 64): void
    {
        $chunks = [];
        for ($i = 0; $i < $megabytes; $i++) {
            $chunks[] = \str_repeat('x', 1024 * 1024);
        }

        $this->record_success($marker_dir, $id);
    }

    private function increment_attempt(string $marker_dir, string $id): int
    {
        $this->ensure_marker_dir($marker_dir);
        $path = $marker_dir . DIRECTORY_SEPARATOR . 'attempt-' . $this->safe_name($id) . '.txt';
        $handle = \fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open attempt marker: ' . $path);
        }

        try {
            \flock($handle, LOCK_EX);
            $raw = \stream_get_contents($handle);
            $attempt = ((int)$raw) + 1;
            \ftruncate($handle, 0);
            \rewind($handle);
            \fwrite($handle, (string)$attempt);
            \fflush($handle);
            return $attempt;
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }
    }

    private function append_marker(string $marker_dir, string $type, array $data): void
    {
        $this->ensure_marker_dir($marker_dir);
        $path = $marker_dir . DIRECTORY_SEPARATOR . $type . '.jsonl';
        \file_put_contents($path, \json_encode($data, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function ensure_marker_dir(string $marker_dir): void
    {
        if (!\is_dir($marker_dir) && !@\mkdir($marker_dir, 0777, true) && !\is_dir($marker_dir)) {
            throw new \RuntimeException('Unable to create marker directory: ' . $marker_dir);
        }
    }

    private function safe_name(string $id): string
    {
        return \preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) ?: 'marker';
    }
}
