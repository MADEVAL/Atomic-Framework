<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Console;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Style;

class Output
{
    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    /**
     * @param resource|null $stdout  Defaults to STDOUT constant or php://stdout.
     * @param resource|null $stderr  Defaults to STDERR constant or php://stderr.
     */
    public function __construct(mixed $stdout = null, mixed $stderr = null)
    {
        $this->stdout = $stdout ?? (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        $this->stderr = $stderr ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
    }

    public function write(string $message): void
    {
        $this->write_to($this->stdout, $message);
    }

    public function writeln(string $message = ''): void
    {
        $this->write($message . PHP_EOL);
    }

    public function prompt(string $message): void
    {
        $this->write_to($this->stderr, $message);
    }

    public function err(string $message): void
    {
        $this->write_to($this->stderr, $message . PHP_EOL);
    }

    public function section(string $title): void
    {
        $this->writeln(Style::bold($title));
    }

    public function field(string $label, string|int|float|bool|null $value): void
    {
        $this->writeln('  ' . Style::cyan($label . ':', true) . ' ' . $this->string_value($value));
    }

    public function success(string $message): void
    {
        $this->writeln('  ' . Style::success_label() . ' ' . $message);
    }

    public function failure(string $message): void
    {
        $this->err('  ' . Style::error_label() . ' ' . $message);
    }

    public function usage(string $command): void
    {
        $this->err(Style::bold('Usage:') . ' ' . Style::cyan('php atomic ' . CommandCatalog::display($command), true));
    }

    /** @param list<string> $items */
    public function error_list(string $title, array $items): void
    {
        $this->err('  ' . Style::bold($title));
        foreach ($items as $item) {
            $this->err('    ' . Style::cyan($item));
        }
    }

    public function error_hint(string $label, string $value): void
    {
        $this->err('  ' . Style::bold($label . ':'));
        $this->err('    ' . Style::cyan($value));
    }

    public function warning(string $message): void
    {
        $this->writeln('  ' . Style::warning_label() . ' ' . $message);
    }

    /** @param list<string> $lines */
    public function warning_box(string $title, array $lines = []): void
    {
        $content = array_merge([$title], $lines);
        $width = max(array_map(
            static fn(string $line): int => self::display_width($line),
            $content,
        ));
        $border = '+' . str_repeat('-', $width + 2) . '+';

        $this->err(Style::yellow($border, true));
        foreach ($content as $line) {
            $this->err(self::bordered_line($line, $width));
        }
        $this->err(Style::yellow($border, true));
    }

    public function check(string $status, string $name, string $message): void
    {
        $label = match (strtolower($status)) {
            'ok' => Style::green('[OK]', true),
            'warn' => Style::yellow('[WARN]', true),
            'fail' => Style::red('[FAIL]', true),
            'skip' => Style::cyan('[SKIP]', true),
            default => Style::bold('[' . strtoupper($status) . ']'),
        };

        $this->writeln('  ' . $label . ' ' . Style::cyan($name, true) . ' - ' . $message);
    }

    public function health_status(bool $healthy): void
    {
        $status = $healthy
            ? Style::green('HEALTHY', true)
            : Style::red('UNHEALTHY', true);

        $this->writeln('Status: ' . $status);
    }

    public static function plain(string $output): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $output) ?? $output;
    }

    private function write_to(mixed $stream, string $message): void
    {
        fwrite($stream, $message);
        fflush($stream);
    }

    private static function bordered_line(string $line, int $width): string
    {
        $offset = max(0, $width - self::display_width($line));
        return Style::yellow('|', true) . ' '
            . $line . str_repeat(' ', $offset) . ' '
            . Style::yellow('|', true);
    }

    private static function display_width(string $value): int
    {
        $plain = self::plain($value);
        return function_exists('mb_strwidth') ? mb_strwidth($plain, 'UTF-8') : strlen($plain);
    }

    private function string_value(string|int|float|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return $value === null ? '' : (string)$value;
    }

    /** @return resource */
    public function stdout(): mixed
    {
        return $this->stdout;
    }

    /** @return resource */
    public function stderr(): mixed
    {
        return $this->stderr;
    }
}
