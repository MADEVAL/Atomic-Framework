<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

enum Range: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public static function of(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string)$value))) ?? self::Day;
    }

    public function text(): string
    {
        return match ($this) {
            self::Day => 'last 24h',
            self::Week => 'last 7 days',
            self::Month => 'last 30 days',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Day => '24 hours',
            self::Week => '7 days',
            self::Month => '30 days',
        };
    }

    /** @return array{0:string, 1:string, 2:string} chart axis: start, middle, end */
    public function axis(): array
    {
        return match ($this) {
            self::Day => ['24h ago', '12h', 'now'],
            self::Week => ['7d ago', '3d', 'now'],
            self::Month => ['30d ago', '15d', 'now'],
        };
    }
}
