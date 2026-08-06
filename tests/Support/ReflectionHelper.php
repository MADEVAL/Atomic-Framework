<?php
declare(strict_types=1);

namespace Tests\Support;

final class ReflectionHelper
{
    public static function property(object|string $target, string $name): \ReflectionProperty
    {
        return new \ReflectionProperty($target, $name);
    }

    public static function get(object|string $target, string $name, ?object $object = null): mixed
    {
        $property = self::property($target, $name);
        return $property->getValue(is_object($target) ? $target : $object);
    }

    public static function set(object|string $target, string $name, mixed $value, ?object $object = null): void
    {
        $property = self::property($target, $name);
        $property->setValue(is_object($target) ? $target : $object, $value);
    }

    public static function invoke(object|string $target, string $name, array $args = [], ?object $object = null): mixed
    {
        $method = new \ReflectionMethod($target, $name);
        $ref_args = [];
        foreach ($args as $key => &$value) {
            $ref_args[$key] = &$value;
        }

        return $method->invokeArgs(is_object($target) ? $target : $object, $ref_args);
    }

    public static function new_without_constructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    public static function constant(object|string $target, string $name): mixed
    {
        return (new \ReflectionClass($target))->getConstant($name);
    }
}
