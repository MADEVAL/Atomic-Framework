<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class PluginRequirement
{
    public function __construct(
        public readonly string $package,
        public readonly bool $missing,
    ) {}

    /** @return array{package:string, missing:bool} */
    public function to_array(): array
    {
        return ['package' => $this->package, 'missing' => $this->missing];
    }
}
