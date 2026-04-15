<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Interfaces;

if (!defined( 'ATOMIC_START' ) ) exit;

interface Telemetry {
    public function push_telemetry(array $entry): bool;
    public function fetch_completed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array;
    public function fetch_failed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array;
    public function fetch_in_progress_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array;
    public function fetch_events(string $queue, string $uuid): array;
}