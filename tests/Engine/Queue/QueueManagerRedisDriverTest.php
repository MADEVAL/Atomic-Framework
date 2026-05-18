<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\ID;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use Tests\Engine\Queue\Support\QueueRedisTestCase;

final class QueueManagerRedisDriverTest extends QueueRedisTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueTestHandler::reset();
    }

    public function test_redis_driver_queue_flow(): void
    {
        $manager = new Manager($this->queue);

        $uuid_completed = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'ok'], [], $uuid_completed));
        $jobs = $manager->pop_batch();
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame($uuid_completed, $job['uuid']);
        $job['pid'] = getmypid();
        $this->assertTrue($manager->set_pid($job));
        $this->assertTrue($manager->mark_completed($job));
        $this->assertFalse($manager->cancel($uuid_completed));

        $uuid_release = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'release'], [], $uuid_release));
        $first = $manager->pop_batch();
        $this->assertCount(1, $first);
        $released_job = $first[0];
        $released_job['pid'] = getmypid();
        $this->assertTrue($manager->set_pid($released_job));
        $this->assertTrue($manager->release($released_job, 0));
        $repop = $manager->pop_batch();
        $this->assertCount(1, $repop);
        $this->assertSame($uuid_release, $repop[0]['uuid']);
        $this->assertGreaterThanOrEqual(2, (int)$repop[0]['attempts']);
        $this->assertTrue($manager->mark_completed($repop[0]));

        $uuid_failed = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'failure'], ['params' => ['id' => 3], 'smth' => 'fail'], [], $uuid_failed));
        $failed = $manager->pop_batch();
        $this->assertCount(1, $failed);
        $failed_job = $failed[0];
        $failed_job['pid'] = getmypid();
        $this->assertTrue($manager->set_pid($failed_job));
        $this->assertTrue($manager->mark_failed($failed_job, new \RuntimeException('boom')));
        $this->assertTrue($manager->retry_by_uuid($uuid_failed));
        $retried = $manager->pop_batch();
        $this->assertCount(1, $retried);
        $this->assertSame($uuid_failed, $retried[0]['uuid']);
        $this->assertTrue($manager->mark_completed($retried[0]));

        $uuid_cancel = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 4], 'smth' => 'cancel'], [], $uuid_cancel));
        $this->assertTrue($manager->cancel($uuid_cancel));
        $this->assertFalse($manager->cancel($uuid_cancel));

        $uuid_cancel_running = ID::uuid_v4();
        $this->assertTrue($manager->push(
            [QueueTestHandler::class, 'success'],
            ['params' => ['id' => 6], 'smth' => 'cancel-running'],
            ['cancel_handler' => 'cancelled'],
            $uuid_cancel_running
        ));
        $running = $manager->pop_batch();
        $this->assertCount(1, $running);
        $running_job = $running[0];
        $this->assertSame($uuid_cancel_running, $running_job['uuid']);
        $this->assertTrue($manager->cancel($uuid_cancel_running));
        $this->assertTrue($manager->is_cancel_requested($uuid_cancel_running));
        $this->assertFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.running', $uuid_cancel_running));
        $this->assertNotFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.cancel_requested', $uuid_cancel_running));
        $telemetry = new TelemetryManager();
        $cancel_requested_jobs = $telemetry->fetch_all_jobs($this->queue, ['state' => 'cancel_requested']);
        $this->assertSame(1, $cancel_requested_jobs['total']);
        $this->assertArrayHasKey($uuid_cancel_running, $cancel_requested_jobs['items']);
        $running_jobs = $telemetry->fetch_all_jobs($this->queue, ['state' => 'running']);
        $this->assertSame(0, $running_jobs['total']);
        $this->assertCount(1, QueueTestHandler::$cancelled);
        $this->assertSame($uuid_cancel_running, QueueTestHandler::$cancelled[0]['uuid']);
        $this->assertTrue($manager->mark_completed($running_job));
        $this->assertFalse($manager->is_cancel_requested($uuid_cancel_running));
        $this->assertFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.cancel_requested', $uuid_cancel_running));
        $this->assertNotFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.cancelled', $uuid_cancel_running));
        $this->assertFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.completed', $uuid_cancel_running));
        $this->assertFalse($manager->cancel($uuid_cancel_running));

        $uuid_delete_blocked = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 8], 'smth' => 'delete-blocked'], [], $uuid_delete_blocked));
        $delete_blocked_running = $manager->pop_batch();
        $this->assertCount(1, $delete_blocked_running);
        $this->assertSame($uuid_delete_blocked, $delete_blocked_running[0]['uuid']);
        $this->assertTrue($manager->cancel($uuid_delete_blocked));
        $this->assertFalse($manager->delete_job($uuid_delete_blocked));
        $this->assertTrue($manager->mark_cancelled($delete_blocked_running[0], 'test cleanup'));
        $this->assertFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.cancel_requested', $uuid_delete_blocked));
        $this->assertNotFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.cancelled', $uuid_delete_blocked));
        $this->assertFalse($this->redis->zScore($this->prefix . $this->queue . '.idx.completed', $uuid_delete_blocked));

        $uuid_delete = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 5], 'smth' => 'delete'], [], $uuid_delete));
        $this->assertTrue($manager->delete_job($uuid_delete));
    }
}
