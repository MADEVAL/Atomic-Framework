<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Job
{
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;

    /** @param array<mixed> $payload */
    public function __construct(
        public readonly string $uuid,
        public readonly JobState $state,
        public readonly string $queue,
        public readonly string $driver,
        public readonly int $attempts,
        public readonly ?int $max_attempts,
        public readonly ?int $timeout,
        public readonly ?int $retry_delay,
        public readonly string $created,
        public readonly int $created_at,
        public readonly array $payload,
        public readonly ?JobError $error,
        public readonly ?string $reason,
        public readonly ?string $cancel_requested_at,
        public readonly ?string $cancelled_at,
    ) {}

    /** @param array<string, mixed> $row */
    public static function from_row(string $uuid, array $row): self
    {
        $exception = is_array($row['exception'] ?? null) && ($row['exception']['message'] ?? '') !== '' ? $row['exception'] : null;

        return new self(
            uuid: $uuid,
            state: JobState::of((string)($row['state'] ?? '')),
            queue: (string)($row['queue'] ?? ''),
            driver: (string)($row['driver'] ?? 'db') ?: 'db',
            attempts: (int)($row['attempts'] ?? 0),
            max_attempts: isset($row['max_attempts']) ? (int)$row['max_attempts'] : null,
            timeout: isset($row['timeout']) ? (int)$row['timeout'] : null,
            retry_delay: isset($row['retry_delay']) ? (int)$row['retry_delay'] : null,
            created: (string)($row['created_at_formatted'] ?? $row['created_at'] ?? ''),
            created_at: (int)($row['created_at'] ?? 0),
            payload: is_array($row['payload'] ?? null) ? $row['payload'] : [],
            error: $exception === null ? null : new JobError(
                (string)$exception['message'],
                isset($exception['file']) ? (string)$exception['file'] : null,
                isset($exception['line']) ? (int)$exception['line'] : null,
                isset($exception['trace_string']) && $exception['trace_string'] !== '' ? (string)$exception['trace_string'] : null,
            ),
            reason: isset($row['reason']) && $row['reason'] !== '' ? (string)$row['reason'] : null,
            cancel_requested_at: $row['cancel_requested_at_formatted'] ?? null,
            cancelled_at: $row['cancelled_at_formatted'] ?? null,
        );
    }

    public function title(): string
    {
        foreach (['job', 'class', 'handler'] as $key) {
            if (is_string($this->payload[$key] ?? null) && $this->payload[$key] !== '') {
                return $this->payload[$key];
            }
        }

        return $this->queue;
    }

    public function ago(int $now): string
    {
        if ($this->created_at <= 0) {
            return '';
        }
        $minutes = max(0, (int)round(($now - $this->created_at) / self::MINUTE));

        return match (true) {
            $minutes < self::HOUR / self::MINUTE => $minutes . 'm ago',
            $minutes < self::DAY / self::MINUTE => intdiv($minutes, self::HOUR / self::MINUTE) . 'h ago',
            default => intdiv($minutes, self::DAY / self::MINUTE) . 'd ago',
        };
    }

    public function report(): string
    {
        $error = $this->error?->text() ?? '';

        return 'Job ' . $this->title() . ' (' . $this->uuid . ")\n"
            . 'State: ' . $this->state->label() . ', attempt ' . $this->attempts . ' of ' . ($this->max_attempts ?? '-') . ', queue ' . $this->queue . "\n"
            . ($error !== '' ? "\n" . $error . "\n" : '')
            . "\nPayload:\n" . Json::pretty($this->payload);
    }
}
