<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;

class PluginManager
{
    protected ?App $atomic = null;
    private static ?self $instance = null;
    protected array $plugins = [];
    protected array $registered = [];
    protected array $booted = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    protected function getAtomic(): App
    {
        return $this->atomic ??= App::instance();
    }

    public function register(Plugin $plugin): void
    {
        $name = $plugin->getPluginName();
        
        if (isset($this->plugins[$name])) {
            //Log::warning("Plugin {$name} already registered");
            return;
        }

        $this->plugins[$name] = $plugin;
    }

    public function registerAll(): void
    {
        foreach ($this->plugins as $name => $plugin) {
            if (isset($this->registered[$name])) continue;
            
            if (!$plugin->isEnabled()) {
                //Log::debug("Plugin {$name} disabled");
                continue;
            }

            $this->checkDependencies($plugin);
            
            try {
                $plugin->register();
                $this->registered[$name] = true;
            } catch (\Throwable $e) {
                Log::error("Plugin {$name} registration failed: " . $e->getMessage());
            }
        }
    }

    public function bootAll(): void
    {
        foreach ($this->registered as $name => $_) {
            if (isset($this->booted[$name])) continue;
            
            try {
                $this->plugins[$name]->boot();
                $this->booted[$name] = true;
            } catch (\Throwable $e) {
                Log::error("Plugin {$name} boot failed: " . $e->getMessage());
            }
        }
    }

    public function loadUserPlugins(): void
    {
        $pluginsPath = rtrim((string)$this->getAtomic()->get('USER_PLUGINS'), '/\\') . DIRECTORY_SEPARATOR;
        
        if (!is_dir($pluginsPath)) {
            Log::debug("User plugins directory not found: {$pluginsPath}");
            return;
        }

        $dirs = array_filter(glob($pluginsPath . '*'), 'is_dir');
        
        foreach ($dirs as $dir) {
            $pluginFile = $dir . DIRECTORY_SEPARATOR . 'plugin.php';
            if (file_exists($pluginFile)) {
                try {
                    require_once $pluginFile;
                    //Log::debug("User plugin loaded from: {$dir}");
                } catch (\Throwable $e) {
                    Log::error("Failed to load user plugin from {$dir}: " . $e->getMessage());
                }
            }
        }
    }

    protected function checkDependencies(Plugin $plugin): void
    {
        $deps = $plugin->getDependencies();
        foreach ($deps as $dep) {
            if (!isset($this->plugins[$dep]) || !$this->plugins[$dep]->isEnabled()) {
                throw new \RuntimeException("Plugin {$plugin->getPluginName()} requires {$dep}");
            }
        }
    }

    public function get(string $name): ?Plugin
    {
        return $this->plugins[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    public function all(): array
    {
        return $this->plugins;
    }

    public function enable(string $name): bool
    {
        if (!isset($this->plugins[$name])) return false;
        
        $this->plugins[$name]->setEnabled(true);
        $this->plugins[$name]->activate();
        return true;
    }

    public function disable(string $name): bool
    {
        if (!isset($this->plugins[$name])) return false;
        
        $this->plugins[$name]->deactivate();
        $this->plugins[$name]->setEnabled(false);
        unset($this->registered[$name], $this->booted[$name]);
        return true;
    }

    private function __clone() {}
}
