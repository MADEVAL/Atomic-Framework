<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class MigrationSource
{
    public function __construct(
        public readonly string $name,
        public readonly string $file,
        public readonly string $code,
        public readonly bool $truncated,
    ) {}

    public function lines(): int
    {
        return substr_count($this->code, "\n") + 1;
    }

    /** @return array{file:string, code:string, truncated:bool} */
    public function to_array(): array
    {
        return ['file' => $this->file, 'code' => $this->code, 'truncated' => $this->truncated];
    }
}
