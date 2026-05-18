<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Monitor;

if (!defined( 'ATOMIC_START' ) ) exit;

interface ProcessProbeInterface
{
    public function exists(int $pid): array;
    public function signal(int $pid, int $signal): bool;
    public function sleep(int $seconds): void;
    public function usleep(int $microseconds): void;
}
