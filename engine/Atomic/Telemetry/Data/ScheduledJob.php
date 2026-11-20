<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class ScheduledJob
{
    /**
     * @param list<\DateTimeImmutable> $next_runs
     * @param list<JobRun> $runs newest first
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $command,
        public readonly string $cron,
        public readonly string $human,
        public readonly ?string $owner,
        public readonly bool $enabled,
        public readonly JobOutcome $last_outcome,
        public readonly ?string $last_status,
        public readonly ?int $last_run_at,
        public readonly ?float $duration,
        public readonly array $next_runs,
        public readonly array $runs,
        public readonly bool $internal = false,
    ) {}

    public function never_run(): bool
    {
        return $this->last_run_at === null;
    }

    /** Closures registered with call() are named after their command; show just the class. */
    public function display_name(): string
    {
        return $this->name === $this->command ? substr((string)strrchr('\\' . $this->name, '\\'), 1) : $this->name;
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'command' => $this->command,
            'cron' => $this->cron,
            'human' => $this->human,
            'target' => $this->command,
            'owner' => $this->owner,
            'internal' => $this->internal,
            'enabled' => $this->enabled,
            'state' => $this->enabled ? 'enabled' : 'paused',
            'last_outcome' => $this->last_outcome->value,
            'last_status' => $this->last_status,
            'last_run_at' => $this->last_run_at,
            'dur' => $this->duration,
            'next_runs' => array_map(static fn (\DateTimeImmutable $date): string => $date->format(DATE_ATOM), $this->next_runs),
            'runs' => array_map(static fn (JobRun $run): array => $run->to_array(), $this->runs),
        ];
    }
}
