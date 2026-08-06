<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Traits;

if (!defined('ATOMIC_START')) exit;

trait Singleton
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
