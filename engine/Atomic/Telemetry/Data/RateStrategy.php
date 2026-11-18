<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

enum RateStrategy: string
{
    case Fixed = 'fixed';
    case Sliding = 'sliding';
    case Cooldown = 'cooldown';
    case Concurrency = 'concurrency';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed window',
            self::Sliding => 'Sliding window',
            self::Cooldown => 'Cooldown',
            self::Concurrency => 'Concurrency',
        };
    }

    public function short_label(): string
    {
        return ucfirst($this->value);
    }
}
