<?php
declare(strict_types=1);
namespace Engine\Atomic\Enums;

if (!defined('ATOMIC_START')) exit;

enum Language: string
{
    case EN = 'en';
    case ES = 'es';
    case FR = 'fr';
    case DE = 'de';
    case IT = 'it';
    case PT = 'pt';
    case NL = 'nl';
    case PL = 'pl';
    case RU = 'ru';
    case UK = 'uk';

    public function display_name(): string {
        return match($this) {
            self::EN => 'English',
            self::ES => 'Español',
            self::FR => 'Français',
            self::DE => 'Deutsch',
            self::IT => 'Italiano',
            self::PT => 'Português',
            self::NL => 'Nederlands',
            self::PL => 'Polski',
            self::RU => 'Русский',
            self::UK => 'Українська',
        };
    }

    public static function get_all(): array {
        return array_map(fn(self $case) => $case->value, self::cases());
    }
}