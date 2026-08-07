<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Config\V2;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Config\ConfigSchema;
use Engine\Atomic\Core\Config\ConfigValue;

final class Config
{
    /** @var array<string, ConfigValue> */
    private array $definitions;

    /** @param array<string, mixed> $values */
    public function __construct(
        private readonly array $values,
        ?array $definitions = null,
    ) {
        $this->definitions = $definitions ?? ConfigSchema::definitions();
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);
        return (string)$value;
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default);
        return (int)$value;
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) return $value;
        if (is_string($value)) return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        return (bool)$value;
    }

    public function float(string $key, ?float $default = null): float
    {
        $value = $this->get($key, $default);
        return (float)$value;
    }

    /** @return mixed[] */
    public function array(string $key, ?array $default = null): array
    {
        $value = $this->get($key, $default);
        return (array)$value;
    }

    /** @return string[] */
    public function csv(string $key, ?array $default = null): array
    {
        $value = $this->get($key, $default);
        if (is_array($value)) return $value;
        if ($value === '' || $value === null) return $default ?? [];
        return array_map('trim', explode(',', (string)$value));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /** @throws \RuntimeException */
    public function validate(): void
    {
        $errors = [];

        foreach ($this->definitions as $key => $definition) {
            $value = $this->values[$key] ?? null;

            if ($definition->isRequired() && ($value === null || $value === '')) {
                $errors[] = "{$key}: required key is missing";
                continue;
            }
        }

        if (!empty($errors)) {
            throw new \RuntimeException("Configuration validation failed:\n  - " . implode("\n  - ", $errors));
        }
    }

    public function applyToHive(\Base $f3): void
    {
        foreach ($this->values as $key => $value) {
            $f3->set($key, $value);
        }
    }
}