<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

final class Shell
{
    /** @param array<string, int> $badges badge id => count */
    public function __construct(
        public readonly string $tab,
        public readonly Html $pane,
        public readonly array $badges,
        public readonly bool $public_access,
        public readonly string $range,
    ) {}

    public function badge(string $id): int
    {
        return $this->badges[$id] ?? 0;
    }
}
