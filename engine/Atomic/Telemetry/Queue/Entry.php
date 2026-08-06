<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Queue;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\ID;
use Engine\Atomic\Telemetry\Queue\EventType;

class Entry
{
    private string $uuid;
    private string $batch_uuid;
    private string $job_uuid;
    private EventType|null $event_type_id;
    private string $message;
    private string $queue;
    private int $created_at;
    private int $ttl;

    public function __construct(
        EventType|null $event_type_id,
        string $queue,
        string $batch_uuid,
        string $job_uuid,
        string $message = '',
        int $ttl = 0
    ) {
        $this->uuid = ID::uuid_v4();
        $this->batch_uuid = $batch_uuid;
        $this->job_uuid = $job_uuid;
        $this->event_type_id = $event_type_id;
        $this->queue = $queue;
        $this->created_at = \time();
        $this->message = $message;
        $this->ttl = $ttl;
    }

    public function get_struct(): array
    {
        return [
            'uuid' => $this->uuid,
            'uuid_batch' => $this->batch_uuid,
            'uuid_job' => $this->job_uuid,
            'event_type_id' => $this->event_type_id?->value,
            'queue' => $this->queue,
            'created_at' => $this->created_at,
            'message' => $this->message,
            'ttl' => $this->ttl,
        ];
    }
}
