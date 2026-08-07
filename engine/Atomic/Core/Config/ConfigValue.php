<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Config;
if (!defined('ATOMIC_START')) exit;

abstract class ConfigValue
{
    protected mixed $default = null;
    protected bool $hasDefault = false;
    protected bool $required = false;
    protected bool $nullable = false;
    protected array $constraints = [];

    public function default(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function required(): static
    {
        $this->required = true;
        return $this;
    }

    public function nullable(): static
    {
        $this->nullable = true;
        return $this;
    }

    public function min(int|float $n): static
    {
        $this->constraints['min'] = $n;
        return $this;
    }

    public function max(int|float $n): static
    {
        $this->constraints['max'] = $n;
        return $this;
    }

    public function minLength(int $n): static
    {
        $this->constraints['minLength'] = $n;
        return $this;
    }

    public function maxLength(int $n): static
    {
        $this->constraints['maxLength'] = $n;
        return $this;
    }

    public function pattern(string $regex): static
    {
        $this->constraints['pattern'] = $regex;
        return $this;
    }

    /** @param list<mixed> $allowed */
    public function in(array $allowed): static
    {
        $this->constraints['in'] = $allowed;
        return $this;
    }

    public function notEmpty(): static
    {
        $this->constraints['notEmpty'] = true;
        return $this;
    }

    public function isRequired(): bool { return $this->required; }
    public function isNullable(): bool { return $this->nullable; }
    public function hasDefault(): bool { return $this->hasDefault; }
    public function getDefault(): mixed { return $this->default; }

    abstract public function cast(mixed $raw): mixed;
    abstract public function typeName(): string;
}