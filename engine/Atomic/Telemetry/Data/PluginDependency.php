<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class PluginDependency
{
    public function __construct(
        public readonly string $name,
        public readonly PluginState $state,
    ) {}

    /** @return array{name:string, state:string} */
    public function to_array(): array
    {
        return ['name' => $this->name, 'state' => $this->state->value];
    }
}
