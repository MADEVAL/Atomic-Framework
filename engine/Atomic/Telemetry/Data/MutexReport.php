<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class MutexReport
{
    /**
     * @param list<MutexDriverInfo> $drivers
     * @param list<LockInfo> $locks
     */
    public function __construct(
        public readonly ?string $driver,
        public readonly array $drivers,
        public readonly array $locks,
    ) {}

    /** @return list<LockInfo> */
    public function expired(): array
    {
        return array_values(array_filter($this->locks, static fn (LockInfo $lock): bool => $lock->is_expired()));
    }

    public function to_array(): array
    {
        return [
            'driver' => $this->driver,
            'drivers' => array_map(static fn (MutexDriverInfo $driver): array => $driver->to_array(), $this->drivers),
            'locks' => array_map(static fn (LockInfo $lock): array => $lock->to_array(), $this->locks),
        ];
    }
}
