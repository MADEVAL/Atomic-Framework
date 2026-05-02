<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\RouteLoader;

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

    protected function get_atomic(): App
    {
        return $this->atomic ??= App::instance();
    }

    public function register(Plugin $plugin): void
    {
        $name = $plugin->get_plugin_name();
        
        if (isset($this->plugins[$name])) {
            //Log::warning("Plugin {$name} already registered");
            return;
        }

        $this->plugins[$name] = $plugin;
    }

    public function register_all(): void
    {
        foreach ($this->plugins as $name => $plugin) {
            if (isset($this->registered[$name])) continue;
            
            if (!$plugin->is_enabled()) {
                //Log::debug("Plugin {$name} disabled");
                continue;
            }

            try {
                $this->check_dependencies($plugin);
                $plugin->register();
                $this->registered[$name] = true;
            } catch (\Throwable $e) {
                Log::error("Plugin {$name} registration failed: " . $e->getMessage());
            }
        }
    }

    public function boot_all(): void
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

        $this->load_plugin_routes();
    }

    protected function load_plugin_routes(): void
    {
        $atomic = App::instance();
        $routeLoader = RouteLoader::instance();
        $fileNames = $routeLoader->get_filenames_for($atomic->detect_request_type());

        foreach ($this->booted as $name => $_) {
            $plugin = $this->plugins[$name];
            $routesDir = $plugin->get_plugin_path() . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR;

            foreach ($fileNames as $fileName) {
                $file = $routesDir . $fileName;

                if (!is_file($file)) continue;

                try {
                    require $file;
                } catch (\Throwable $e) {
                    Log::error("Plugin {$name}: failed to load routes/{$fileName}: " . $e->getMessage());
                }
            }
        }
    }

    public function load_user_plugins(): void
    {
        $pluginsPath = rtrim((string)$this->get_atomic()->get('USER_PLUGINS'), '/\\') . DIRECTORY_SEPARATOR;
        $resolvedPluginsPath = realpath($pluginsPath);
        
        if ($resolvedPluginsPath === false || !is_dir($resolvedPluginsPath)) {
            Log::debug("User plugins directory not found: {$pluginsPath}");
            return;
        }

        $dirs = array_filter(glob($resolvedPluginsPath . DIRECTORY_SEPARATOR . '*'), 'is_dir');
        
        foreach ($dirs as $dir) {
            $pluginFile = $dir . DIRECTORY_SEPARATOR . 'plugin.php';
            $resolvedPluginFile = realpath($pluginFile);
            if (
                $resolvedPluginFile !== false
                && is_file($resolvedPluginFile)
                && is_readable($resolvedPluginFile)
                && str_starts_with($resolvedPluginFile, $resolvedPluginsPath . DIRECTORY_SEPARATOR)
            ) {
                try {
                    require_once $resolvedPluginFile;
                    //Log::debug("User plugin loaded from: {$dir}");
                } catch (\Throwable $e) {
                    Log::error("Failed to load user plugin from {$dir}: " . $e->getMessage());
                }
            }
        }
    }

    protected function check_dependencies(Plugin $plugin): void
    {
        foreach ($plugin->get_dependencies() as $dependency_class) {
            $dependency = $this->resolve_dependency($plugin, $dependency_class);
            if (!$dependency->is_enabled()) {
                throw new \RuntimeException("Plugin {$plugin->get_plugin_name()} requires {$dependency_class}, but it is disabled.");
            }
        }
    }

    public function resolve_dependency(Plugin $plugin, mixed $dependency_class): Plugin
    {
        if (!is_string($dependency_class)) {
            throw new \RuntimeException("Plugin {$plugin->get_plugin_name()} has invalid dependency; use PluginClass::class.");
        }

        if (!class_exists($dependency_class)) {
            throw new \RuntimeException("Plugin {$plugin->get_plugin_name()} requires missing plugin class {$dependency_class}.");
        }

        if (!is_subclass_of($dependency_class, Plugin::class)) {
            throw new \RuntimeException("Plugin {$plugin->get_plugin_name()} dependency {$dependency_class} must extend " . Plugin::class . '.');
        }

        $dependency = $this->get_by_class($dependency_class);
        if ($dependency === null) {
            throw new \RuntimeException("Plugin {$plugin->get_plugin_name()} requires {$dependency_class}, but it is not registered.");
        }

        return $dependency;
    }

    public function get_by_class(string $class): ?Plugin
    {
        if (!is_subclass_of($class, Plugin::class)) {
            return null;
        }

        foreach ($this->plugins as $plugin) {
            if ($plugin instanceof $class) {
                return $plugin;
            }
        }

        return null;
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
        
        $this->plugins[$name]->set_enabled(true);
        $this->plugins[$name]->activate();
        return true;
    }

    public function disable(string $name): bool
    {
        if (!isset($this->plugins[$name])) return false;
        
        $this->plugins[$name]->deactivate();
        $this->plugins[$name]->set_enabled(false);
        unset($this->registered[$name], $this->booted[$name]);
        return true;
    }

    private function __clone() {}
}
