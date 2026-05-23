<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Telemetry\Queue\Entry;
use Engine\Atomic\Telemetry\Queue\EventType;
use Tests\Engine\Queue\Support\QueueDbTestCase;

final class QueueTelemetryBadPathTest extends QueueDbTestCase
{
    public function test_unknown_fetch_events_driver_returns_empty_list(): void
    {
        $telemetry = new TelemetryManager();

        $this->assertSame([], $telemetry->fetch_events('missing', 'queue', $this->new_uuid()));
    }

    public function test_entry_struct_preserves_null_event_type_message_and_ttl(): void
    {
        $entry = new Entry(null, 'queue', 'batch', 'job', 'custom message', 123);
        $struct = $entry->get_struct();

        $this->assertNull($struct['event_type_id']);
        $this->assertSame('queue', $struct['queue']);
        $this->assertSame('batch', $struct['uuid_batch']);
        $this->assertSame('job', $struct['uuid_job']);
        $this->assertSame('custom message', $struct['message']);
        $this->assertSame(123, $struct['ttl']);
    }

    public function test_push_telemetry_rejects_missing_context(): void
    {
        $atomic = App::instance();
        $atomic->set('ATOMIC_QUEUE_CURRENT_UUID', '');
        $atomic->set('ATOMIC_QUEUE_CURRENT_BATCH_UUID', '');
        $atomic->set('ATOMIC_QUEUE_CURRENT_NAME', '');
        $atomic->set('ATOMIC_QUEUE_CURRENT_EVENT_TYPE', EventType::JOB_CREATED);

        $telemetry = new TelemetryManager();

        $this->assertIsBool($telemetry->push_telemetry('missing context'));
    }
}
