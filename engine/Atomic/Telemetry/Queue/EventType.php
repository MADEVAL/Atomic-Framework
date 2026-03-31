<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Queue;

if (!defined( 'ATOMIC_START' ) ) exit;

enum EventType: int {
    case JOB_CREATED = 1;
    case JOB_FETCHED = 2;
    case JOB_SUCCESS = 3;
    case JOB_FAILED  = 4;
    case JOB_RETRIED = 5;
    case JOB_INCOMPLETE_HANDLED = 6;

    public function description(): string {
        return match($this) {
            self::JOB_CREATED => 'Job was created',
            self::JOB_FETCHED => 'Job was fetched',
            self::JOB_SUCCESS => 'Job finished successfully',
            self::JOB_FAILED  => 'Job finished with failure',
            self::JOB_RETRIED => 'Job retried',
            self::JOB_INCOMPLETE_HANDLED => 'Incomplete job detected by the monitor and handled',
        };
    }
}
