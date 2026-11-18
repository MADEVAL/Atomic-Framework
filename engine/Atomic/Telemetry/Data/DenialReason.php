<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

enum DenialReason: string
{
    case InsufficientBalance = 'insufficient_balance';
    case Pacing = 'pacing';
    case NotEntitled = 'not_entitled';
    case DuplicateReservation = 'duplicate_reservation';

    public function label(): string
    {
        return match ($this) {
            self::InsufficientBalance => 'Insufficient balance',
            self::Pacing => 'Pacing',
            self::NotEntitled => 'Not entitled',
            self::DuplicateReservation => 'Duplicate',
        };
    }

    public static function label_of(string $reason): string
    {
        return self::tryFrom($reason)?->label() ?? $reason;
    }
}
