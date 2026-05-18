<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Console;

if (!defined('ATOMIC_START')) exit;

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

    public static function plain(string $output): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $output) ?? $output;
    }

    private function write_to(mixed $stream, string $message): void
    {
        fwrite($stream, $message);
        fflush($stream);
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
