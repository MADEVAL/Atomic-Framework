<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use Engine\Atomic\Telemetry\Queue\EventType;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Queue\Support\QueueDriverTestHarness;
use Tests\Engine\Queue\Support\WorkerProcessHarness;

/**
 * @group worker-integration
 */
final class WorkerIntegrationTest extends TestCase
{
    use QueueDriverTestHarness;

    private ?WorkerProcessHarness $workers = null;
    private ?\Redis $redis = null;
    private ?string $redis_prefix = null;
    private bool $db_migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->require_worker_integration_support();
        $this->backup_queue_state();
        $this->workers = new WorkerProcessHarness();
    }

    protected function tearDown(): void
    {
        if ($this->workers) {
            $this->workers->stopAll();
            $this->workers->cleanupMarkers();
            $this->workers = null;
        }

        if ($this->db_migrated) {
            $this->migrate_queue_tables_down();
            $this->db_migrated = false;
        }

        if ($this->redis && $this->redis_prefix) {
            $this->cleanup_redis_prefix($this->redis, $this->redis_prefix);
            $this->redis->close();
        }
        $this->redis = null;
        $this->redis_prefix = null;

        $this->restore_queue_state();
        parent::tearDown();
    }

    public function test_redis_worker_consumes_one_job_and_records_lifecycle(): void
    {
        [$queue] = $this->configure_redis_queues(['worker_e2e_default']);
        $manager = new Manager($queue);

        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'record_success'],
            ['marker_dir' => $this->workers->markerDir(), 'id' => 'redis-single', 'queue' => $queue]
        ));

        $this->workers->startWorker($queue);

        $telemetry = new TelemetryManager();
        $job = $this->workers->waitUntil(
            fn (): mixed => $this->first_job_in_state($telemetry, $queue, State::COMPLETED->value),
            8.0,
            'Redis worker did not complete the queued job.'
        );

        $this->assertSame(1, $this->workers->markerCount('success'));
        $this->wait_for_lifecycle_events('redis', $telemetry, $queue, $job['uuid']);

        $this->workers->stopAll();
    }

    public function test_db_worker_consumes_one_job_and_records_lifecycle(): void
    {
        [$queue] = $this->configure_db_queues(['worker_e2e_default']);
        $manager = new Manager($queue);

        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'record_success'],
            ['marker_dir' => $this->workers->markerDir(), 'id' => 'db-single', 'queue' => $queue]
        ));

        $this->workers->startWorker($queue);

        $telemetry = new TelemetryManager();
        $job = $this->workers->waitUntil(
            fn (): mixed => $this->first_job_in_state($telemetry, $queue, State::COMPLETED->value),
            8.0,
            'DB worker did not complete the queued job.'
        );

        $this->assertSame(1, $this->workers->markerCount('success'));
        $this->wait_for_lifecycle_events('db', $telemetry, $queue, $job['uuid']);

        $this->workers->stopAll();
    }

    public function test_workers_only_consume_their_configured_redis_queue(): void
    {
        [$queueA, $queueB] = $this->configure_redis_queues(['worker_e2e_a', 'worker_e2e_b']);
        $this->push_marker_job($queueA, 'redis-a');
        $this->push_marker_job($queueB, 'redis-b');

        $this->workers->startWorker($queueA);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queueA, State::COMPLETED->value, 1);

        $this->assertSame(1, $this->state_count($telemetry, $queueB, State::PENDING->value));
        $this->assertSame(['redis-a'], $this->workers->uniqueMarkerIds('success'));

        $this->workers->startWorker($queueB);
        $this->wait_for_state_count($telemetry, $queueB, State::COMPLETED->value, 1);

        $ids = $this->workers->uniqueMarkerIds('success');
        \sort($ids);
        $this->assertSame(['redis-a', 'redis-b'], $ids);
    }

    public function test_workers_only_consume_their_configured_db_queue(): void
    {
        [$queueA, $queueB] = $this->configure_db_queues(['worker_e2e_a', 'worker_e2e_b']);
        $this->push_marker_job($queueA, 'db-a');
        $this->push_marker_job($queueB, 'db-b');

        $this->workers->startWorker($queueA);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queueA, State::COMPLETED->value, 1);

        $this->assertSame(1, $this->state_count($telemetry, $queueB, State::PENDING->value));
        $this->assertSame(['db-a'], $this->workers->uniqueMarkerIds('success'));

        $this->workers->startWorker($queueB);
        $this->wait_for_state_count($telemetry, $queueB, State::COMPLETED->value, 1);

        $ids = $this->workers->uniqueMarkerIds('success');
        \sort($ids);
        $this->assertSame(['db-a', 'db-b'], $ids);
    }

    public function test_redis_concurrent_workers_do_not_duplicate_claims(): void
    {
        [$queue] = $this->configure_redis_queues(['worker_e2e_race'], ['worker_cnt' => 4]);
        $this->push_many_marker_jobs($queue, 'redis-race-', 50);

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::COMPLETED->value, 50, 10.0);

        $ids = $this->workers->uniqueMarkerIds('success');
        $this->assertCount(50, $ids);
        $this->assertSame(50, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::RUNNING->value));
    }

    public function test_db_concurrent_workers_do_not_duplicate_claims(): void
    {
        [$queue] = $this->configure_db_queues(['worker_e2e_race'], ['worker_cnt' => 4]);
        $this->push_many_marker_jobs($queue, 'db-race-', 50);

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::COMPLETED->value, 50, 10.0);

        $ids = $this->workers->uniqueMarkerIds('success');
        $this->assertCount(50, $ids);
        $this->assertSame(50, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::RUNNING->value));
    }

    public function test_redis_retry_race_succeeds_once_after_first_failure(): void
    {
        [$queue] = $this->configure_redis_queues(['worker_e2e_retry'], ['worker_cnt' => 3, 'max_attempts' => 3]);
        for ($i = 0; $i < 10; $i++) {
            $this->push_handler_job($queue, 'fail_once_then_success', 'redis-retry-' . $i);
        }

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::COMPLETED->value, 10, 10.0);

        $this->assertSame(10, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::FAILED->value));
        foreach ($this->workers->markerRows('success') as $row) {
            $this->assertSame(2, (int)$row['attempt']);
        }
    }

    public function test_db_retry_race_succeeds_once_after_first_failure(): void
    {
        [$queue] = $this->configure_db_queues(['worker_e2e_retry'], ['worker_cnt' => 3, 'max_attempts' => 3]);
        for ($i = 0; $i < 10; $i++) {
            $this->push_handler_job($queue, 'fail_once_then_success', 'db-retry-' . $i);
        }

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::COMPLETED->value, 10, 10.0);

        $this->assertSame(10, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::FAILED->value));
        foreach ($this->workers->markerRows('success') as $row) {
            $this->assertSame(2, (int)$row['attempt']);
        }
    }

    public function test_redis_exhaustion_marks_failed_without_active_copy(): void
    {
        [$queue] = $this->configure_redis_queues(['worker_e2e_exhaust'], ['max_attempts' => 2]);
        $this->push_handler_job($queue, 'always_fail', 'redis-exhaust');

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::FAILED->value, 1, 8.0);

        $failed = $telemetry->fetch_failed_jobs($queue);
        $job = \array_values($failed['items'])[0];
        $this->assertSame(2, (int)$job['attempts']);
        $this->assertIsArray($job['exception']);
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::RUNNING->value));
    }

    public function test_db_exhaustion_marks_failed_without_active_copy(): void
    {
        [$queue] = $this->configure_db_queues(['worker_e2e_exhaust'], ['max_attempts' => 2]);
        $this->push_handler_job($queue, 'always_fail', 'db-exhaust');

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::FAILED->value, 1, 8.0);

        $failed = $telemetry->fetch_failed_jobs($queue);
        $job = \array_values($failed['items'])[0];
        $this->assertSame(2, (int)$job['attempts']);
        $this->assertIsArray($job['exception']);
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::RUNNING->value));
    }

    public function test_redis_running_job_can_be_cancelled_by_worker_signal(): void
    {
        [$queue] = $this->configure_redis_queues(['worker_e2e_cancel'], ['timeout' => 5]);
        $uuid = $this->new_uuid();
        $manager = new Manager($queue);
        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'slow_success'],
            ['marker_dir' => $this->workers->markerDir(), 'id' => 'redis-cancel', 'seconds' => 4.0],
            ['cancel_handler' => [QueueTestHandler::class, 'record_cancellation']],
            $uuid
        ));

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::RUNNING->value, 1, 5.0);

        $this->assertTrue($manager->cancel($uuid));
        $this->workers->waitUntil(
            fn (): bool => $this->state_count($telemetry, $queue, State::CANCEL_REQUESTED->value) === 1
                || $this->state_count($telemetry, $queue, State::CANCELLED->value) === 1,
            3.0,
            "Queue {$queue} did not reach cancel_requested or cancelled state."
        );
        $this->wait_for_state_count($telemetry, $queue, State::CANCELLED->value, 1, 6.0);

        $this->assertSame(1, $this->workers->markerCount('cancelled'));
        $this->assertSame(0, $this->state_count($telemetry, $queue, State::COMPLETED->value));
        $this->assertFalse($this->redis->zScore($this->redis_prefix . $queue . '.idx.completed', $uuid));
    }

    public function test_redis_graceful_shutdown_finishes_current_job_and_starts_no_new_job(): void
    {
        [$queue] = $this->configure_redis_queues(['worker_e2e_shutdown'], ['worker_cnt' => 1, 'timeout' => 5]);
        $this->push_handler_job($queue, 'block_until_released', 'redis-shutdown-current');
        $this->push_marker_job($queue, 'redis-shutdown-next');

        $master = $this->workers->startWorker($queue);
        $this->workers->waitUntil(fn (): bool => $this->workers->markerCount('running') === 1, 5.0, 'Redis shutdown job did not start.');
        $this->workers->signal($master, SIGTERM);
        $this->workers->release('redis-shutdown-current');
        $this->workers->assertMasterExited($master, 8.0);

        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::COMPLETED->value, 1, 3.0);
        $this->assertSame(1, $this->state_count($telemetry, $queue, State::COMPLETED->value));
        $this->assertSame(1, $this->state_count($telemetry, $queue, State::PENDING->value));
        $this->assertSame(['redis-shutdown-current'], $this->workers->uniqueMarkerIds('success'));
    }

    public function test_db_graceful_shutdown_finishes_current_job_and_starts_no_new_job(): void
    {
        [$queue] = $this->configure_db_queues(['worker_e2e_shutdown'], ['worker_cnt' => 1, 'timeout' => 5]);
        $this->push_handler_job($queue, 'block_until_released', 'db-shutdown-current');
        $this->push_marker_job($queue, 'db-shutdown-next');

        $master = $this->workers->startWorker($queue);
        $this->workers->waitUntil(fn (): bool => $this->workers->markerCount('running') === 1, 5.0, 'DB shutdown job did not start.');
        $this->workers->signal($master, SIGTERM);
        $this->workers->release('db-shutdown-current');
        $this->workers->assertMasterExited($master, 8.0);

        $telemetry = new TelemetryManager();
        $this->wait_for_state_count($telemetry, $queue, State::COMPLETED->value, 1, 3.0);
        $this->assertSame(1, $this->state_count($telemetry, $queue, State::COMPLETED->value));
        $this->assertSame(1, $this->state_count($telemetry, $queue, State::PENDING->value));
        $this->assertSame(['db-shutdown-current'], $this->workers->uniqueMarkerIds('success'));
    }

    private function require_worker_integration_support(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for worker integration tests.');
        }
    }

    private function configure_redis_queues(array $queues, array $overrides = []): array
    {
        [$redis, $host] = $this->connect_redis_or_skip();
        $this->redis = $redis;
        $this->redis_prefix = 'atomic_worker_it_' . \bin2hex(\random_bytes(4)) . ':';
        $this->cleanup_redis_prefix($redis, $this->redis_prefix);

        $this->configure_redis_connection($redis, $host, $this->redis_prefix);
        $this->configure_queues('redis', $queues, $overrides);
        return $queues;
    }

    private function configure_db_queues(array $queues, array $overrides = []): array
    {
        [, $host] = $this->connect_pdo_or_skip();
        $prefix = 'atomic_worker_it_' . \bin2hex(\random_bytes(4)) . '_';
        $this->configure_db_for_queue($host, $prefix, $queues[0], $overrides);
        $this->configure_queues('db', $queues, $overrides);
        $this->migrate_queue_tables_up();
        $this->db_migrated = true;
        return $queues;
    }

    private function configure_queues(string $driver, array $queues, array $overrides = []): void
    {
        $defaults = [
            'delay' => 0,
            'priority' => 5,
            'timeout' => 3,
            'max_attempts' => 3,
            'retry_delay' => 0,
            'worker_cnt' => 1,
            'ttl' => 60,
            'memory_limit_mb' => 128,
        ];
        $configured = [];
        foreach ($queues as $queue) {
            $configured[$queue] = \array_merge($defaults, $overrides);
        }

        App::instance()->set('QUEUE_DRIVER', $driver);
        App::instance()->set('QUEUE_NAME', $queues[0]);
        App::instance()->set('QUEUE', [
            'db' => ['queues' => $configured],
            'redis' => ['queues' => $configured],
        ]);
    }

    private function push_marker_job(string $queue, string $id): void
    {
        $this->push_handler_job($queue, 'record_success', $id, ['queue' => $queue]);
    }

    private function push_many_marker_jobs(string $queue, string $prefix, int $count): void
    {
        $manager = new Manager($queue);
        for ($i = 0; $i < $count; $i++) {
            $this->push_handler_job_with_manager($manager, 'record_success', $prefix . $i, ['queue' => $queue]);
        }
    }

    private function push_handler_job(string $queue, string $handler, string $id, array $extraData = []): void
    {
        $manager = new Manager($queue);
        $this->push_handler_job_with_manager($manager, $handler, $id, $extraData);
    }

    private function push_handler_job_with_manager(Manager $manager, string $handler, string $id, array $extraData = []): void
    {
        $data = \array_merge(['marker_dir' => $this->workers->markerDir(), 'id' => $id], $extraData);
        $this->assertTrue($manager->push([QueueTestHandler::class, $handler], $data));
    }

    private function first_job_in_state(TelemetryManager $telemetry, string $queue, string $state): ?array
    {
        $jobs = $telemetry->fetch_all_jobs($queue, ['state' => $state]);
        if (($jobs['total'] ?? 0) < 1) {
            return null;
        }
        return \array_values($jobs['items'])[0];
    }

    private function wait_for_state_count(TelemetryManager $telemetry, string $queue, string $state, int $count, float $deadline = 8.0): void
    {
        $this->workers->waitUntil(
            fn (): bool => $this->state_count($telemetry, $queue, $state) === $count,
            $deadline,
            "Queue {$queue} did not reach {$count} {$state} job(s)."
        );
    }

    private function wait_for_lifecycle_events(string $driver, TelemetryManager $telemetry, string $queue, string $uuid): void
    {
        $required = [
            EventType::JOB_CREATED->value,
            EventType::JOB_FETCHED->value,
            EventType::JOB_SUCCESS->value,
        ];

        $this->workers->waitUntil(
            function () use ($driver, $telemetry, $queue, $uuid, $required): bool {
                $types = \array_map('intval', \array_column($telemetry->fetch_events($driver, $queue, $uuid), 'event_type_id'));
                return \count(\array_intersect($required, $types)) === \count($required);
            },
            8.0,
            "Queue {$queue} did not record the expected lifecycle telemetry for job {$uuid}."
        );
    }

    private function state_count(TelemetryManager $telemetry, string $queue, string $state): int
    {
        return (int)($telemetry->fetch_all_jobs($queue, ['state' => $state])['total'] ?? 0);
    }
}
