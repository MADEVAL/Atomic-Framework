<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class PluginInfo
{
    /**
     * @param list<PluginDependency> $deps
     * @param list<PluginRequirement> $requirements
     */
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        public readonly string $version,
        public readonly bool $enabled,
        public readonly PluginState $state,
        public readonly ?int $load_order,
        public readonly array $deps,
        public readonly ?string $error,
        public readonly array $requirements,
        public readonly string $migration_source,
        public readonly bool $has_migrations,
        public readonly StepOutcome $construct,
        public readonly StepOutcome $register,
        public readonly StepOutcome $boot,
    ) {}

    public function to_array(): array
    {
        return [
            'name' => $this->name,
            'class' => $this->class,
            'version' => $this->version,
            'enabled' => $this->enabled,
            'state' => $this->state->value,
            'load_order' => $this->load_order,
            'deps' => array_map(static fn (PluginDependency $dep): array => $dep->to_array(), $this->deps),
            'error' => $this->error,
            'requirements' => array_map(static fn (PluginRequirement $req): array => $req->to_array(), $this->requirements),
            'migration_source' => $this->migration_source,
            'has_migrations' => $this->has_migrations,
            'steps' => [
                'construct' => $this->construct->value,
                'register' => $this->register->value,
                'boot' => $this->boot->value,
            ],
        ];
    }
}
