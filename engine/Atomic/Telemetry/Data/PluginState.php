<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

enum PluginState: string
{
    case Booted = 'booted';
    case Registered = 'registered';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Booted => 'Running',
            self::Registered => 'Registered',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Disabled => 'Disabled',
        };
    }

    public function status(): Status
    {
        return match ($this) {
            self::Booted => new Status('check', Tone::Neutral, $this->label()),
            self::Registered => new Status('clock', Tone::Mute, $this->label()),
            self::Failed => new Status('x', Tone::Bad, $this->label()),
            self::Skipped => new Status('alert', Tone::Warn, $this->label()),
            self::Disabled => new Status('ban', Tone::Mute, $this->label()),
        };
    }

    public function is_problem(): bool
    {
        return $this === self::Failed || $this === self::Skipped;
    }
}
