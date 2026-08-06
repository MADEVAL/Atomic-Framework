<?php
declare(strict_types=1);
namespace Engine\Atomic\Enums;

if (!defined('ATOMIC_START')) exit;

enum Role: string
{
    case ADMIN  = 'admin';
    case SELLER = 'seller';
    case BUYER  = 'buyer';
    case MODERATOR = 'moderator';
    case SUPPORT = 'support';

    public static function all(): array {
        return array_column(self::cases(), 'value');
    }

    public static function is_valid(string $role): bool {
        return in_array($role, self::all(), true);
    }
}
