<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Lockout
{
    public function __construct(
        public readonly string $identifier,
        public readonly int $count,
        public readonly int $max,
        public readonly ?int $first_ago,
        public readonly bool $locked,
        public readonly int $remaining,
    ) {}

    /** @param array<string, mixed> $lockout a lockout of SecurityReporter::report(), redacted */
    public static function from_array(array $lockout): self
    {
        return new self(
            identifier: (string)($lockout['identifier'] ?? ''),
            count: (int)($lockout['count'] ?? 0),
            max: (int)($lockout['max'] ?? 0),
            first_ago: isset($lockout['first_ago']) ? (int)$lockout['first_ago'] : null,
            locked: (bool)($lockout['locked'] ?? false),
            remaining: (int)($lockout['remaining'] ?? 0),
        );
    }

    public function tone(): Tone
    {
        return match (true) {
            $this->locked => Tone::Bad,
            $this->count >= $this->max - 1 => Tone::Warn,
            default => Tone::Neutral,
        };
    }

    public function status(): Status
    {
        return $this->locked
            ? new Status('ban', Tone::Bad, 'Locked')
            : new Status('alert', $this->tone() === Tone::Neutral ? Tone::Mute : $this->tone(), 'Failed attempts');
    }

    public function usage(): float
    {
        return $this->max > 0 ? $this->count / $this->max : 1.0;
    }
}
