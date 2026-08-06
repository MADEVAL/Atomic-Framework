<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use Tests\Engine\Queue\Support\QueueDbTestCase;

final class QueueDbDriverEdgeTest extends QueueDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueTestHandler::reset();
    }

    public function test_direct_pop_batch_honors_limit_without_duplicate_claims(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $queue = $manager->get_queue();
        $one = $this->new_uuid();
        $two = $this->new_uuid();
        $three = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'one'], [], $one));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'two'], [], $two));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 3], 'smth' => 'three'], [], $three));

        $jobs = $driver->pop_batch($queue, 2);

        $this->assertCount(2, $jobs);
        $this->assertCount(2, \array_unique(\array_column($jobs, 'uuid')));
    }

    public function test_direct_pop_batch_zero_limit_returns_no_jobs_without_claiming(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $queue = $manager->get_queue();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'zero-limit'], [], $uuid));

        $this->assertSame([], $driver->pop_batch($queue, 0));
        $jobs = $driver->pop_batch($queue, 1);

        $this->assertCount(1, $jobs);
        $this->assertSame($uuid, $jobs[0]['uuid']);
    }

    public function test_priority_order_and_delay_are_respected(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $queue = $manager->get_queue();
        $delayed = $this->new_uuid();
        $low = $this->new_uuid();
        $high = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'delay'], ['delay' => 2], $delayed));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'low'], ['priority' => 9], $low));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 3], 'smth' => 'high'], ['priority' => 1], $high));

        $jobs = $driver->pop_batch($queue, 3);

        $this->assertSame([$high, $low], \array_column($jobs, 'uuid'));
        $this->assertNotContains($delayed, \array_column($jobs, 'uuid'));
    }

    public function test_ownership_guards_reject_stale_worker_mutations(): void
    {
        $manager = new Manager();
        $uuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'guard'], [], $uuid));
        $job = $manager->pop_batch()[0];
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $stale = $job;
        $stale['pid'] = \getmypid() + 99999;
        $this->assertFalse($manager->release($stale, 0));
        $this->assertFalse($manager->mark_completed($stale));
        $this->assertFalse($manager->mark_failed($stale, new \RuntimeException('stale')));
        $this->assertTrue($manager->mark_completed($job));
    }

    public function test_uuid_search_finds_active_failed_and_completed_without_cancel_column(): void
    {
        $manager = new Manager();
        $telemetry = new TelemetryManager();

        $activeUuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'active'], [], $activeUuid));
        $active = $telemetry->fetch_all_jobs($manager->get_queue(), ['uuid' => $activeUuid]);
        $this->assertSame(1, $active['total']);
        $this->assertSame('pending', $active['items'][$activeUuid]['state']);
        $activeJob = $manager->pop_batch()[0];
        $activeJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($activeJob));
        $this->assertTrue($manager->mark_completed($activeJob));

        $failedUuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'failure'], ['params' => ['id' => 2], 'smth' => 'failed'], [], $failedUuid));
        $failedJob = $manager->pop_batch()[0];
        $this->assertSame($failedUuid, $failedJob['uuid']);
        $failedJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($failedJob));
        $this->assertTrue($manager->mark_failed($failedJob, new \RuntimeException('search boom')));
        $failed = $telemetry->fetch_all_jobs($manager->get_queue(), ['uuid' => $failedUuid]);
        $this->assertSame(1, $failed['total']);
        $this->assertSame('failed', $failed['items'][$failedUuid]['state']);

        $completedUuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 3], 'smth' => 'completed'], [], $completedUuid));
        $completedJob = $manager->pop_batch()[0];
        $completedJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($completedJob));
        $this->assertTrue($manager->mark_completed($completedJob));
        $completed = $telemetry->fetch_all_jobs($manager->get_queue(), ['uuid' => $completedUuid]);
        $this->assertSame(1, $completed['total']);
        $this->assertSame('completed', $completed['items'][$completedUuid]['state']);
    }

    public function test_monitor_adapter_loads_and_handles_active_jobs(): void
    {
        $manager = new Manager();
        $queue = $manager->get_queue();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'monitor'], [], $uuid));
        $job = $manager->pop_batch()[0];
        $this->assertSame($uuid, $job['uuid']);
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $activeJobs = $manager->load_active_jobs($queue);
        $this->assertSame([$uuid], \array_column($activeJobs, 'uuid'));

        $manager->handle_incomplete_job($activeJobs[0]);
        $this->assertSame([], $manager->load_active_jobs($queue));

        $retried = $manager->pop_batch();
        $this->assertCount(1, $retried);
        $this->assertSame($uuid, $retried[0]['uuid']);
        $this->assertGreaterThanOrEqual(2, (int)$retried[0]['attempts']);
    }

    public function test_monitor_adapter_loads_stuck_jobs_and_marks_exhausted_jobs_failed(): void
    {
        $manager = new Manager();
        $queue = $manager->get_queue();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'success'],
            ['params' => ['id' => 2], 'smth' => 'stuck'],
            ['max_attempts' => 1],
            $uuid
        ));
        $job = $manager->pop_batch()[0];
        $this->assertSame($uuid, $job['uuid']);
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $sql = App::instance()->get('DB');
        $table = App::instance()->get('DB_CONFIG.prefix') . 'jobs';
        $sql->exec('UPDATE `' . $table . '` SET available_at = ? WHERE uuid = ?', [\time() - 5, $uuid]);

        $stuck = $manager->load_stuck_jobs([], $queue);
        $this->assertSame([$uuid], \array_column($stuck, 'uuid'));
        $this->assertSame([], $manager->load_stuck_jobs([$uuid], $queue));

        $manager->handle_incomplete_job($stuck[0]);

        $telemetry = new TelemetryManager();
        $failed = $telemetry->fetch_all_jobs($queue, ['uuid' => $uuid]);
        $this->assertSame(1, $failed['total']);
        $this->assertSame('failed', $failed['items'][$uuid]['state']);
    }

    public function test_retry_and_delete_false_paths_are_reported(): void
    {
        $manager = new Manager();

        $this->assertTrue($manager->retry_by_uuid($this->new_uuid()) === false);
        $this->assertFalse($manager->delete_job($this->new_uuid()));
    }

    public function test_missing_uuid_mutations_return_false(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $job = [
            'queue' => $manager->get_queue(),
            'pid' => \getmypid(),
            'payload' => ['uuid_batch' => $this->new_uuid()],
        ];

        $this->assertFalse($driver->release($job, 0));
        $this->assertFalse($driver->set_pid($job));
        $this->assertFalse($driver->mark_completed($job));
        $this->assertFalse($driver->mark_failed($job, new \RuntimeException('missing uuid')));
    }

    public function test_retry_all_restores_failed_jobs_and_delete_removes_terminal_rows(): void
    {
        $manager = new Manager();
        $telemetry = new TelemetryManager();
        $queue = $manager->get_queue();
        $failedUuid = $this->new_uuid();
        $completedUuid = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'failure'], ['params' => ['id' => 1], 'smth' => 'retry-all'], [], $failedUuid));
        $failedJob = $manager->pop_batch()[0];
        $failedJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($failedJob));
        $this->assertTrue($manager->mark_failed($failedJob, new \RuntimeException('retry all')));

        $manager->retry();
        $retried = $manager->pop_batch();
        $this->assertCount(1, $retried);
        $this->assertSame($failedUuid, $retried[0]['uuid']);

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'delete-terminal'], [], $completedUuid));
        $completedJob = $manager->pop_batch()[0];
        $completedJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($completedJob));
        $this->assertTrue($manager->mark_completed($completedJob));
        $this->assertSame(1, $telemetry->fetch_all_jobs($queue, ['uuid' => $completedUuid])['total']);

        $this->assertTrue($manager->delete_job($completedUuid));
        $this->assertSame(0, $telemetry->fetch_all_jobs($queue, ['uuid' => $completedUuid])['total']);
    }
}
