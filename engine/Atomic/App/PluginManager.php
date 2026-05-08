<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\RouteLoader;
use Engine\Atomic\Exceptions\PluginDependencyException;

class PluginManager
{
    protected ?App $atomic = null;
    private static ?self $instance = null;
    protected array $plugins = [];
    protected array $registered = [];
    protected array $booted = [];
    protected array $loaded_route_types = [];

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

        $this->load_plugin_autoload($plugin->get_plugin_path(), $name);
        $this->plugins[$name] = $plugin;
    }

    public function register_all(): void
    {
        foreach ($this->ordered_plugins($this->plugins) as $name => $plugin) {
            if (isset($this->registered[$name])) continue;
            
            if (!$plugin->is_enabled()) {
                //Log::debug("Plugin {$name} disabled");
                continue;
            }

            try {
                $this->check_dependencies($plugin);
                $this->check_dependency_state($plugin, $this->registered, 'registered successfully');
                $plugin->assert_runtime_requirements();
                $plugin->register();
                $this->registered[$name] = true;
            } catch (\Throwable $e) {
                Log::error("Plugin {$name} registration failed: " . $e->getMessage());
                if ($e instanceof PluginDependencyException) {
                    throw $e;
                }
            }
        }
    }

    public function boot_all(): void
    {
        $registered_plugins = array_intersect_key($this->plugins, $this->registered);
        foreach ($this->ordered_plugins($registered_plugins) as $name => $plugin) {
            if (isset($this->booted[$name])) continue;

            try {
                $this->check_dependency_state($plugin, $this->booted, 'booted successfully');
                $plugin->assert_runtime_requirements();
                $plugin->boot();
                $this->booted[$name] = true;
            } catch (\Throwable $e) {
                Log::error("Plugin {$name} boot failed: " . $e->getMessage());
                if ($e instanceof PluginDependencyException) {
                    throw $e;
                }
            }
        }

    }

    protected function load_plugin_routes(): void
    {
        $atomic = App::instance();
        $this->load_plugin_routes_for($atomic->detect_request_type());
    }

    public function load_plugin_routes_for(string $request_type): array
    {
        $request_type = strtolower(trim($request_type));
        if (isset($this->loaded_route_types[$request_type])) {
            return [];
        }
        if ($this->booted === []) {
            return [];
        }

        $route_loader = RouteLoader::instance();
        $file_names = $route_loader->get_filenames_for($request_type);
        $loaded_files = [];

        foreach ($this->booted as $name => $_) {
            $plugin = $this->plugins[$name];
            $routes_dir = $plugin->get_plugin_path() . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR;
            $atomic = App::instance();

            foreach ($file_names as $file_name) {
                $file = $routes_dir . $file_name;

                if (!is_file($file)) continue;

                try {
                    require $file;
                    $resolved_file = realpath($file);
                    $loaded_files[] = $resolved_file !== false ? $resolved_file : $file;
                } catch (\Throwable $e) {
                    Log::error("Plugin {$name}: failed to load routes/{$file_name}: " . $e->getMessage());
                }
            }
        }

        $this->loaded_route_types[$request_type] = true;
        return $loaded_files;
    }

    public function load_user_plugins(): void
    {
        $plugins_path = rtrim((string)$this->get_atomic()->get('USER_PLUGINS'), '/\\') . DIRECTORY_SEPARATOR;
        $resolved_plugins_path = realpath($plugins_path);
        
        if ($resolved_plugins_path === false || !is_dir($resolved_plugins_path)) {
            Log::debug("User plugins directory not found: {$plugins_path}");
            return;
        }

        $dirs = array_filter(glob($resolved_plugins_path . DIRECTORY_SEPARATOR . '*'), 'is_dir');
        
        foreach ($dirs as $dir) {
            $plugin_file = $dir . DIRECTORY_SEPARATOR . 'plugin.php';
            $resolved_plugin_file = realpath($plugin_file);
            if (
                $resolved_plugin_file !== false
                && is_file($resolved_plugin_file)
                && is_readable($resolved_plugin_file)
                && str_starts_with($resolved_plugin_file, $resolved_plugins_path . DIRECTORY_SEPARATOR)
            ) {
                try {
                    $this->load_plugin_autoload($dir, basename($dir));
                    require_once $resolved_plugin_file;
                    //Log::debug("User plugin loaded from: {$dir}");
                } catch (\Throwable $e) {
                    Log::error("Failed to load user plugin from {$dir}: " . $e->getMessage());
                }
            }
        }
    }

    protected function load_plugin_autoload(string $plugin_dir, string $plugin_name): void
    {
        $autoload_file = $plugin_dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $composer_file = $plugin_dir . DIRECTORY_SEPARATOR . 'composer.json';

        $resolved_autoload_file = realpath($autoload_file);
        if (
            $resolved_autoload_file !== false
            && is_file($resolved_autoload_file)
            && is_readable($resolved_autoload_file)
            && str_starts_with($resolved_autoload_file, $plugin_dir . DIRECTORY_SEPARATOR)
        ) {
            require_once $resolved_autoload_file;
            return;
        }

        if (is_file($composer_file)) {
            Log::warning(
                "Plugin {$plugin_name} has composer.json but vendor/autoload.php is missing. "
                . 'Run composer install in the plugin directory or install dependencies in the root application.'
            );
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

    protected function check_dependency_state(Plugin $plugin, array $state, string $label): void
    {
        foreach ($plugin->get_dependencies() as $dependency_class) {
            $dependency = $this->resolve_dependency($plugin, $dependency_class);
            if (!isset($state[$dependency->get_plugin_name()])) {
                throw new \RuntimeException("Plugin {$plugin->get_plugin_name()} requires {$dependency_class}, but it was not {$label}.");
            }
        }
    }

    protected function ordered_plugins(array $plugins): array
    {
        $visiting = [];
        $visited = [];
        $skipped = [];
        $ordered = [];

        foreach (array_keys($plugins) as $name) {
            $this->order_plugin($name, $plugins, [], $visiting, $visited, $skipped, $ordered);
        }

        return $ordered;
    }

    protected function order_plugin(
        string $name,
        array $plugins,
        array $stack,
        array &$visiting,
        array &$visited,
        array &$skipped,
        array &$ordered
    ): bool {
        if (isset($visited[$name])) return true;
        if (isset($skipped[$name])) return false;

        if (isset($visiting[$name])) {
            $cycle_start = array_search($name, $stack, true);
            $cycle = $cycle_start === false ? [$name] : array_slice($stack, $cycle_start);
            foreach ($cycle as $cycle_name) {
                $skipped[$cycle_name] = true;
            }
            Log::error('Plugin dependency cycle detected: ' . implode(' -> ', array_merge($cycle, [$name])));
            return false;
        }

        $plugin = $plugins[$name] ?? null;
        if (!$plugin instanceof Plugin) return false;

        $visiting[$name] = true;
        $stack[] = $name;

        foreach ($plugin->get_dependencies() as $dependency_class) {
            try {
                $dependency = $this->resolve_dependency($plugin, $dependency_class);
            } catch (\Throwable $e) {
                Log::error("Plugin {$name} dependency failed: " . $e->getMessage());
                unset($visiting[$name]);
                $skipped[$name] = true;
                return false;
            }

            $dependency_name = $dependency->get_plugin_name();
            if (!isset($plugins[$dependency_name])) {
                unset($visiting[$name]);
                $skipped[$name] = true;
                return false;
            }

            if (!$this->order_plugin($dependency_name, $plugins, $stack, $visiting, $visited, $skipped, $ordered)) {
                unset($visiting[$name]);
                $skipped[$name] = true;
                return false;
            }
        }

        unset($visiting[$name]);

        $visited[$name] = true;
        $ordered[$name] = $plugin;
        return true;
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
