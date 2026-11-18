<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

/** Where a queued job is (the queue's states, 'canceled' read as cancelled). */
enum JobState: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Pending = 'pending';
    case Running = 'running';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';

    public static function of(string $state): self
    {
        return $state === 'canceled' ? self::Cancelled : (self::tryFrom($state) ?? self::Pending);
    }

    /** Most urgent first: a queue lane cell takes the colour of its worst job. */
    public static function by_severity(): array
    {
        return [self::Failed, self::CancelRequested, self::Running, self::Pending, self::Cancelled, self::Completed];
    }

    public static function in_flow(): array
    {
        return [self::Completed, self::Running, self::Pending, self::CancelRequested, self::Cancelled, self::Failed];
    }

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Pending => 'Waiting',
            self::Running => 'Running',
            self::CancelRequested => 'Cancel requested',
            self::Cancelled => 'Cancelled',
        };
    }

    public function status(): Status
    {
        return match ($this) {
            self::Completed => new Status('check', Tone::Neutral, $this->label()),
            self::Failed => new Status('x', Tone::Bad, $this->label()),
            self::Pending => new Status('clock', Tone::Mute, $this->label()),
            self::Running => new Status('loader', Tone::Neutral, $this->label()),
            self::CancelRequested => new Status('alert', Tone::Warn, $this->label()),
            self::Cancelled => new Status('ban', Tone::Mute, $this->label()),
        };
    }
}
