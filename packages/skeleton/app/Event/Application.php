<?php
declare(strict_types=1);
namespace App\Event;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Event\Event;

final class Application
{
    private function __construct() {}

    public static function init(): void
    {
        $events = Event::instance();

        $events->on('queue.job.completed', function (string $jobType, array $payload): void {
            \Engine\Atomic\Core\Log::info("Queue job completed: {$jobType}");
        });

        $events->on('auth.login', function (string $userId): void {
            \Engine\Atomic\Core\Log::info("User logged in: {$userId}");
        });
    }
}
