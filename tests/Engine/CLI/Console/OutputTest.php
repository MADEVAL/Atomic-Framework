<?php
declare(strict_types=1);

namespace Tests\Engine\CLI\Console;

use Engine\Atomic\CLI\Console\Output;
use PHPUnit\Framework\TestCase;
use Tests\Support\Environment;
use Tests\Support\StreamCapture;

class OutputTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::clear_cli_color();
    }

    protected function tearDown(): void
    {
        Environment::clear_cli_color();
    }

    public function test_error_list_renders_a_readable_indented_list(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);

        try {
            $output->error_list('Did you mean?', [
                'php atomic queue/worker',
                'php atomic schedule/work',
            ]);

            $this->assertSame(
                '  Did you mean?' . PHP_EOL
                    . '    php atomic queue/worker' . PHP_EOL
                    . '    php atomic schedule/work' . PHP_EOL,
                Output::plain(StreamCapture::read($stream, true))
            );
        } finally {
            fclose($stream);
        }
    }

    public function test_usage_renders_the_command_with_emphasis(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);

        try {
            $output->usage('queue/cancel');

            $this->assertSame(
                'Usage: php atomic queue/cancel <job_uuid>' . PHP_EOL,
                Output::plain(StreamCapture::read($stream, true))
            );
        } finally {
            fclose($stream);
        }
    }

    public function test_usage_colors_the_command_when_color_is_forced(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);
        Environment::set('FORCE_COLOR', '1');

        try {
            $output->usage('queue/cancel');
            $raw = StreamCapture::read($stream);

            $this->assertStringContainsString("\033[36mphp atomic queue/cancel <job_uuid>", $raw);
        } finally {
            Environment::clear_cli_color();
            fclose($stream);
        }
    }

    public function test_error_hint_renders_label_and_value_on_separate_lines(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);

        try {
            $output->error_hint('Help', 'php atomic help');

            $this->assertSame(
                '  Help:' . PHP_EOL . '    php atomic help' . PHP_EOL,
                Output::plain(StreamCapture::read($stream, true))
            );
        } finally {
            fclose($stream);
        }
    }
}
