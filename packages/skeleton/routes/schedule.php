<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$schedule = \Engine\Atomic\Scheduler\Scheduler::instance();

// Clean old logs daily at 3:00 AM
$schedule->job(\Engine\Atomic\Scheduler\Jobs\LogCleanupJob::class)->dailyAt('03:00');

// Session GC is handled by PHP's built-in garbage collection
// via session.gc_probability / session.gc_divisor and the driver's gc() method.

// Process next queue job every 5 minutes
$schedule->exec('php atomic queue:work --once')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('queue:process-next');
