<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Interfaces;

if (!defined( 'ATOMIC_START' ) ) exit;

interface Base {
    public function push(array $payload, array $options = []): bool;
    public function pop_batch(string $queue, int $limit): array;
    public function release(array $job, int $delay): bool;
    public function mark_failed(array $job, \Throwable $exception): bool;
    public function mark_completed(array $job): bool;
    public function find_by_uuid(string $uuid): ?array;
    public function cancel(string $uuid): ?array;
    public function mark_cancel_requested(string $uuid): bool;
    public function mark_cancelled(array $job, ?string $reason = null): bool;
    public function is_cancel_requested(string $uuid): bool;
    public function set_pid(array $job): bool;
}
