<?php
declare(strict_types=1);
namespace App\Providers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ServiceProvider;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Event\Event;
use Engine\Atomic\Hook\ApplicationHook;

final class ApplicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Event::instance()->on('queue.job.completed', function (string $jobType, array $payload): void {
            Log::info("Queue job completed: {$jobType}");
        });

        Event::instance()->on('auth.login', function (string $userId): void {
            Log::info("User logged in: {$userId}");
        });

        add_filter('body_class', function (array $classes): array {
            if (is_page('dashboard')) {
                $classes[] = 'dashboard-page';
            }
            return $classes;
        });

        add_action(ApplicationHook::APP_BOOTSTRAPPED, function (): void {
            Log::info('Application bootstrapped | url=' . App::atomic()->get('PATH'));
        });
    }
}
