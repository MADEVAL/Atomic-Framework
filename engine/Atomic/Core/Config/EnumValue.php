<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Config;
if (!defined('ATOMIC_START')) exit;

final class EnumValue extends ConfigValue
{
    /** @param class-string<\BackedEnum> $enumClass */
    public function __construct(private readonly string $enumClass) {}

    public function cast(mixed $raw): mixed
    {
        if ($raw instanceof $this->enumClass) return $raw;
        return $this->enumClass::from($raw);
    }
    public function typeName(): string { return 'enum<' . $this->enumClass . '>'; }
}