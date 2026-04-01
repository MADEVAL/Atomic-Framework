<?php
declare(strict_types=1);

namespace Tests\Engine\Enums;

use Engine\Atomic\Enums\Language;
use PHPUnit\Framework\TestCase;

class LanguageTest extends TestCase
{
    public function test_all_languages_have_string_values(): void
    {
        foreach (Language::cases() as $lang) {
            $this->assertIsString($lang->value);
            $this->assertSame(2, strlen($lang->value), "Language {$lang->name} value is not 2 chars");
        }
    }

    public function test_expected_languages_exist(): void
    {
        $expected = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'uk'];
        $actual = Language::get_all();
        foreach ($expected as $code) {
            $this->assertContains($code, $actual, "Language '{$code}' missing");
        }
    }

    public function test_display_name(): void
    {
        $this->assertSame('English', Language::EN->display_name());
        $this->assertSame('Українська', Language::UK->display_name());
        $this->assertSame('Русский', Language::RU->display_name());
        $this->assertSame('Deutsch', Language::DE->display_name());
    }

    public function test_get_all_returns_value_strings(): void
    {
        $all = Language::get_all();
        $this->assertCount(count(Language::cases()), $all);
        foreach ($all as $v) {
            $this->assertIsString($v);
        }
    }

    public function test_from_string(): void
    {
        $this->assertSame(Language::EN, Language::from('en'));
        $this->assertSame(Language::UK, Language::from('uk'));
    }

    public function test_tryFrom_invalid(): void
    {
        $this->assertNull(Language::tryFrom('xx'));
    }
}
