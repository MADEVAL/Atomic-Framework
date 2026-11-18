<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class JobError
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly ?string $trace,
    ) {}

    public function text(): string
    {
        return $this->message . "\n"
            . ($this->file ? 'at ' . $this->file . ':' . $this->line . "\n" : '')
            . ($this->trace ? "\n" . $this->trace : '');
    }
}
