<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\CLI\Queue as QueueCliTrait;
use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use PHPUnit\Framework\TestCase;

final class QueueCliTest extends TestCase
{
    private array $originalState = [];

    protected function setUp(): void
    {
        parent::setUp();
        $atomic = App::instance();
        $this->originalState = [
            'QUEUE_DRIVER' => $atomic->get('QUEUE_DRIVER'),
            'QUEUE_NAME' => $atomic->get('QUEUE_NAME'),
            'QUEUE' => $atomic->get('QUEUE'),
        ];

        $atomic->set('QUEUE_DRIVER', 'redis');
        $atomic->set('QUEUE_NAME', 'cli_test');
        $atomic->set('QUEUE', [
            'redis' => [
                'queues' => [
                    'cli_test' => [
                        'delay' => 0,
                        'priority' => 5,
                        'timeout' => 10,
                        'max_attempts' => 3,
                        'retry_delay' => 0,
                        'worker_cnt' => 1,
                        'ttl' => 60,
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
        parent::tearDown();
    }

    public function test_queue_worker_requires_queue_name(): void
    {
        $cli = new QueueCliHarness([]);

        $cli->queue_worker();

        $this->assertSame(['Usage: php atomic queue/worker <queue_name>'], $cli->output->errors);
    }

    public function test_queue_test_requires_type(): void
    {
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness([], $manager);

        $cli->queue_test();

        $this->assertSame(['Usage: php atomic queue/test <success|failed|timeout|cancel_requested|cancelled|all> [queue_name]'], $cli->output->errors);
        $this->assertSame([], $manager->pushCalls);
    }

    public function test_queue_test_success_pushes_success_job(): void
    {
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness(['success', 'emails'], $manager);

        $cli->queue_test();

        $this->assertSame(['emails'], $manager->factoryQueues);
        $this->assertCount(1, $manager->pushCalls);
        $this->assertSame([QueueTestHandler::class, 'success'], $manager->pushCalls[0]['payload']);
        $this->assertSame('queue-test-success', $manager->pushCalls[0]['data']['smth']);
        $this->assertSame([], $manager->pushCalls[0]['options']);
        $this->assertStringContainsString('Queued success test job', $cli->output->lines[0]);
        $this->assertSame('queue/test completed. Created: 1, Failed: 0', $cli->output->lines[1]);
        $this->assertSame('Run: php atomic queue/worker fake', $cli->output->lines[2]);
    }

    public function test_queue_test_all_pushes_supported_jobs(): void
    {
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness(['all'], $manager);

        $cli->queue_test();

        $this->assertCount(5, $manager->pushCalls);
        $this->assertSame('success', $manager->pushCalls[0]['payload'][1]);
        $this->assertSame('failure', $manager->pushCalls[1]['payload'][1]);
        $this->assertSame('timeout', $manager->pushCalls[2]['payload'][1]);
        $this->assertSame('self_cancel', $manager->pushCalls[3]['payload'][1]);
        $this->assertSame('self_request_cancel', $manager->pushCalls[4]['payload'][1]);
        $this->assertSame(['max_attempts' => 1, 'retry_delay' => 0], $manager->pushCalls[1]['options']);
        $this->assertSame(['timeout' => 1, 'max_attempts' => 1, 'retry_delay' => 0], $manager->pushCalls[2]['options']);
        $this->assertSame(['max_attempts' => 1, 'retry_delay' => 0], $manager->pushCalls[3]['options']);
        $this->assertSame('cancelled', $manager->pushCalls[4]['options']['cancel_handler']);
        $this->assertContains('queue/test completed. Created: 5, Failed: 0', $cli->output->lines);
    }

    public function test_queue_test_reports_unknown_type(): void
    {
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness(['mystery'], $manager);

        $cli->queue_test();

        $this->assertSame(["Unknown queue test type 'mystery'. Supported: success, failed, timeout, cancel_requested, cancelled, all"], $cli->output->errors);
        $this->assertSame('queue/test completed. Created: 0, Failed: 1', $cli->output->lines[0]);
    }

    public function test_queue_retry_without_args_retries_default_queue(): void
    {
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness([], $manager);

        $cli->queue_retry();

        $this->assertSame([null], $manager->factoryQueues);
        $this->assertSame(1, $manager->retryCalls);
        $this->assertSame(['Retried failed jobs'], $cli->output->lines);
        $this->assertSame([], $cli->output->errors);
    }

    public function test_queue_retry_with_uuid_uses_retry_by_uuid(): void
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness([$uuid], $manager);

        $cli->queue_retry();

        $this->assertSame([$uuid], $manager->retryByUuidCalls);
        $this->assertSame(["Successfully retried failed job with UUID '{$uuid}'"], $cli->output->lines);
    }

    public function test_queue_retry_with_queue_name_builds_named_manager(): void
    {
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness(['emails'], $manager);

        $cli->queue_retry();

        $this->assertSame([null, 'emails'], $manager->factoryQueues);
        $this->assertSame(1, $manager->retryCalls);
        $this->assertSame(["Retried failed jobs for queue 'emails'"], $cli->output->lines);
    }

    public function test_queue_delete_requires_uuid_and_reports_running_job(): void
    {
        $manager = new QueueCliFakeManager();
        $manager->deleteResult = false;

        $missing = new QueueCliHarness([], $manager);
        $missing->queue_delete_job();
        $this->assertSame(['Usage: php atomic queue/delete <job_uuid>'], $missing->output->errors);

        $uuid = '123e4567-e89b-42d3-a456-426614174001';
        $cli = new QueueCliHarness([$uuid], $manager);
        $cli->queue_delete_job();

        $this->assertSame([$uuid], $manager->deleteCalls);
        $this->assertSame(["Could not delete job with UUID '{$uuid}' - it may not exist or it may be currently running"], $cli->output->errors);
    }

    public function test_queue_retry_with_uuid_reports_false_result(): void
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174111';
        $manager = new QueueCliFakeManager();
        $manager->retryByUuidResult = false;
        $cli = new QueueCliHarness([$uuid], $manager);

        $cli->queue_retry();

        $this->assertSame([$uuid], $manager->retryByUuidCalls);
        $this->assertSame(["Could not retry failed job with UUID '{$uuid}' - it may not exist"], $cli->output->errors);
    }

    public function test_queue_delete_reports_success(): void
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174112';
        $manager = new QueueCliFakeManager();
        $cli = new QueueCliHarness([$uuid], $manager);

        $cli->queue_delete_job();

        $this->assertSame([$uuid], $manager->deleteCalls);
        $this->assertSame(["Successfully deleted job with UUID '{$uuid}'"], $cli->output->lines);
        $this->assertSame([], $cli->output->errors);
    }

    public function test_queue_cancel_requires_uuid_and_reports_success(): void
    {
        $manager = new QueueCliFakeManager();

        $missing = new QueueCliHarness([], $manager);
        $missing->queue_cancel();
        $this->assertSame(['Usage: php atomic queue/cancel <job_uuid>'], $missing->output->errors);

        $uuid = '123e4567-e89b-42d3-a456-426614174113';
        $cli = new QueueCliHarness([$uuid], $manager);
        $cli->queue_cancel();

        $this->assertSame([$uuid], $manager->cancelCalls);
        $this->assertSame(["Cancellation requested for job with UUID '{$uuid}'"], $cli->output->lines);
    }

    public function test_queue_retry_reports_manager_creation_failure(): void
    {
        App::instance()->set('QUEUE_DRIVER', 'unsupported');
        $cli = new QueueCliHarness(['emails']);

        $cli->queue_retry();

        $this->assertNotEmpty($cli->output->errors);
        $this->assertStringContainsString('unsupported QUEUE_DRIVER', $cli->output->errors[0]);
    }

    public function test_queue_test_monitor_rejects_unsupported_driver(): void
    {
        App::instance()->set('QUEUE_DRIVER', 'memory');
        $cli = new QueueCliHarness([]);

        $cli->queue_test_monitor();

        $this->assertSame(["queue/test/monitor is not supported for queue driver 'memory'"], $cli->output->errors);
    }

    public function test_dependency_hint_mentions_driver_specific_requirements(): void
    {
        $cli = new QueueCliHarness([]);

        App::instance()->set('QUEUE_DRIVER', 'redis');
        $this->assertStringContainsString("Queue driver 'redis' is unavailable", $cli->hint());

        App::instance()->set('QUEUE_DRIVER', 'db');
        $this->assertStringContainsString("Queue driver 'db' is unavailable", $cli->hint());

        App::instance()->set('QUEUE_DRIVER', 'memory');
        $this->assertSame("Queue system is unavailable: unsupported QUEUE_DRIVER 'memory'.", $cli->hint());
    }
}

final class QueueCliHarness
{
    use QueueCliTrait {
        create_queue_manager_or_null as private traitCreateQueueManagerOrNull;
    }

    public QueueCliOutputFake $output;

    public function __construct(
        private array $args = [],
        private ?QueueCliFakeManager $manager = null,
    ) {
        $this->output = new QueueCliOutputFake();
    }

    public function get_cli_args(): array
    {
        return $this->args;
    }

    public function hint(): string
    {
        return $this->queue_dependency_hint();
    }

    protected function create_queue_manager_or_null(?string $queue_name = null): ?Manager
    {
        if ($this->manager === null) {
            return $this->traitCreateQueueManagerOrNull($queue_name);
        }

        $this->manager->factoryQueues[] = $queue_name;
        return $this->manager;
    }
}

final class QueueCliOutputFake
{
    public array $lines = [];
    public array $errors = [];

    public function writeln(string $message): void
    {
        $this->lines[] = $message;
    }

    public function err(string $message): void
    {
        $this->errors[] = $message;
    }
}

final class QueueCliFakeManager extends Manager
{
    public array $factoryQueues = [];
    public array $pushCalls = [];
    public array $cancelCalls = [];
    public int $retryCalls = 0;
    public array $retryByUuidCalls = [];
    public array $deleteCalls = [];
    public bool $pushResult = true;
    public bool $cancelResult = true;
    public bool $retryByUuidResult = true;
    public bool $deleteResult = true;

    public function __construct()
    {
    }

    public function retry(): void
    {
        $this->retryCalls++;
    }

    public function get_queue(): string
    {
        return 'fake';
    }

    public function supports_cancel(): bool
    {
        return true;
    }

    public function push(array $payload, array $data = [], array $options = [], string $uuid = ''): bool
    {
        $this->pushCalls[] = [
            'payload' => $payload,
            'data' => $data,
            'options' => $options,
            'uuid' => $uuid,
        ];
        return $this->pushResult;
    }

    public function cancel(string $uuid): bool
    {
        $this->cancelCalls[] = $uuid;
        return $this->cancelResult;
    }

    public function retry_by_uuid(string $uuid): bool
    {
        $this->retryByUuidCalls[] = $uuid;
        return $this->retryByUuidResult;
    }

    public function delete_job(string $uuid): bool
    {
        $this->deleteCalls[] = $uuid;
        return $this->deleteResult;
    }
}
