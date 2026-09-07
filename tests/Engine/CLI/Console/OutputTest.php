<?php
declare(strict_types=1);

namespace Tests\Engine\CLI\Console;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Style;
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

    public function test_root_usage_renders_the_cli_entrypoint_with_emphasis(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);

        try {
            $output->root_usage();

            $this->assertSame(
                'Usage: php atomic <command> [options]' . PHP_EOL,
                Output::plain(StreamCapture::read($stream, true))
            );
        } finally {
            fclose($stream);
        }
    }

    public function test_root_usage_colors_the_entrypoint_when_color_is_forced(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);
        Environment::set('FORCE_COLOR', '1');

        try {
            $output->root_usage();
            $raw = StreamCapture::read($stream);

            $this->assertStringContainsString("\033[36mphp atomic <command> [options]", $raw);
        } finally {
            Environment::clear_cli_color();
            fclose($stream);
        }
    }

    public function test_field_keeps_labels_in_the_default_color(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);
        Environment::set('FORCE_COLOR', '1');

        try {
            $output->field('Pending migrations', Style::yellow('2', true));
            $raw = StreamCapture::read($stream);

            $this->assertStringNotContainsString("\033[36mPending migrations:", $raw);
            $this->assertStringContainsString("\033[33m2", $raw);
        } finally {
            Environment::clear_cli_color();
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

    public function test_warning_box_renders_a_bordered_notice(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);

        try {
            $output->warning_box('Migration history is in legacy mode', [
                'Run php atomic migrations/upgrade to update it.',
            ]);

            $plain = Output::plain(StreamCapture::read($stream, true));
            $lines = explode(PHP_EOL, trim($plain));

            $this->assertSame($lines[0], $lines[3]);
            $this->assertStringStartsWith('| Migration history is in legacy mode', $lines[1]);
            $this->assertStringStartsWith('| Run php atomic migrations/upgrade', $lines[2]);
            $this->assertStringStartsWith('+', $lines[0]);
            $this->assertStringEndsWith('+', $lines[0]);
        } finally {
            fclose($stream);
        }
    }

    public function test_warning_box_preserves_colored_content_and_border_alignment(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);
        Environment::set('FORCE_COLOR', '1');

        try {
            $output->warning_box(Style::yellow('Legacy mode', true), [
                Style::cyan('Preview: php atomic migrations/upgrade --dry-run', true),
            ]);

            $raw = StreamCapture::read($stream);
            $plain = Output::plain($raw);
            $lines = explode(PHP_EOL, trim($plain));

            $this->assertStringContainsString("\033[33m", $raw);
            $this->assertStringContainsString("\033[36m", $raw);
            $this->assertSame(strlen($lines[0]), strlen($lines[1]));
            $this->assertSame(strlen($lines[0]), strlen($lines[3]));
            $this->assertSame($lines[0], $lines[3]);
        } finally {
            Environment::clear_cli_color();
            fclose($stream);
        }
    }

    public function test_warning_box_aligns_unicode_content_to_the_border(): void
    {
        $stream = StreamCapture::memory();
        $output = new Output($stream, $stream);

        try {
            $output->warning_box('Dry run complete - upgrade is still required', [
                'The migration history remains in legacy mode.',
                'Run: php atomic migrations/upgrade',
            ]);

            $lines = explode(PHP_EOL, trim(Output::plain(StreamCapture::read($stream, true))));
            $line_widths = array_map(static fn(string $line): int => mb_strwidth($line, 'UTF-8'), $lines);

            $this->assertCount(5, $lines);
            $this->assertCount(1, array_unique($line_widths));
        } finally {
            fclose($stream);
        }
    }
}
