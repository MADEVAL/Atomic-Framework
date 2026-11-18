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

    public function test_direct_pop_claims_distinct_jobs_until_queue_is_empty(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $queue = $manager->get_queue();
        $one = $this->new_uuid();
        $two = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'one'], [], $one));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'two'], [], $two));

        $first = $driver->pop($queue);
        $second = $driver->pop($queue);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first['uuid'], $second['uuid']);
        $this->assertNull($driver->pop($queue));
    }

    public function test_direct_pop_returns_null_for_empty_queue(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);

        $this->assertNull($driver->pop($manager->get_queue()));
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

        $this->assertSame($high, $driver->pop($queue)['uuid']);
        $this->assertSame($low, $driver->pop($queue)['uuid']);
        $this->assertNull($driver->pop($queue));
    }

    public function test_ownership_guards_reject_stale_worker_mutations(): void
    {
        $manager = new Manager();
        $uuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'guard'], [], $uuid));
        $job = $manager->pop();
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $stale = $job;
        $stale['pid'] = \getmypid() + 99999;
        $this->assertFalse($manager->release($stale, 0));
        $this->assertFalse($manager->mark_completed($stale));
        $this->assertFalse($manager->mark_failed($stale, new \RuntimeException('stale')));
        $this->assertTrue($manager->mark_completed($job));
    }

    public function test_mark_completed_rejects_an_expired_lease(): void
    {
        $manager = new Manager();
        $uuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'expired'], [], $uuid));

        $job = $manager->pop();
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $sql = App::instance()->get('DB');
        $table = App::instance()->get('DB_CONFIG.prefix') . 'jobs';
        $sql->exec('UPDATE `' . $table . '` SET available_at = ? WHERE uuid = ?', [\time() - 1, $uuid]);

        $this->assertFalse($manager->mark_completed($job));
        $this->assertSame([$uuid], \array_column($manager->load_stuck_jobs([], $manager->get_queue()), 'uuid'));
    }

    public function test_renew_lease_extends_only_the_active_owned_job(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $uuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'renew'], [], $uuid));

        $job = $manager->pop();
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $sql = App::instance()->get('DB');
        $table = App::instance()->get('DB_CONFIG.prefix') . 'jobs';
        $before = (int)$sql->exec('SELECT available_at FROM `' . $table . '` WHERE uuid = ?', [$uuid])[0]['available_at'];

        $this->assertTrue($driver->renew_lease($job, 60));
        $after = (int)$sql->exec('SELECT available_at FROM `' . $table . '` WHERE uuid = ?', [$uuid])[0]['available_at'];
        $this->assertGreaterThan($before, $after);

        $stale = $job;
        $stale['pid']++;
        $this->assertFalse($driver->renew_lease($stale, 60));
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
        $activeJob = $manager->pop();
        $activeJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($activeJob));
        $this->assertTrue($manager->mark_completed($activeJob));

        $failedUuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'failure'], ['params' => ['id' => 2], 'smth' => 'failed'], [], $failedUuid));
        $failedJob = $manager->pop();
        $this->assertSame($failedUuid, $failedJob['uuid']);
        $failedJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($failedJob));
        $this->assertTrue($manager->mark_failed($failedJob, new \RuntimeException('search boom')));
        $failed = $telemetry->fetch_all_jobs($manager->get_queue(), ['uuid' => $failedUuid]);
        $this->assertSame(1, $failed['total']);
        $this->assertSame('failed', $failed['items'][$failedUuid]['state']);

        $completedUuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 3], 'smth' => 'completed'], [], $completedUuid));
        $completedJob = $manager->pop();
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
        $job = $manager->pop();
        $this->assertSame($uuid, $job['uuid']);
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $activeJobs = $manager->load_active_jobs($queue);
        $this->assertSame([$uuid], \array_column($activeJobs, 'uuid'));

        $manager->handle_incomplete_job($activeJobs[0]);
        $this->assertSame([], $manager->load_active_jobs($queue));

        $retried = $manager->pop();
        $this->assertNotNull($retried);
        $this->assertSame($uuid, $retried['uuid']);
        $this->assertGreaterThanOrEqual(2, (int)$retried['attempts']);
    }

    public function test_monitor_adapter_loads_active_jobs_without_process_start_ticks(): void
    {
        $manager = new Manager();
        $queue = $manager->get_queue();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'monitor-no-ticks'], [], $uuid));
        $job = $manager->pop();
        $this->assertSame($uuid, $job['uuid']);
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));
        $this->clear_process_start_ticks($uuid);

        $activeJobs = $manager->load_active_jobs($queue);
        $this->assertSame([$uuid], \array_column($activeJobs, 'uuid'));
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
        $job = $manager->pop();
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
        $failedJob = $manager->pop();
        $failedJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($failedJob));
        $this->assertTrue($manager->mark_failed($failedJob, new \RuntimeException('retry all')));

        $manager->retry();
        $retried = $manager->pop();
        $this->assertNotNull($retried);
        $this->assertSame($failedUuid, $retried['uuid']);

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'delete-terminal'], [], $completedUuid));
        $completedJob = $manager->pop();
        $completedJob['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($completedJob));
        $this->assertTrue($manager->mark_completed($completedJob));
        $this->assertSame(1, $telemetry->fetch_all_jobs($queue, ['uuid' => $completedUuid])['total']);

        $this->assertTrue($manager->delete_job($completedUuid));
        $this->assertSame(0, $telemetry->fetch_all_jobs($queue, ['uuid' => $completedUuid])['total']);
    }
}
