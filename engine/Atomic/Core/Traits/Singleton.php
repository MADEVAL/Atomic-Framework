<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Traits;

if (!defined('ATOMIC_START')) exit;

trait Singleton
{
    protected static ?self $instance = null;

    public static function instance(...$args): static
    {
        return static::$instance ??= new static(...$args);
    }

    public static function reset(): void
    {
        static::$instance = null;
    }

    private function __clone(): void {}
}
