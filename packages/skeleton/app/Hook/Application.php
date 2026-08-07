<?php
declare(strict_types=1);
namespace App\Hook;

if (!defined('ATOMIC_START')) exit;

final class Application
{
    private function __construct() {}

    public static function init(): void
    {
        add_filter('body_class', function (array $classes): array {
            if (is_page('dashboard')) {
                $classes[] = 'dashboard-page';
            }
            return $classes;
        });

        add_action('app_bootstrapped', function (): void {
            \Engine\Atomic\Core\Log::info('Application bootstrapped', [
                'url' => \Engine\Atomic\Core\App::atomic()->get('PATH'),
            ]);
        });
    }
}
