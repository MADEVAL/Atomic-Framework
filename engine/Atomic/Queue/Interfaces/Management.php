<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Interfaces;

if (!defined( 'ATOMIC_START' ) ) exit;

interface Management {
    public function load_stuck_jobs(array $exclude, string $queue = '*'): array;
    public function load_jobs_in_progress(string $queue = '*'): array;
    public function handle_incomplete_job(array $job): bool;
    public function retry(string $queue = '*'): bool;
    public function retry_by_uuid(string $uuid): bool;
    public function delete_job(string $uuid): bool;
}