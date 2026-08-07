<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Config;
if (!defined('ATOMIC_START')) exit;

final class ConfigSchema
{
    private static array $definitions = [];

    public static function define(string $key, ConfigValue $definition): void
    {
        self::$definitions[$key] = $definition;
    }

    public static function string(string $key): StringValue
    {
        $v = new StringValue();
        self::$definitions[$key] = $v;
        return $v;
    }

    public static function int(string $key): IntValue
    {
        $v = new IntValue();
        self::$definitions[$key] = $v;
        return $v;
    }

    public static function bool(string $key): BoolValue
    {
        $v = new BoolValue();
        self::$definitions[$key] = $v;
        return $v;
    }

    public static function float(string $key): FloatValue
    {
        $v = new FloatValue();
        self::$definitions[$key] = $v;
        return $v;
    }

    public static function array(string $key): ArrayValue
    {
        $v = new ArrayValue();
        self::$definitions[$key] = $v;
        return $v;
    }

    public static function csv(string $key): CsvValue
    {
        $v = new CsvValue();
        self::$definitions[$key] = $v;
        return $v;
    }

    /** @param class-string<\BackedEnum> $enumClass */
    public static function enum(string $key, string $enumClass): EnumValue
    {
        $v = new EnumValue($enumClass);
        self::$definitions[$key] = $v;
        return $v;
    }

    /** @return array<string, ConfigValue> */
    public static function definitions(): array
    {
        return self::$definitions;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::$definitions as $key => $definition) {
            if ($definition->isRequired() && !$definition->hasDefault() && !$definition->isNullable()) {
                continue;
            }
            $defaults[$key] = $definition->isNullable() ? null : $definition->getDefault();
        }
        return $defaults;
    }

    public static function has(string $key): bool
    {
        return isset(self::$definitions[$key]);
    }

    public static function reset(): void
    {
        self::$definitions = [];
    }
}