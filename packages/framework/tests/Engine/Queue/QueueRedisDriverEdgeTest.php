<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use Tests\Engine\Queue\Support\QueueRedisTestCase;

final class QueueRedisDriverEdgeTest extends QueueRedisTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->trapUsr1Signal();
        QueueTestHandler::reset();
    }

    protected function tearDown(): void
    {
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(false);
        }
        parent::tearDown();
    }

    public function test_priority_and_fifo_order_are_stable_for_direct_pop_batch(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $queue = $manager->get_queue();

        $low = $this->new_uuid();
        $first = $this->new_uuid();
        $second = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'low'], ['priority' => 9], $low));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'first'], ['priority' => 1], $first));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 3], 'smth' => 'second'], ['priority' => 1], $second));

        $jobs = $driver->pop_batch($queue, 3);

        $this->assertSame([$first, $second, $low], \array_column($jobs, 'uuid'));
        $this->assertCount(3, \array_unique(\array_column($jobs, 'uuid')));
    }

    public function test_delayed_job_is_not_popped_until_available(): void
    {
        $manager = new Manager();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'delay'], ['delay' => 2], $uuid));
        $this->assertSame([], $manager->pop_batch());
    }

    public function test_large_priority_does_not_delay_available_job(): void
    {
        $manager = new Manager();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'large-priority'], ['priority' => 2500], $uuid));

        $jobs = $manager->pop_batch();

        $this->assertCount(1, $jobs);
        $this->assertSame($uuid, $jobs[0]['uuid']);
    }

    public function test_release_rejects_wrong_pid_and_preserves_running_job(): void
    {
        $manager = new Manager();
        $uuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'pid'], [], $uuid));
        $job = $manager->pop_batch()[0];
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));

        $stale = $job;
        $stale['pid'] = \getmypid() + 99999;
        $this->assertFalse($manager->release($stale, 0));
        $this->assertFalse($this->redis->zScore($this->prefix . $manager->get_queue() . '.idx.pending', $uuid));
        $this->assertNotFalse($this->redis->zScore($this->prefix . $manager->get_queue() . '.idx.running', $uuid));
    }

    public function test_script_flush_is_recovered_for_eval_lua_paths(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $cancelUuid = $this->new_uuid();
        $popUuid = $this->new_uuid();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'cancel'], [], $cancelUuid));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'pop'], [], $popUuid));

        $this->redis->script('FLUSH');

        $this->assertTrue($manager->cancel($cancelUuid));
        $this->assertNotFalse($this->redis->zScore($this->prefix . $manager->get_queue() . '.idx.cancelled', $cancelUuid));
        $jobs = $driver->pop_batch($manager->get_queue(), 1);
        $this->assertCount(1, $jobs);
        $this->assertSame($popUuid, $jobs[0]['uuid']);
    }

    public function test_cancel_requested_completion_becomes_cancelled_and_telemetry_search_finds_it(): void
    {
        $manager = new Manager();
        $uuid = $this->new_uuid();
        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'success'],
            ['params' => ['id' => 1], 'smth' => 'cancel'],
            ['cancel_handler' => 'cancelled'],
            $uuid
        ));
        $job = $manager->pop_batch()[0];

        $this->assertTrue($manager->cancel($uuid));
        $this->assertTrue($manager->mark_completed($job));
        $this->assertFalse($this->redis->zScore($this->prefix . $manager->get_queue() . '.idx.completed', $uuid));
        $this->assertNotFalse($this->redis->zScore($this->prefix . $manager->get_queue() . '.idx.cancelled', $uuid));
        $this->assertCount(1, QueueTestHandler::$cancelled);

        $telemetry = new TelemetryManager();
        $search = $telemetry->fetch_all_jobs($manager->get_queue(), ['uuid' => $uuid]);
        $this->assertSame(1, $search['total']);
        $this->assertSame('cancelled', $search['items'][$uuid]['state']);
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

    public function test_monitor_adapter_loads_stuck_jobs_and_marks_cancel_requested_jobs_cancelled(): void
    {
        $manager = new Manager();
        $queue = $manager->get_queue();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'success'],
            ['params' => ['id' => 2], 'smth' => 'stuck-cancel'],
            ['cancel_handler' => 'cancelled'],
            $uuid
        ));
        $job = $manager->pop_batch()[0];
        $this->assertSame($uuid, $job['uuid']);
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));
        $this->assertTrue($manager->cancel($uuid));

        $this->redis->zAdd($this->prefix . $queue . '.idx.cancel_requested', (\time() - 5) * 1000, $uuid);

        $stuck = $manager->load_stuck_jobs([], $queue);
        $this->assertSame([$uuid], \array_column($stuck, 'uuid'));
        $this->assertSame([], $manager->load_stuck_jobs([(string)\getmypid()], $queue));

        $manager->handle_incomplete_job($stuck[0]);
        $this->assertFalse($this->redis->zScore($this->prefix . $queue . '.idx.cancel_requested', $uuid));
        $this->assertNotFalse($this->redis->zScore($this->prefix . $queue . '.idx.cancelled', $uuid));
    }

    public function test_missing_and_malformed_registry_entries_return_empty_results(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);
        $uuid = $this->new_uuid();

        $this->assertFalse($manager->retry_by_uuid($uuid));
        $this->assertFalse($manager->delete_job($uuid));
        $this->assertNull($driver->find_by_uuid($uuid));

        $this->redis->hMSet($this->prefix . 'registry.' . $uuid, [
            'uuid' => $uuid,
            'queue' => $manager->get_queue(),
            'payload' => '{}',
        ]);

        $this->assertNull($driver->find_by_uuid($uuid));
        $telemetry = new TelemetryManager();
        $search = $telemetry->fetch_all_jobs($manager->get_queue(), ['uuid' => $uuid]);
        $this->assertSame(0, $search['total']);
    }

    public function test_zero_limit_pop_and_missing_uuid_set_pid_return_empty_false(): void
    {
        $manager = new Manager();
        $driver = $this->manager_driver($manager);

        $this->assertSame([], $driver->pop_batch($manager->get_queue(), 0));
        $this->assertFalse($driver->set_pid(['uuid' => null]));
    }

    private function trapUsr1Signal(): void
    {
        if (!\function_exists('pcntl_signal') || !\defined('SIGUSR1')) {
            return;
        }

        \pcntl_signal(SIGUSR1, static function (): void {
        });
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
        }
    }
}
