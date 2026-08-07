<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Traits;

if (!defined('ATOMIC_START')) exit;

trait Singleton
{
    protected static ?self $instance = null;

    public static function instance(...$args): static
    {
        if (static::$instance === null) {
            // Try Container first — enables seamless migration to DI
            if ($container = \Engine\Atomic\Core\Container::global()) {
                if ($container->has(static::class)) {
                    return $container->get(static::class);
                }
            }
            static::$instance = new static(...$args);
        }

        return static::$instance;
    }

    public static function reset(): void
    {
        static::$instance = null;
    }

    private function __clone(): void {}
}
