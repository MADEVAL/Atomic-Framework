<?php
declare(strict_types=1);

namespace Tests\Engine\Enums;

use Engine\Atomic\Enums\Currency;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    public function test_all_currencies_have_string_values(): void
    {
        foreach (Currency::cases() as $c) {
            $this->assertIsString($c->value);
            $this->assertNotEmpty($c->value);
        }
    }

    public function test_expected_currencies_exist(): void
    {
        $expected = ['USD', 'EUR', 'RUB', 'UAH'];
        $actual = array_map(fn(Currency $c) => $c->value, Currency::cases());
        foreach ($expected as $code) {
            $this->assertContains($code, $actual, "Currency {$code} missing");
        }
    }

    public function test_symbol(): void
    {
        $this->assertSame('$', Currency::USD->symbol());
        $this->assertSame('€', Currency::EUR->symbol());
        $this->assertSame('₽', Currency::RUB->symbol());
        $this->assertSame('₴', Currency::UAH->symbol());
    }

    public function test_display_name(): void
    {
        $this->assertSame('US Dollar', Currency::USD->display_name());
        $this->assertSame('Euro', Currency::EUR->display_name());
        $this->assertSame('Ukrainian Hryvnia', Currency::UAH->display_name());
    }

    public function test_to_array(): void
    {
        $arr = Currency::USD->to_array();
        $this->assertArrayHasKey('value', $arr);
        $this->assertArrayHasKey('symbol', $arr);
        $this->assertArrayHasKey('display_name', $arr);
        $this->assertSame('USD', $arr['value']);
        $this->assertSame('$', $arr['symbol']);
    }

    public function test_get_available(): void
    {
        $avail = Currency::get_available();
        $this->assertCount(4, $avail);
        $this->assertContains(Currency::USD, $avail);
        $this->assertContains(Currency::EUR, $avail);
    }

    public function test_from_string(): void
    {
        $this->assertSame(Currency::USD, Currency::from('USD'));
        $this->assertSame(Currency::UAH, Currency::from('UAH'));
    }

    public function test_tryFrom_invalid(): void
    {
        $this->assertNull(Currency::tryFrom('INVALID'));
    }
}
