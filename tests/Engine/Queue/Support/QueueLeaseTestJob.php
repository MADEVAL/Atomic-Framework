<?php
declare(strict_types=1);

namespace Tests\Engine\Queue\Support;

use Engine\Atomic\Queue\Managers\Manager;

final class QueueLeaseTestJob
{
    public function renew_lease(string $marker_dir, string $id, float $seconds = 7.0): void
    {
        $this->append_marker($marker_dir, 'running', [
            'id' => $id,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);

        $queue = new Manager();
        $deadline = \microtime(true) + $seconds;
        while (\microtime(true) < $deadline) {
            if (!$queue->renew_lease()) {
                throw new \RuntimeException('Queue lease renewal failed.');
            }
            \usleep(250_000);
        }

        $this->append_marker($marker_dir, 'success', [
            'id' => $id,
            'pid' => \getmypid(),
            'time' => \microtime(true),
        ]);
    }

    private function append_marker(string $marker_dir, string $type, array $data): void
    {
        if (!\is_dir($marker_dir) && !\mkdir($marker_dir, 0777, true) && !\is_dir($marker_dir)) {
            throw new \RuntimeException('Unable to create marker directory: ' . $marker_dir);
        }

        \file_put_contents(
            $marker_dir . DIRECTORY_SEPARATOR . $type . '.jsonl',
            \json_encode($data, JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
