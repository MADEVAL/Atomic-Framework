<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

enum StepOutcome: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Pending = 'registered'; // registered, waiting to boot

    public function text(): ?string
    {
        return match ($this) {
            self::Ok => 'Completed',
            self::Failed => 'Failed',
            self::Skipped => 'Not reached',
            self::Pending => null,
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Ok => Tone::Ok,
            self::Failed => Tone::Bad,
            default => Tone::Neutral,
        };
    }
}
