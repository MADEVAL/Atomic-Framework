<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\Capabilities;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase
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

    public function test_supportsColors_defaults_to_false_for_non_tty_stream(): void
    {
        $stream = fopen('php://memory', 'rb');

        try {
            $this->assertFalse(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supportsColors_respects_force_color(): void
    {
        $this->setEnvironment('FORCE_COLOR', '1');

        $stream = fopen('php://memory', 'rb');

        try {
            $this->assertTrue(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supportsColors_respects_clicolor_force(): void
    {
        $this->setEnvironment('CLICOLOR_FORCE', '1');

        $stream = fopen('php://memory', 'rb');

        try {
            $this->assertTrue(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supportsColors_disables_color_when_no_color_is_set(): void
    {
        $this->setEnvironment('NO_COLOR', '1');
        $this->setEnvironment('FORCE_COLOR', '1');

        $stream = fopen('php://memory', 'rb');

        try {
            $this->assertFalse(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supportsColors_ignores_empty_no_color_value(): void
    {
        $this->setEnvironment('NO_COLOR', '');
        $this->setEnvironment('FORCE_COLOR', '1');

        $stream = fopen('php://memory', 'rb');

        try {
            $this->assertTrue(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supportsColors_ignores_empty_force_color_value(): void
    {
        $this->setEnvironment('FORCE_COLOR', '');

        $stream = fopen('php://memory', 'rb');

        try {
            $this->assertFalse(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
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
