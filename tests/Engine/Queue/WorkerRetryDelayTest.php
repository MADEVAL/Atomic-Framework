<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Worker\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Retry delay must grow exponentially with the attempt number and be capped
 * (П-9). The probe runs without pcntl so it works on every platform.
 */
final class WorkerRetryDelayTest extends TestCase
{
    private array $originalState = [];

    protected function setUp(): void
    {
        $atomic = App::instance();
        $this->originalState = [
            'QUEUE_DRIVER' => $atomic->get('QUEUE_DRIVER'),
            'QUEUE_NAME' => $atomic->get('QUEUE_NAME'),
            'QUEUE' => $atomic->get('QUEUE'),
        ];

        $atomic->set('QUEUE_DRIVER', 'db');
        $atomic->set('QUEUE_NAME', 'retry_probe');
        $atomic->set('QUEUE', [
            'db' => [
                'queues' => [
                    'retry_probe' => [
                        'worker_cnt' => 1,
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalState as $key => $value) {
            App::instance()->set($key, $value);
        }
    }

    private function probeWorker(): RetryDelayProbeWorker
    {
        return new RetryDelayProbeWorker(new class() extends Manager {
            public function close_all_connections(): void
            {
            }
        });
    }

    public function test_retry_delay_grows_exponentially_with_attempts(): void
    {
        $worker = $this->probeWorker();

        $this->assertSame(10, $worker->probe(['retry_delay' => 10, 'attempts' => 1]));
        $this->assertSame(20, $worker->probe(['retry_delay' => 10, 'attempts' => 2]));
        $this->assertSame(40, $worker->probe(['retry_delay' => 10, 'attempts' => 3]));
        $this->assertSame(80, $worker->probe(['retry_delay' => 10, 'attempts' => 4]));
    }

    public function test_retry_delay_is_capped_at_one_hour(): void
    {
        $worker = $this->probeWorker();

        $this->assertSame(3600, $worker->probe(['retry_delay' => 10, 'attempts' => 10]));
        $this->assertSame(3600, $worker->probe(['retry_delay' => 3600, 'attempts' => 2]));
    }

    public function test_first_attempt_uses_base_delay_when_attempts_missing(): void
    {
        $worker = $this->probeWorker();

        $this->assertSame(15, $worker->probe(['retry_delay' => 15]));
    }
}

final class RetryDelayProbeWorker extends Worker
{
    public function probe(array $job): int
    {
        return $this->retry_delay_for($job);
    }
}
