<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class MigrationEntry
{
    public function __construct(
        public readonly string $source,
        public readonly string $name,
        public readonly ?string $file,
        public readonly bool $applied,
        public readonly MigrationIntegrity $integrity,
        public readonly ?string $batch_uuid,
        public readonly ?string $applied_at,
    ) {}

    public function is_framework(): bool
    {
        return $this->source === 'framework';
    }

    public function is_pending(): bool
    {
        return !$this->applied;
    }

    /** Applied, but its file can't be trusted to match what ran. */
    public function needs_attention(): bool
    {
        return $this->applied && $this->integrity !== MigrationIntegrity::Verified;
    }

    public function status(): Status
    {
        return match (true) {
            $this->is_pending() => new Status('clock', Tone::Mute, 'Pending'),
            $this->needs_attention() => new Status('alert', $this->integrity === MigrationIntegrity::FileUnavailable ? Tone::Bad : Tone::Warn, $this->integrity->label()),
            default => new Status('check', Tone::Neutral, 'Applied'),
        };
    }

    /** @return array{source:string, name:string, file:?string, status:string, integrity:string, batch_uuid:?string, applied_at:?string} */
    public function to_array(): array
    {
        return [
            'source' => $this->source,
            'name' => $this->name,
            'file' => $this->file,
            'status' => $this->applied ? 'applied' : 'pending',
            'integrity' => $this->integrity->value,
            'batch_uuid' => $this->batch_uuid,
            'applied_at' => $this->applied_at,
        ];
    }
}
