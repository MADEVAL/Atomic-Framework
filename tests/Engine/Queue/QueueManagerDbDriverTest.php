<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\ID;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use Tests\Engine\Queue\Support\QueueDbTestCase;

final class QueueManagerDbDriverTest extends QueueDbTestCase
{
    public function test_db_driver_queue_flow(): void
    {
        QueueTestHandler::reset();

        $manager = new Manager();

        $uuid_completed = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'ok'], [], $uuid_completed));
        $jobs = $manager->pop_batch();
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame($uuid_completed, $job['uuid']);
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));
        $this->assertTrue($manager->mark_completed($job));

        $uuid_release = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'release'], [], $uuid_release));
        $first = $manager->pop_batch();
        $this->assertCount(1, $first);
        $released_job = $first[0];
        $released_job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($released_job));
        $this->assertTrue($manager->release($released_job, 0));
        $repop = $manager->pop_batch();
        $this->assertCount(1, $repop);
        $this->assertSame($uuid_release, $repop[0]['uuid']);
        $this->assertGreaterThanOrEqual(2, (int)$repop[0]['attempts']);

        $uuid_failed = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'failure'], ['params' => ['id' => 3], 'smth' => 'fail'], [], $uuid_failed));
        $failed = $manager->pop_batch();
        $this->assertCount(1, $failed);
        $failed_job = $failed[0];
        $failed_job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($failed_job));
        $this->assertTrue($manager->mark_failed($failed_job, new \RuntimeException('boom')));
        $this->assertTrue($manager->retry_by_uuid($uuid_failed));
        $retried = $manager->pop_batch();
        $this->assertCount(1, $retried);
        $this->assertSame($uuid_failed, $retried[0]['uuid']);

        $uuid_delete = ID::uuid_v4();
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 5], 'smth' => 'delete'], [], $uuid_delete));
        $this->assertTrue($manager->delete_job($uuid_delete));

        $this->assertFalse($manager->supports_cancel());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue cancellation is not supported for the database queue driver.');
        $manager->cancel(ID::uuid_v4());
    }
}
