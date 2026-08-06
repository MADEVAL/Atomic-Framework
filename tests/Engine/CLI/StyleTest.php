<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\Style;
use PHPUnit\Framework\TestCase;
use Tests\Support\Environment;

class StyleTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::clear_cli_color();
    }

    protected function tearDown(): void
    {
        Environment::clear_cli_color();
    }

    public function test_warning_label_uses_color_when_forced(): void
    {
        Environment::set('FORCE_COLOR', '1');

        $this->assertStringContainsString("\033[33m", Style::warning_label());
        $this->assertStringContainsString('[WARNING]', Style::warning_label());
    }

    public function test_bold_returns_plain_text_when_color_is_disabled(): void
    {
        Environment::set('NO_COLOR', '1');

        $this->assertSame('hello', Style::bold('hello'));
    }
}
