<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

enum JobOutcome: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case Skipped = 'skipped';
    case Never = 'never run';
    case Unknown = 'unknown';

    public static function of(?string $status): self
    {
        return match ($status) {
            null => self::Never,
            'ok', 'failed', 'timeout', 'skipped' => self::from($status),
            default => self::Unknown,
        };
    }

    public function is_failing(): bool
    {
        return $this === self::Failed || $this === self::Timeout;
    }

    /** The filter key; one word, as rows list their keys space-separated. */
    public function key(): string
    {
        return $this === self::Never ? 'never' : $this->value;
    }

    public function text(?string $raw_status = null): string
    {
        return match ($this) {
            self::Ok => 'Succeeded',
            self::Failed => 'Failed',
            self::Timeout => 'Timed out',
            self::Skipped => 'Skipped (overlap)',
            self::Never => 'Never run',
            self::Unknown => 'Unknown (' . ($raw_status ?? 'null') . ')',
        };
    }

    public function status(): Status
    {
        return match ($this) {
            self::Ok => new Status('check', Tone::Neutral, 'Succeeded'),
            self::Failed => new Status('x', Tone::Bad, 'Failed'),
            self::Timeout => new Status('clock', Tone::Warn, 'Timed out'),
            self::Never => new Status('clock', Tone::Mute, 'Not run yet'),
            default => new Status('alert', Tone::Mute, $this->value),
        };
    }
}
