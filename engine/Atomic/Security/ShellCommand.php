<?php

declare(strict_types=1);

namespace Engine\Atomic\Security;
if (!defined('ATOMIC_START')) exit;

final class ShellCommand
{
    private array $parts = [];

    private function __construct(
        private string $executable,
    ) {}

    public static function new(string $executable, string ...$args): self
    {
        $cmd = new self($executable);
        foreach ($args as $arg) {
            $cmd->arg($arg);
        }
        return $cmd;
    }

    public function arg(string $value): self
    {
        $this->parts[] = ['type' => 'arg', 'value' => $value];
        return $this;
    }

    public function option(string $name, ?string $value = null): self
    {
        $this->parts[] = ['type' => 'option', 'name' => $name, 'value' => $value];
        return $this;
    }

    public function toCommandLine(): string
    {
        $parts = [\escapeshellcmd($this->executable)];

        foreach ($this->parts as $part) {
            if ($part['type'] === 'arg') {
                $parts[] = \escapeshellarg($part['value']);
            } elseif ($part['type'] === 'option') {
                $parts[] = \escapeshellarg($part['name']);
                if ($part['value'] !== null) {
                    $parts[] = \escapeshellarg($part['value']);
                }
            }
        }

        return implode(' ', $parts);
    }

    public function execute(?string &$output = null, ?int &$exitCode = null): string
    {
        $command = $this->toCommandLine();
        $result = \exec($command, $outputLines, $exitCodeResult);
        $output = implode("\n", $outputLines);
        $exitCode = $exitCodeResult;
        return $result !== false ? $result : '';
    }

    public function __toString(): string
    {
        return $this->toCommandLine();
    }
}