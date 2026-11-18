<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$schedule = \Engine\Atomic\Scheduler\Scheduler::instance();

// Clean old logs daily at 3:00 AM
$schedule->job(\Engine\Atomic\Scheduler\Jobs\LogCleanupJob::class)->daily()->at('03:00');

// Trim telemetry statistics (counters, events, registry sidecars) daily at 3:20 AM
$schedule->job(\Engine\Atomic\Telemetry\Stats\TrimJob::class)->daily()->at('03:20');

// Persist telemetry stats spooled by requests that never opened the database
$schedule->job(\Engine\Atomic\Telemetry\Stats\FlushJob::class)->every_minute();

// Session GC is handled by PHP's built-in garbage collection
// via session.gc_probability / session.gc_divisor and the driver's gc() method.

// Process next queue job every 5 minutes
$schedule->exec('php atomic queue:work --once')
    ->every_five_minutes()
    ->without_overlapping()
    ->name('queue:process-next');
