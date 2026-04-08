<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\Style;
use PHPUnit\Framework\TestCase;

class StyleTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearEnvironment('NO_COLOR');
        $this->clearEnvironment('FORCE_COLOR');
        $this->clearEnvironment('CLICOLOR_FORCE');
        $this->clearEnvironment('CLICOLOR');
        $this->clearEnvironment('TERM');
        $this->clearEnvironment('TERM_PROGRAM');
        $this->clearEnvironment('COLORTERM');
        $this->clearEnvironment('ANSICON');
        $this->clearEnvironment('ConEmuANSI');
        $this->clearEnvironment('MSYSTEM');
    }

    public function test_warningLabel_uses_color_when_forced(): void
    {
        $this->setEnvironment('FORCE_COLOR', '1');

        $this->assertStringContainsString("\033[33m", Style::warningLabel());
        $this->assertStringContainsString('[WARNING]', Style::warningLabel());
    }

    public function test_bold_returns_plain_text_when_color_is_disabled(): void
    {
        $this->setEnvironment('NO_COLOR', '1');

        $this->assertSame('hello', Style::bold('hello'));
    }

    private function setEnvironment(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }

    private function clearEnvironment(string $name): void
    {
        putenv($name);
        unset($_SERVER[$name]);
    }
}
