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

    public function warning(string $message): void
    {
        $this->writeln('  ' . Style::warning_label() . ' ' . $message);
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
