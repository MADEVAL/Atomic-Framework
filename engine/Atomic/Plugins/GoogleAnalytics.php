<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Plugin;

class GoogleAnalytics extends Plugin
{
    protected array $dependencies = ['Google']; 
    
    protected function getName(): string
    {
        return 'GoogleAnalytics';
    }
    
    public function register(): void
    {
        $this->atomic->set('PLUGIN.GoogleAnalytics.registered', true);
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.GoogleAnalytics.booted', true);
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.GoogleAnalytics.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.GoogleAnalytics.active', false);
    }

}