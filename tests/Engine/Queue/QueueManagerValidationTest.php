<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Queue\Support\QueueDriverTestHarness;

final class QueueManagerValidationTest extends TestCase
{
    use QueueDriverTestHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupQueueState();
        $this->configureQueue('db', $this->newQueueName());
    }

    protected function tearDown(): void
    {
        ConnectionManager::instance()->close();
        $this->restoreQueueState();
        parent::tearDown();
    }

    public function test_unknown_queue_driver_throws(): void
    {
        App::instance()->set('QUEUE_DRIVER', 'missing');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown queue driver: missing');
        new Manager();
    }

    public function test_unknown_queue_name_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Queue absent is not configured');
        new Manager('absent');
    }

    public function test_invalid_handler_shape_throws_before_push_driver_call(): void
    {
        $manager = new Manager();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid handler format');
        $manager->push([QueueTestHandler::class], []);
    }

    public function test_missing_class_and_method_validation_throw_clear_errors(): void
    {
        $manager = new Manager();

        try {
            $manager->push(['Tests\Engine\Queue\MissingHandler', 'success'], []);
            $this->fail('Expected missing class exception.');
        } catch (\Exception $e) {
            $this->assertSame("Class 'Tests\\Engine\\Queue\\MissingHandler' not found.", $e->getMessage());
        }

        try {
            $manager->push([QueueTestHandler::class, 'missing-method'], []);
            $this->fail('Expected invalid method name exception.');
        } catch (\Exception $e) {
            $this->assertSame("Invalid method name 'missing-method'.", $e->getMessage());
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Method 'missingMethod' not found");
        $manager->push([QueueTestHandler::class, 'missingMethod'], []);
    }

    public function test_non_public_handlers_are_rejected_before_enqueue_and_runtime(): void
    {
        $manager = new Manager();

        try {
            $manager->push([QueuePrivateHandlerJob::class, 'hidden'], []);
            $this->fail('Expected non-public enqueue handler exception.');
        } catch (\Exception $e) {
            $this->assertSame("Method 'hidden' in class '" . QueuePrivateHandlerJob::class . "' is not public.", $e->getMessage());
        }

        $runtime = new QueueProcessOnlyManager();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Method hidden in class ' . QueuePrivateHandlerJob::class . ' is not public');
        $runtime->process_job([
            'payload' => [
                'handler' => QueuePrivateHandlerJob::class . '@hidden',
                'data' => [],
            ],
        ]);
    }

    public function test_invalid_option_values_throw_clear_errors(): void
    {
        $manager = new Manager();

        foreach (
            [
                'delay' => -1,
                'timeout' => 0,
                'max_attempts' => 0,
                'retry_delay' => -1,
                'ttl' => -1,
            ] as $option => $value
        ) {
            try {
                $manager->push([QueueTestHandler::class, 'success'], [], [$option => $value]);
                $this->fail("Expected invalid {$option} exception.");
            } catch (\Exception $e) {
                $this->assertSame("Invalid queue option {$option}. Expected integer >= " . ($option === 'timeout' || $option === 'max_attempts' ? '1' : '0') . '.', $e->getMessage());
            }
        }
    }

    public function test_invalid_cancel_handler_shape_throws(): void
    {
        $manager = new Manager();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid cancel_handler format');
        $manager->push([QueueTestHandler::class, 'success'], [], ['cancel_handler' => [QueueTestHandler::class]]);
    }

    public function test_string_cancel_handler_requires_queued_handler_class(): void
    {
        $manager = new QueueValidationHarnessManager();

        $method = new \ReflectionMethod(Manager::class, 'validate_cancel_handler');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('String cancel_handler requires queued handler class.');
        $method->invoke($manager, 'record_cancellation', null);
    }

    public function test_string_cancel_handler_validates_against_payload_class(): void
    {
        $manager = new QueueValidationHarnessManager();

        $method = new \ReflectionMethod(Manager::class, 'validate_cancel_handler');
        $method->invoke($manager, 'record_cancellation', QueueTestHandler::class);

        $this->addToAssertionCount(1);
    }

    public function test_missing_required_queue_option_throws(): void
    {
        $queue = $this->newQueueName();
        $this->configureQueue('db', $queue);
        $config = App::instance()->get('QUEUE');
        unset($config['db']['queues'][$queue]['ttl']);
        App::instance()->set('QUEUE', $config);

        $manager = new Manager($queue);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Required option parameter not set: ttl');
        $manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'missing']);
    }

    public function test_process_job_orders_named_parameters_and_uses_defaults(): void
    {
        QueueNamedParamJob::reset();
        $manager = new QueueProcessOnlyManager();

        $manager->process_job([
            'payload' => [
                'handler' => QueueNamedParamJob::class . '@record',
                'data' => ['second' => 'two', 'first' => 'one'],
            ],
        ]);

        $this->assertSame([['one', 'two', 'fallback']], QueueNamedParamJob::$calls);
    }

    public function test_process_job_rejects_missing_required_named_parameter(): void
    {
        $manager = new QueueProcessOnlyManager();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Required job handler parameter 'second' not provided");
        $manager->process_job([
            'payload' => [
                'handler' => QueueNamedParamJob::class . '@record',
                'data' => ['first' => 'one'],
            ],
        ]);
    }

    public function test_process_job_rejects_missing_handler_and_missing_method_separator(): void
    {
        $manager = new QueueProcessOnlyManager();

        try {
            $manager->process_job(['payload' => []]);
            $this->fail('Expected missing handler exception.');
        } catch (\Exception $e) {
            $this->assertSame('Handler not specified', $e->getMessage());
        }

        $this->expectException(\Throwable::class);
        $manager->process_job([
            'payload' => [
                'handler' => QueueNamedParamJob::class,
                'data' => [],
            ],
        ]);
    }

    public function test_process_job_rejects_missing_class_and_method(): void
    {
        $manager = new QueueProcessOnlyManager();

        try {
            $manager->process_job([
                'payload' => [
                    'handler' => 'Tests\\Engine\\Queue\\MissingRuntimeHandler@run',
                    'data' => [],
                ],
            ]);
            $this->fail('Expected missing class exception.');
        } catch (\Exception $e) {
            $this->assertSame('Class Tests\\Engine\\Queue\\MissingRuntimeHandler not found', $e->getMessage());
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Method missing not found in class');
        $manager->process_job([
            'payload' => [
                'handler' => QueueNamedParamJob::class . '@missing',
                'data' => [],
            ],
        ]);
    }

    public function test_db_manager_cancel_methods_throw_unsupported_exception(): void
    {
        $manager = new Manager();

        foreach (
            [
                fn() => $manager->cancel($this->newUuid()),
                fn() => $manager->mark_cancelled(['uuid' => $this->newUuid(), 'queue' => 'default', 'payload' => []]),
                fn() => $manager->is_cancel_requested($this->newUuid()),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Expected unsupported DB cancellation exception.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Queue cancellation is not supported for the database queue driver', $e->getMessage());
            }
        }
    }
}

final class QueueProcessOnlyManager extends Manager
{
    public function __construct()
    {
    }
}

final class QueueValidationHarnessManager extends Manager
{
    public function __construct()
    {
    }
}

final class QueueNamedParamJob
{
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function record(string $first, string $second, string $third = 'fallback'): void
    {
        self::$calls[] = [$first, $second, $third];
    }
}

final class QueuePrivateHandlerJob
{
    private function hidden(): void
    {
    }
}
