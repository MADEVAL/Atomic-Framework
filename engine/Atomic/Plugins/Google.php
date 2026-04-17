<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Plugin;

class Google extends Plugin
{
    protected function get_name(): string
    {
        return 'Google';
    }

    public function register(): void
    {
        $this->atomic->set('PLUGIN.Google.registered', true);
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.Google.booted', true);
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.Google.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.Google.active', false);
    }
}