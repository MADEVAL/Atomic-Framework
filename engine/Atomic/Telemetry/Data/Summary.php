<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Summary
{
    public function __construct(
        public readonly QueueTotals $queues,
        public readonly int $failing_tasks,
        public readonly int $new_exceptions_today,
        public readonly int $plugin_problems,
        public readonly int $pending_migrations,
        public readonly int $migrations_needing_attention,
        public readonly int $expired_locks,
        public readonly int $locked_accounts,
        public readonly int $limited_keys,
    ) {}

    /** @param array<string, mixed> $summary SummaryReporter::summary() */
    public static function from_array(array $summary): self
    {
        $int = static fn (string $section, string $key): int => (int)($summary[$section][$key] ?? 0);

        return new self(
            queues: QueueTotals::from_snapshot((array)($summary['queues'] ?? [])),
            failing_tasks: $int('scheduled', 'failing'),
            new_exceptions_today: $int('exceptions', 'new_today'),
            plugin_problems: $int('plugins', 'problems'),
            pending_migrations: $int('migrations', 'pending'),
            migrations_needing_attention: $int('migrations', 'attention'),
            expired_locks: $int('mutex', 'expired'),
            locked_accounts: $int('security', 'locked'),
            limited_keys: $int('ratelimit', 'limited'),
        );
    }

    /** @return array<string, int> nav badge id => count */
    public function badges(): array
    {
        return [
            'q-badge' => $this->queues->count(JobState::Failed),
            'm-badge' => $this->pending_migrations,
            'rl-badge' => $this->limited_keys,
            'pl-badge' => $this->plugin_problems,
            'mx-badge' => $this->expired_locks,
            'sy-badge' => $this->locked_accounts,
        ];
    }
}
