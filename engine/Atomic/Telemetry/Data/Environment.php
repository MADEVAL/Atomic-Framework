<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Environment
{
    private const KEYS = ['php', 'f3', 'atomic', 'system', 'db'];

    /** @param array<string, EnvGroup> $groups by key; a missing group (no database) is absent */
    public function __construct(public readonly array $groups) {}

    /** @return list<EnvGroup> in display order */
    public function shown(): array
    {
        return array_values(array_filter(array_map(fn (string $key): ?EnvGroup => $this->groups[$key] ?? null, EnvGroup::ORDER)));
    }

    public function text(): string
    {
        return implode("\n\n", array_map(static fn (EnvGroup $group): string => $group->text(), $this->shown()));
    }

    /** @return array<string, array<string, mixed>|null> */
    public function to_array(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = isset($this->groups[$key]) ? $this->groups[$key]->to_array() : null;
        }

        return $out;
    }
}
