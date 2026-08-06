<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Queue\Tests\TestJob as QueueTestHandler;
use Engine\Atomic\Telemetry\Queue\EventType;
use Tests\Engine\Queue\Support\QueueDbTestCase;

final class QueueTelemetryDbTest extends QueueDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueTestHandler::reset();
    }

    public function test_public_fetch_methods_report_db_job_states_and_pagination(): void
    {
        $manager = new Manager();
        $telemetry = new TelemetryManager();
        $queue = $manager->get_queue();

        $pendingOne = $this->new_uuid();
        $pendingTwo = $this->new_uuid();
        $runningUuid = $this->new_uuid();
        $failedUuid = $this->new_uuid();
        $completedUuid = $this->new_uuid();

        $runningJob = $this->pushPopAndSetPid($manager, $runningUuid, 'running');
        $failedJob = $this->pushPopAndSetPid($manager, $failedUuid, 'failed');
        $this->assertTrue($manager->mark_failed($failedJob, new \RuntimeException('telemetry failed')));
        $completedJob = $this->pushPopAndSetPid($manager, $completedUuid, 'completed');
        $this->assertTrue($manager->mark_completed($completedJob));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'pending-1'], [], $pendingOne));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 2], 'smth' => 'pending-2'], [], $pendingTwo));

        $this->assertFetchContains($telemetry->fetch_pending_jobs($queue), $pendingOne, 'pending', 2);
        $pendingPageTwo = $telemetry->fetch_pending_jobs($queue, 2, 1);
        $this->assertSame(2, $pendingPageTwo['total']);
        $this->assertCount(1, $pendingPageTwo['items']);
        $this->assertSame('pending', \array_values($pendingPageTwo['items'])[0]['state']);
        $this->assertFetchContains($telemetry->fetch_running_jobs($queue), $runningUuid, 'running', 1);
        $this->assertFetchContains($telemetry->fetch_failed_jobs($queue), $failedUuid, 'failed', 1);
        $this->assertFetchContains($telemetry->fetch_completed_jobs($queue), $completedUuid, 'completed', 1);
        $this->assertSame(0, $telemetry->fetch_cancel_requested_jobs($queue)['total']);
        $this->assertSame(0, $telemetry->fetch_cancelled_jobs($queue)['total']);

        $all = $telemetry->fetch_all_jobs($queue);
        $this->assertSame(5, $all['total']);
        $this->assertSame(2, $all['state_totals']['pending']);
        $this->assertSame(1, $all['state_totals']['running']);
        $this->assertSame(1, $all['state_totals']['failed']);
        $this->assertSame(1, $all['state_totals']['completed']);

        $stateFiltered = $telemetry->fetch_all_jobs($queue, ['state' => 'failed']);
        $this->assertSame(1, $stateFiltered['total']);
        $this->assertSame('failed', $stateFiltered['items'][$failedUuid]['state']);

        $invalidUuid = $telemetry->fetch_all_jobs($queue, ['uuid' => 'not-a-uuid']);
        $this->assertSame(0, $invalidUuid['total']);
        $this->assertSame(0, $invalidUuid['state_totals']['total']);

        $uuidStateMismatch = $telemetry->fetch_all_jobs($queue, ['uuid' => $failedUuid, 'state' => 'completed']);
        $this->assertSame(0, $uuidStateMismatch['total']);
    }

    public function test_fetch_all_jobs_paginates_globally_across_states(): void
    {
        $manager = new Manager();
        $telemetry = new TelemetryManager();
        $queue = $manager->get_queue();

        $pendingUuid = $this->new_uuid();
        $failedUuid = $this->new_uuid();
        $completedUuid = $this->new_uuid();

        $failedJob = $this->pushPopAndSetPid($manager, $failedUuid, 'failed-page');
        $this->assertTrue($manager->mark_failed($failedJob, new \RuntimeException('global pagination failed')));
        $completedJob = $this->pushPopAndSetPid($manager, $completedUuid, 'completed-page');
        $this->assertTrue($manager->mark_completed($completedJob));
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 1], 'smth' => 'pending'], [], $pendingUuid));

        $pageOne = $telemetry->fetch_all_jobs($queue, [], 1, 1);
        $pageTwo = $telemetry->fetch_all_jobs($queue, [], 2, 1);

        $this->assertSame(3, $pageOne['total']);
        $this->assertCount(1, $pageOne['items']);
        $this->assertSame(3, $pageTwo['total']);
        $this->assertCount(1, $pageTwo['items']);
    }

    public function test_db_fetch_events_returns_job_lifecycle_events(): void
    {
        $manager = new Manager();
        $telemetry = new TelemetryManager();
        $queue = $manager->get_queue();
        $uuid = $this->new_uuid();

        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 10], 'smth' => 'events'], [], $uuid));
        $job = $manager->pop_batch()[0];
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));
        $this->assertTrue($manager->mark_completed($job));

        $events = $telemetry->fetch_events('db', $queue, $uuid);
        $types = \array_column($events, 'event_type_id');

        $this->assertSame(EventType::JOB_CREATED->value, $types[0]);
        $this->assertContains(EventType::JOB_CREATED->value, $types);
        $this->assertContains(EventType::JOB_FETCHED->value, $types);
        $this->assertContains(EventType::JOB_SUCCESS->value, $types);
        $this->assertSame($uuid, $events[0]['uuid_job']);
        $this->assertArrayHasKey('event_description', $events[0]);
    }

    private function pushPopAndSetPid(Manager $manager, string $uuid, string $label): array
    {
        $this->assertTrue($manager->push([QueueTestHandler::class, 'success'], ['params' => ['id' => 3], 'smth' => $label], [], $uuid));
        $job = $manager->pop_batch()[0];
        $this->assertSame($uuid, $job['uuid']);
        $job['pid'] = \getmypid();
        $this->assertTrue($manager->set_pid($job));
        return $job;
    }

    private function assertFetchContains(array $result, string $uuid, string $state, int $total): void
    {
        $this->assertSame($total, $result['total']);
        $this->assertArrayHasKey($uuid, $result['items']);
        $this->assertSame($state, $result['items'][$uuid]['state']);
        $this->assertSame('db', $result['items'][$uuid]['driver']);
        $this->assertArrayHasKey('created_at_formatted', $result['items'][$uuid]);
    }
}
