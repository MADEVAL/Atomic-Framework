<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Traits;

if (!defined('ATOMIC_START')) exit;

trait Singleton
{
    protected static ?self $instance = null;
    private static bool $resolving = false;

    public static function instance(...$args): static
    {
        if (static::$instance === null) {
            // Try Container first — enables seamless migration to DI
            if (!static::$resolving && ($container = \Engine\Atomic\Core\Container::global())) {
                if ($container->has(static::class)) {
                    static::$resolving = true;
                    try {
                        $resolved = $container->get(static::class);
                        if ($resolved instanceof static) {
                            static::$instance = $resolved;
                        }
                    } finally {
                        static::$resolving = false;
                    }
                    if (static::$instance !== null) {
                        return static::$instance;
                    }
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
