<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class JobEvent
{
    private const COMPLETED = 3;
    private const FAILED = 4;

    public function __construct(
        public readonly int $type,
        public readonly string $description,
        public readonly string $created,
        public readonly string $batch,
    ) {}

    /** @param array<string, mixed> $row */
    public static function from_row(array $row): self
    {
        return new self(
            (int)($row['event_type_id'] ?? 0),
            (string)($row['event_description'] ?? ''),
            (string)($row['created_at_formatted'] ?? ''),
            (string)($row['uuid_batch'] ?? ''),
        );
    }

    public function tone(): Tone
    {
        return match ($this->type) {
            self::COMPLETED => Tone::Ok,
            self::FAILED => Tone::Bad,
            default => Tone::Neutral,
        };
    }
}
