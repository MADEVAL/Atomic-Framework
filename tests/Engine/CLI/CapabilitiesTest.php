<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\Capabilities;
use PHPUnit\Framework\TestCase;
use Tests\Support\Environment;
use Tests\Support\StreamCapture;

class CapabilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::clear_cli_color();
    }

    protected function tearDown(): void
    {
        Environment::clear_cli_color();
    }

    public function test_supports_colors_defaults_to_false_for_non_tty_stream(): void
    {
        $stream = StreamCapture::memory('rb');

        try {
            $this->assertFalse(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supports_colors_respects_force_color(): void
    {
        Environment::set('FORCE_COLOR', '1');

        $stream = StreamCapture::memory('rb');

        try {
            $this->assertTrue(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supports_colors_respects_clicolor_force(): void
    {
        Environment::set('CLICOLOR_FORCE', '1');

        $stream = StreamCapture::memory('rb');

        try {
            $this->assertTrue(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supports_colors_disables_color_when_no_color_is_set(): void
    {
        Environment::set('NO_COLOR', '1');
        Environment::set('FORCE_COLOR', '1');

        $stream = StreamCapture::memory('rb');

        try {
            $this->assertFalse(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supports_colors_ignores_empty_no_color_value(): void
    {
        Environment::set('NO_COLOR', '');
        Environment::set('FORCE_COLOR', '1');

        $stream = StreamCapture::memory('rb');

        try {
            $this->assertTrue(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_supports_colors_ignores_empty_force_color_value(): void
    {
        Environment::set('FORCE_COLOR', '');

        $stream = StreamCapture::memory('rb');

        try {
            $this->assertFalse(Capabilities::supports_colors($stream));
        } finally {
            fclose($stream);
        }
    }

}
