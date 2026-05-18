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
    private ?string $redisPrefix = null;
    private bool $dbMigrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireWorkerIntegrationSupport();
        $this->backupQueueState();
        $this->workers = new WorkerProcessHarness();
    }

    protected function tearDown(): void
    {
        if ($this->workers) {
            $this->workers->stopAll();
            $this->workers->cleanupMarkers();
            $this->workers = null;
        }

        if ($this->dbMigrated) {
            $this->migrateQueueTablesDown();
            $this->dbMigrated = false;
        }

        if ($this->redis && $this->redisPrefix) {
            $this->cleanupRedisPrefix($this->redis, $this->redisPrefix);
            $this->redis->close();
        }
        $this->redis = null;
        $this->redisPrefix = null;

        $this->restoreQueueState();
        parent::tearDown();
    }

    public function test_redis_worker_consumes_one_job_and_records_lifecycle(): void
    {
        [$queue] = $this->configureRedisQueues(['worker_e2e_default']);
        $manager = new Manager($queue);

        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'record_success'],
            ['marker_dir' => $this->workers->markerDir(), 'id' => 'redis-single', 'queue' => $queue]
        ));

        $this->workers->startWorker($queue);

        $telemetry = new TelemetryManager();
        $job = $this->workers->waitUntil(
            fn (): mixed => $this->firstJobInState($telemetry, $queue, State::COMPLETED->value),
            8.0,
            'Redis worker did not complete the queued job.'
        );

        $this->assertSame(1, $this->workers->markerCount('success'));
        $this->waitForLifecycleEvents('redis', $telemetry, $queue, $job['uuid']);

        $this->workers->stopAll();
    }

    public function test_db_worker_consumes_one_job_and_records_lifecycle(): void
    {
        [$queue] = $this->configureDbQueues(['worker_e2e_default']);
        $manager = new Manager($queue);

        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'record_success'],
            ['marker_dir' => $this->workers->markerDir(), 'id' => 'db-single', 'queue' => $queue]
        ));

        $this->workers->startWorker($queue);

        $telemetry = new TelemetryManager();
        $job = $this->workers->waitUntil(
            fn (): mixed => $this->firstJobInState($telemetry, $queue, State::COMPLETED->value),
            8.0,
            'DB worker did not complete the queued job.'
        );

        $this->assertSame(1, $this->workers->markerCount('success'));
        $this->waitForLifecycleEvents('db', $telemetry, $queue, $job['uuid']);

        $this->workers->stopAll();
    }

    public function test_workers_only_consume_their_configured_redis_queue(): void
    {
        [$queueA, $queueB] = $this->configureRedisQueues(['worker_e2e_a', 'worker_e2e_b']);
        $this->pushMarkerJob($queueA, 'redis-a');
        $this->pushMarkerJob($queueB, 'redis-b');

        $this->workers->startWorker($queueA);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queueA, State::COMPLETED->value, 1);

        $this->assertSame(1, $this->stateCount($telemetry, $queueB, State::PENDING->value));
        $this->assertSame(['redis-a'], $this->workers->uniqueMarkerIds('success'));

        $this->workers->startWorker($queueB);
        $this->waitForStateCount($telemetry, $queueB, State::COMPLETED->value, 1);

        $ids = $this->workers->uniqueMarkerIds('success');
        \sort($ids);
        $this->assertSame(['redis-a', 'redis-b'], $ids);
    }

    public function test_workers_only_consume_their_configured_db_queue(): void
    {
        [$queueA, $queueB] = $this->configureDbQueues(['worker_e2e_a', 'worker_e2e_b']);
        $this->pushMarkerJob($queueA, 'db-a');
        $this->pushMarkerJob($queueB, 'db-b');

        $this->workers->startWorker($queueA);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queueA, State::COMPLETED->value, 1);

        $this->assertSame(1, $this->stateCount($telemetry, $queueB, State::PENDING->value));
        $this->assertSame(['db-a'], $this->workers->uniqueMarkerIds('success'));

        $this->workers->startWorker($queueB);
        $this->waitForStateCount($telemetry, $queueB, State::COMPLETED->value, 1);

        $ids = $this->workers->uniqueMarkerIds('success');
        \sort($ids);
        $this->assertSame(['db-a', 'db-b'], $ids);
    }

    public function test_redis_concurrent_workers_do_not_duplicate_claims(): void
    {
        [$queue] = $this->configureRedisQueues(['worker_e2e_race'], ['worker_cnt' => 4]);
        $this->pushManyMarkerJobs($queue, 'redis-race-', 50);

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::COMPLETED->value, 50, 10.0);

        $ids = $this->workers->uniqueMarkerIds('success');
        $this->assertCount(50, $ids);
        $this->assertSame(50, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::RUNNING->value));
    }

    public function test_db_concurrent_workers_do_not_duplicate_claims(): void
    {
        [$queue] = $this->configureDbQueues(['worker_e2e_race'], ['worker_cnt' => 4]);
        $this->pushManyMarkerJobs($queue, 'db-race-', 50);

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::COMPLETED->value, 50, 10.0);

        $ids = $this->workers->uniqueMarkerIds('success');
        $this->assertCount(50, $ids);
        $this->assertSame(50, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::RUNNING->value));
    }

    public function test_redis_retry_race_succeeds_once_after_first_failure(): void
    {
        [$queue] = $this->configureRedisQueues(['worker_e2e_retry'], ['worker_cnt' => 3, 'max_attempts' => 3]);
        for ($i = 0; $i < 10; $i++) {
            $this->pushHandlerJob($queue, 'fail_once_then_success', 'redis-retry-' . $i);
        }

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::COMPLETED->value, 10, 10.0);

        $this->assertSame(10, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::FAILED->value));
        foreach ($this->workers->markerRows('success') as $row) {
            $this->assertSame(2, (int)$row['attempt']);
        }
    }

    public function test_db_retry_race_succeeds_once_after_first_failure(): void
    {
        [$queue] = $this->configureDbQueues(['worker_e2e_retry'], ['worker_cnt' => 3, 'max_attempts' => 3]);
        for ($i = 0; $i < 10; $i++) {
            $this->pushHandlerJob($queue, 'fail_once_then_success', 'db-retry-' . $i);
        }

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::COMPLETED->value, 10, 10.0);

        $this->assertSame(10, $this->workers->markerCount('success'));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::FAILED->value));
        foreach ($this->workers->markerRows('success') as $row) {
            $this->assertSame(2, (int)$row['attempt']);
        }
    }

    public function test_redis_exhaustion_marks_failed_without_active_copy(): void
    {
        [$queue] = $this->configureRedisQueues(['worker_e2e_exhaust'], ['max_attempts' => 2]);
        $this->pushHandlerJob($queue, 'always_fail', 'redis-exhaust');

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::FAILED->value, 1, 8.0);

        $failed = $telemetry->fetch_failed_jobs($queue);
        $job = \array_values($failed['items'])[0];
        $this->assertSame(2, (int)$job['attempts']);
        $this->assertIsArray($job['exception']);
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::RUNNING->value));
    }

    public function test_db_exhaustion_marks_failed_without_active_copy(): void
    {
        [$queue] = $this->configureDbQueues(['worker_e2e_exhaust'], ['max_attempts' => 2]);
        $this->pushHandlerJob($queue, 'always_fail', 'db-exhaust');

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::FAILED->value, 1, 8.0);

        $failed = $telemetry->fetch_failed_jobs($queue);
        $job = \array_values($failed['items'])[0];
        $this->assertSame(2, (int)$job['attempts']);
        $this->assertIsArray($job['exception']);
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::PENDING->value));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::RUNNING->value));
    }

    public function test_redis_running_job_can_be_cancelled_by_worker_signal(): void
    {
        [$queue] = $this->configureRedisQueues(['worker_e2e_cancel'], ['timeout' => 5]);
        $uuid = $this->newUuid();
        $manager = new Manager($queue);
        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'slow_success'],
            ['marker_dir' => $this->workers->markerDir(), 'id' => 'redis-cancel', 'seconds' => 4.0],
            ['cancel_handler' => [QueueTestHandler::class, 'record_cancellation']],
            $uuid
        ));

        $this->workers->startWorker($queue);
        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::RUNNING->value, 1, 5.0);

        $this->assertTrue($manager->cancel($uuid));
        $this->workers->waitUntil(
            fn (): bool => $this->stateCount($telemetry, $queue, State::CANCEL_REQUESTED->value) === 1
                || $this->stateCount($telemetry, $queue, State::CANCELLED->value) === 1,
            3.0,
            "Queue {$queue} did not reach cancel_requested or cancelled state."
        );
        $this->waitForStateCount($telemetry, $queue, State::CANCELLED->value, 1, 6.0);

        $this->assertSame(1, $this->workers->markerCount('cancelled'));
        $this->assertSame(0, $this->stateCount($telemetry, $queue, State::COMPLETED->value));
        $this->assertFalse($this->redis->zScore($this->redisPrefix . $queue . '.idx.completed', $uuid));
    }

    public function test_redis_graceful_shutdown_finishes_current_job_and_starts_no_new_job(): void
    {
        [$queue] = $this->configureRedisQueues(['worker_e2e_shutdown'], ['worker_cnt' => 1, 'timeout' => 5]);
        $this->pushHandlerJob($queue, 'block_until_released', 'redis-shutdown-current');
        $this->pushMarkerJob($queue, 'redis-shutdown-next');

        $master = $this->workers->startWorker($queue);
        $this->workers->waitUntil(fn (): bool => $this->workers->markerCount('running') === 1, 5.0, 'Redis shutdown job did not start.');
        $this->workers->signal($master, SIGTERM);
        $this->workers->release('redis-shutdown-current');
        $this->workers->assertMasterExited($master, 8.0);

        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::COMPLETED->value, 1, 3.0);
        $this->assertSame(1, $this->stateCount($telemetry, $queue, State::COMPLETED->value));
        $this->assertSame(1, $this->stateCount($telemetry, $queue, State::PENDING->value));
        $this->assertSame(['redis-shutdown-current'], $this->workers->uniqueMarkerIds('success'));
    }

    public function test_db_graceful_shutdown_finishes_current_job_and_starts_no_new_job(): void
    {
        [$queue] = $this->configureDbQueues(['worker_e2e_shutdown'], ['worker_cnt' => 1, 'timeout' => 5]);
        $this->pushHandlerJob($queue, 'block_until_released', 'db-shutdown-current');
        $this->pushMarkerJob($queue, 'db-shutdown-next');

        $master = $this->workers->startWorker($queue);
        $this->workers->waitUntil(fn (): bool => $this->workers->markerCount('running') === 1, 5.0, 'DB shutdown job did not start.');
        $this->workers->signal($master, SIGTERM);
        $this->workers->release('db-shutdown-current');
        $this->workers->assertMasterExited($master, 8.0);

        $telemetry = new TelemetryManager();
        $this->waitForStateCount($telemetry, $queue, State::COMPLETED->value, 1, 3.0);
        $this->assertSame(1, $this->stateCount($telemetry, $queue, State::COMPLETED->value));
        $this->assertSame(1, $this->stateCount($telemetry, $queue, State::PENDING->value));
        $this->assertSame(['db-shutdown-current'], $this->workers->uniqueMarkerIds('success'));
    }

    private function requireWorkerIntegrationSupport(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for worker integration tests.');
        }
    }

    private function configureRedisQueues(array $queues, array $overrides = []): array
    {
        [$redis, $host] = $this->connectRedisOrSkip();
        $this->redis = $redis;
        $this->redisPrefix = 'atomic_worker_it_' . \bin2hex(\random_bytes(4)) . ':';
        $this->cleanupRedisPrefix($redis, $this->redisPrefix);

        $this->configureRedisConnection($redis, $host, $this->redisPrefix);
        $this->configureQueues('redis', $queues, $overrides);
        return $queues;
    }

    private function configureDbQueues(array $queues, array $overrides = []): array
    {
        [, $host] = $this->connectPdoOrSkip();
        $prefix = 'atomic_worker_it_' . \bin2hex(\random_bytes(4)) . '_';
        $this->configureDbForQueue($host, $prefix, $queues[0], $overrides);
        $this->configureQueues('db', $queues, $overrides);
        $this->migrateQueueTablesUp();
        $this->dbMigrated = true;
        return $queues;
    }

    private function configureQueues(string $driver, array $queues, array $overrides = []): void
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

    private function pushMarkerJob(string $queue, string $id): void
    {
        $this->pushHandlerJob($queue, 'record_success', $id, ['queue' => $queue]);
    }

    private function pushManyMarkerJobs(string $queue, string $prefix, int $count): void
    {
        $manager = new Manager($queue);
        for ($i = 0; $i < $count; $i++) {
            $this->pushHandlerJobWithManager($manager, 'record_success', $prefix . $i, ['queue' => $queue]);
        }
    }

    private function pushHandlerJob(string $queue, string $handler, string $id, array $extraData = []): void
    {
        $manager = new Manager($queue);
        $this->pushHandlerJobWithManager($manager, $handler, $id, $extraData);
    }

    private function pushHandlerJobWithManager(Manager $manager, string $handler, string $id, array $extraData = []): void
    {
        $data = \array_merge(['marker_dir' => $this->workers->markerDir(), 'id' => $id], $extraData);
        $this->assertTrue($manager->push([QueueTestHandler::class, $handler], $data));
    }

    private function firstJobInState(TelemetryManager $telemetry, string $queue, string $state): ?array
    {
        $jobs = $telemetry->fetch_all_jobs($queue, ['state' => $state]);
        if (($jobs['total'] ?? 0) < 1) {
            return null;
        }
        return \array_values($jobs['items'])[0];
    }

    private function waitForStateCount(TelemetryManager $telemetry, string $queue, string $state, int $count, float $deadline = 8.0): void
    {
        $this->workers->waitUntil(
            fn (): bool => $this->stateCount($telemetry, $queue, $state) === $count,
            $deadline,
            "Queue {$queue} did not reach {$count} {$state} job(s)."
        );
    }

    private function waitForLifecycleEvents(string $driver, TelemetryManager $telemetry, string $queue, string $uuid): void
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

    private function stateCount(TelemetryManager $telemetry, string $queue, string $state): int
    {
        return (int)($telemetry->fetch_all_jobs($queue, ['state' => $state])['total'] ?? 0);
    }
}
