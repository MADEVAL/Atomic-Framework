<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$schedule = \Engine\Atomic\Scheduler\Scheduler::instance();

// Clean old logs daily at 3:00 AM
$schedule->job(\Engine\Atomic\Scheduler\Jobs\LogCleanupJob::class)->dailyAt('03:00');

// Clean expired sessions every hour
$schedule->call(function (): void {
    \Engine\Atomic\Session\Session::instance()->gc(3600);
})->hourly()->name('session:gc');

// Process next queue job every 5 minutes
$schedule->exec('php atomic queue:work --once')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('queue:process-next');
