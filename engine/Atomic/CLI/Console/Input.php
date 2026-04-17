<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Console;

if (!defined('ATOMIC_START')) exit;

class Input
{
    /** @var resource */
    private mixed $stdin;

    /** Cached TTY detection result. */
    private ?bool $interactive = null;

    /**
     * @param Output        $output  Receives prompt labels (written to stderr).
     * @param resource|null $stdin   Defaults to STDIN constant or php://stdin.
     */
    public function __construct(private readonly Output $output, mixed $stdin = null)
    {
        $this->stdin = $stdin ?? (defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));
    }

    public function is_interactive(): bool
    {
        if ($this->interactive !== null) {
            return $this->interactive;
        }

        return $this->interactive = (
            function_exists('stream_isatty') && @stream_isatty($this->stdin)
        );
    }

    public function read_line(): string
    {
        return trim((string) fgets($this->stdin));
    }

    public function read_secret(string $label, string $default = ''): string
    {
        $this->output->prompt("  {$label}: ");

        if (!$this->has_stty()) {
            $value = $this->read_line();
            $this->output->err('');
            return $value === '' ? $default : $value;
        }

        $savedState = trim((string) shell_exec('stty -g'));
        shell_exec('stty -echo');

        try {
            $value = $this->read_line();
        } finally {
            shell_exec("stty {$savedState}");
            $this->output->err('');
        }

        return $value === '' ? $default : $value;
    }

    private function has_stty(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        return (bool) @shell_exec('stty 2> ' . $null);
    }
}
