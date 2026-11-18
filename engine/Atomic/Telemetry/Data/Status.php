<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Status
{
    public function __construct(
        public readonly string $icon,
        public readonly Tone $tone,
        public readonly string $label,
    ) {}
}
