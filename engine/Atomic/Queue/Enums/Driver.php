<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Enums;

if (!defined( 'ATOMIC_START' ) ) exit;

enum Driver: string
{
    case REDIS = 'redis';
    case DB = 'db';

    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }
    
    public static function is_valid(string $driver): bool
    {
        return in_array($driver, self::all(), true);
    }
}
