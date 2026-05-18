<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Queue\Support\QueueDriverTestHarness;

final class QueueRemainingEdgeTest extends TestCase
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
        $this->restoreQueueState();
        parent::tearDown();
    }

    public function test_fetch_all_jobs_with_invalid_uuid_returns_empty_template_without_driver_lookup(): void
    {
        $telemetry = new TelemetryManager();

        $result = $telemetry->fetch_all_jobs('*', ['uuid' => 'not-a-uuid'], 3, 7);

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(3, $result['page']);
        $this->assertSame(7, $result['per_page']);
        $this->assertSame(State::state_totals_template(), $result['state_totals']);
    }

    public function test_fetch_events_returns_empty_for_driver_not_loaded_in_manager(): void
    {
        $telemetry = new TelemetryManager();

        $this->assertSame([], $telemetry->fetch_events('redis', '*', $this->newUuid()));
        $this->assertSame([], $telemetry->fetch_events('missing', '*', $this->newUuid()));
    }

    public function test_state_display_map_and_all_include_every_case(): void
    {
        $all = State::all();
        $map = State::display_map();

        $this->assertSame($all, \array_keys($map));
        $this->assertNotContains('active', $all);
        $this->assertSame(State::RUNNING->value, State::aggregate(State::RUNNING->value));
    }

}
