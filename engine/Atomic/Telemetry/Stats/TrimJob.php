<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;

/** Lifetime totals are never trimmed. */
class TrimJob
{
    public function handle(): void
    {
        // Scheduler run history is kept even with statistics off, so it is
        // trimmed regardless of the statistics switch.
        $runs = \Engine\Atomic\Scheduler\RunLogger::trim(time() - StatsConnection::retention_days() * 86400);
        if (!StatsConnection::enabled()) {
            return;
        }
        $db = StatsConnection::db();
        if ($db === null) {
            Log::debug('[StatsTrim] statistics storage unavailable, nothing trimmed.');
            return;
        }

        try {
            $store = new Adapters\DB();
            $now = time();

            $counter_before = $now - StatsConnection::retention_days() * 86400;
            $event_before = $now - StatsConnection::event_retention_days() * 86400;
            $rate_key_before = $now - 86400;
            $lockout_before = $now - StatsConnection::event_retention_days() * 86400;

            $counters = $store->trim_counters($counter_before);
            $events = $store->trim_events($event_before);
            $rate_keys = $store->prune_rate_keys($rate_key_before);
            $lockouts = $store->prune_lockouts($lockout_before);

            Log::debug(sprintf(
                '[StatsTrim] counters=%d events=%d rate_keys=%d lockouts=%d scheduler_runs=%d',
                $counters,
                $events,
                $rate_keys,
                $lockouts,
                $runs,
            ));
        } catch (\Throwable $e) {
            Log::warning('[StatsTrim] failed: ' . $e->getMessage());
        }
    }
}
