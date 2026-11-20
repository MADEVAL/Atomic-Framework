<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Telemetry\Stats\FlushJob;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\TrimJob;

if (StatsConnection::enabled()) {
    $schedule = Scheduler::instance();
    $schedule->job(TrimJob::class)->daily()->at('03:20')->internal();
    // Drains the spool of requests that never opened the database.
    $schedule->job(FlushJob::class)->every_minute()->internal();
}
