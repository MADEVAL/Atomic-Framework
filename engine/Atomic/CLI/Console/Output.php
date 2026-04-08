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
        fwrite($this->stdout, $message);
        fflush($this->stdout);
    }

    public function writeln(string $message = ''): void
    {
        $this->write($message . PHP_EOL);
    }

    public function prompt(string $message): void
    {
        fwrite($this->stderr, $message);
        fflush($this->stderr);
    }

    public function err(string $message): void
    {
        fwrite($this->stderr, $message . PHP_EOL);
        fflush($this->stderr);
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
