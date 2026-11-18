<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class MutexDriverInfo
{
    public function __construct(
        public readonly string $name,
        public readonly bool $available,
        public readonly bool $active,
    ) {}

    /** @return array{name:string, available:bool, active:bool} */
    public function to_array(): array
    {
        return ['name' => $this->name, 'available' => $this->available, 'active' => $this->active];
    }
}
