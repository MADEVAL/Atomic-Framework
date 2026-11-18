<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Tools\Transient;

/** Cached for 60s, so sidebar refreshes don't hit every subsystem. */
class SummaryReporter
{
    private const CACHE_KEY = 'telemetry.summary';
    private const CACHE_TTL = 60;

    public function summary(): array
    {
        try {
            $cached = Transient::get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['queues'], $cached['exceptions'])) {
                return $cached;
            }
        } catch (\Throwable $e) {
        }

        $summary = $this->compute();

        try {
            Transient::set(self::CACHE_KEY, $summary, self::CACHE_TTL);
        } catch (\Throwable $e) {
        }

        return $summary;
    }

    private function compute(): array
    {
        return [
            'queues' => (new QueueReporter())->snapshot(),
            'scheduled' => (new SchedulerReporter())->snapshot(),
            'migrations' => (new MigrationsReporter())->snapshot(),
            'quota' => (new QuotaReporter())->snapshot(),
            'ratelimit' => (new RateLimitReporter())->snapshot(),
            'plugins' => (new PluginsReporter())->snapshot(),
            'exceptions' => (new ExceptionsReporter())->snapshot(),
            'mutex' => (new MutexReporter())->snapshot(),
            'security' => (new SecurityReporter())->snapshot(),
        ];
    }
}
