<?php
declare(strict_types=1);
namespace Engine\Atomic\Enums;

if (!defined('ATOMIC_START')) exit;

enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case RUB = 'RUB';
    case UAH = 'UAH';

    public static function get_available(): array {
        return [
            self::USD,
            self::EUR,
            self::RUB,
            self::UAH,
        ];
    }

    public function symbol(): string {
        return match($this) {
            self::USD => '$',
            self::EUR => '€',
            self::RUB => '₽',
            self::UAH => '₴',
        };
    }

    public function display_name(): string {
        return match($this) {
            self::USD => 'US Dollar',
            self::EUR => 'Euro',
            self::RUB => 'Russian Ruble',
            self::UAH => 'Ukrainian Hryvnia',
        };
    }

    public function to_array(): array {
        return [
            'value' => $this->value,
            'symbol' => $this->symbol(),
            'display_name' => $this->display_name(),
        ];
    }
}
