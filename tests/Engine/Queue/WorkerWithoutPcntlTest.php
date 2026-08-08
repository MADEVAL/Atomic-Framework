<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Worker\Worker;
use PHPUnit\Framework\TestCase;

/**
 * On platforms without the pcntl extension (e.g. Windows) the worker must fail
 * with a catchable, descriptive RuntimeException instead of a fatal error.
 */
final class WorkerWithoutPcntlTest extends TestCase
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
        $atomic->set('QUEUE_NAME', 'nopcntl');
        $atomic->set('QUEUE', [
            'db' => [
                'queues' => [
                    'nopcntl' => [
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

    private function fakeManager(): Manager
    {
        return new class() extends Manager {
            public function close_all_connections(): void
            {
            }
        };
    }

    public function test_run_throws_runtime_exception_when_pcntl_unavailable(): void
    {
        if (function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl is available — this test targets platforms without it.');
        }

        $worker = new Worker($this->fakeManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pcntl');

        $worker->run();
    }

    public function test_worker_can_be_constructed_without_pcntl(): void
    {
        $worker = new Worker($this->fakeManager());

        $this->assertNotNull($worker);
    }
}
