<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;

/** Drains the stats spool every minute, so it never waits for an unrelated request or hits its cap. */
class FlushJob
{
    public function handle(): void
    {
        if (!StatsConnection::enabled()) {
            return;
        }

        try {
            StatsRecorder::instance()->drain();
        } catch (\Throwable $e) {
            Log::warning('[StatsFlush] failed: ' . $e->getMessage());
        }
    }
}
