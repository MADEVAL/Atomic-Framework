<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\RouteLoader;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Exceptions\PluginDependencyException;

class PluginManager
{
    use Singleton;

    protected ?App $atomic = null;
    protected array $plugins = [];
    protected array $registered = [];
    protected array $booted = [];
    protected array $loaded_route_types = [];
    protected array $registered_autoloaders = [];
    /** @var array<string, string> plugin_name => error_message */
    protected array $errors = [];

    private function __construct()
    {
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
                $this->errors[$name] = 'register: ' . $e->getMessage();
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
                $this->errors[$name] = 'boot: ' . $e->getMessage();
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

    public function load_plugins(?array $plugin_classes = null): void
    {
        $plugin_classes ??= $this->provider_plugin_classes();
        $plugins_path = rtrim((string)$this->get_atomic()->get('USER_PLUGINS'), '/\\') . DIRECTORY_SEPARATOR;
        $resolved_plugins_path = realpath($plugins_path);

        if ($resolved_plugins_path === false || !is_dir($resolved_plugins_path)) {
            Log::debug("User plugins directory not found: {$plugins_path}");
            $resolved_plugins_path = null;
        }

        foreach ($plugin_classes as $plugin_class) {
            if (!is_string($plugin_class) || $plugin_class === '') {
                continue;
            }

            if ($this->get_by_class($plugin_class) !== null) {
                continue;
            }

            $resolved_plugin_dir = $this->resolve_provider_plugin_directory($plugin_class, $resolved_plugins_path);
            if ($resolved_plugin_dir !== false) {
                $this->load_provider_plugin_autoload($plugin_class, $resolved_plugin_dir);
            }

            if (!class_exists($plugin_class)) {
                Log::warning("Plugin class not found: {$plugin_class}");
                continue;
            }

            if (!is_subclass_of($plugin_class, Plugin::class)) {
                Log::warning("Plugin {$plugin_class} must extend " . Plugin::class . '.');
                continue;
            }

            try {
                $plugin = new $plugin_class($this->get_atomic());
            } catch (\Throwable $e) {
                Log::error("Failed to create plugin {$plugin_class}: " . $e->getMessage());
                $this->errors[$plugin_class] = 'construct: ' . $e->getMessage();
                continue;
            }

            try {
                $this->register($plugin);
            } catch (\Throwable $e) {
                Log::error("Failed to register plugin {$plugin_class}: " . $e->getMessage());
                $this->errors[$plugin_class] = 'register: ' . $e->getMessage();
            }
        }
    }

    public function load_user_plugins(?array $plugin_classes = null): void
    {
        $this->load_plugins($plugin_classes);
    }

    public function load_core_plugins(?array $plugin_classes = null): void
    {
        $this->load_plugins($plugin_classes);
    }

    protected function provider_plugin_classes(): array
    {
        $providers_config = ATOMIC_CONFIG . 'providers.php';
        $resolved_providers_config = realpath($providers_config);
        if ($resolved_providers_config === false || !is_file($resolved_providers_config) || !is_readable($resolved_providers_config)) {
            Log::debug("Providers config not found: {$providers_config}");
            return [];
        }

        $providers = require $resolved_providers_config;
        return is_array($providers) ? (array)($providers['plugins'] ?? []) : [];
    }

    protected function load_user_plugin_autoload(string $plugin_class, string $resolved_plugins_path): void
    {
        $resolved_plugin_dir = $this->resolve_plugin_directory($plugin_class, $resolved_plugins_path);

        if (
            $resolved_plugin_dir === false
            || !is_dir($resolved_plugin_dir)
            || !str_starts_with($resolved_plugin_dir, $resolved_plugins_path . DIRECTORY_SEPARATOR)
        ) {
            return;
        }

        $this->load_plugin_autoload($resolved_plugin_dir, basename($resolved_plugin_dir));
        $this->register_plugin_namespace_autoload($plugin_class, $resolved_plugin_dir);

        if (class_exists($plugin_class)) {
            return;
        }

        $plugin_file = $resolved_plugin_dir . DIRECTORY_SEPARATOR . $this->plugin_directory_name($plugin_class) . '.php';
        $resolved_plugin_file = realpath($plugin_file);
        if (
            $resolved_plugin_file !== false
            && is_file($resolved_plugin_file)
            && is_readable($resolved_plugin_file)
            && str_starts_with($resolved_plugin_file, $resolved_plugin_dir . DIRECTORY_SEPARATOR)
        ) {
            require_once $resolved_plugin_file;
        }
    }

    protected function resolve_provider_plugin_directory(string $plugin_class, ?string $resolved_plugins_path): string|false
    {
        if ($resolved_plugins_path !== null) {
            $resolved_user_plugin_dir = $this->resolve_plugin_directory($plugin_class, $resolved_plugins_path);
            if (
                $resolved_user_plugin_dir !== false
                && is_dir($resolved_user_plugin_dir)
                && str_starts_with($resolved_user_plugin_dir, $resolved_plugins_path . DIRECTORY_SEPARATOR)
            ) {
                return $resolved_user_plugin_dir;
            }
        }

        if (!class_exists($plugin_class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($plugin_class);
        } catch (\ReflectionException) {
            return false;
        }

        $file_name = $reflection->getFileName();
        if ($file_name === false) {
            return false;
        }

        $resolved_plugin_dir = realpath(dirname($file_name));
        return $resolved_plugin_dir !== false && is_dir($resolved_plugin_dir) ? $resolved_plugin_dir : false;
    }

    protected function load_provider_plugin_autoload(string $plugin_class, string $resolved_plugin_dir): void
    {
        $this->load_plugin_autoload($resolved_plugin_dir, basename($resolved_plugin_dir));
        $this->register_plugin_namespace_autoload($plugin_class, $resolved_plugin_dir);

        if (class_exists($plugin_class)) {
            return;
        }

        $plugin_file = $resolved_plugin_dir . DIRECTORY_SEPARATOR . $this->plugin_directory_name($plugin_class) . '.php';
        $resolved_plugin_file = realpath($plugin_file);
        if (
            $resolved_plugin_file !== false
            && is_file($resolved_plugin_file)
            && is_readable($resolved_plugin_file)
            && str_starts_with($resolved_plugin_file, $resolved_plugin_dir . DIRECTORY_SEPARATOR)
        ) {
            require_once $resolved_plugin_file;
        }
    }

    protected function resolve_plugin_directory(string $plugin_class, string $resolved_plugins_path): string|false
    {
        foreach ($this->plugin_directory_candidates($plugin_class) as $directory_name) {
            $resolved_plugin_dir = realpath($resolved_plugins_path . DIRECTORY_SEPARATOR . $directory_name);
            if ($resolved_plugin_dir !== false) {
                return $resolved_plugin_dir;
            }
        }

        return false;
    }

    protected function plugin_directory_candidates(string $plugin_class): array
    {
        $class_parts = explode('\\', ltrim($plugin_class, '\\'));
        $candidates = [];

        $class_name = end($class_parts);
        if (is_string($class_name) && $class_name !== '') {
            $candidates[] = $class_name;
        }

        $namespace_plugin_name = count($class_parts) >= 2 ? $class_parts[count($class_parts) - 2] : null;
        if (is_string($namespace_plugin_name) && $namespace_plugin_name !== '') {
            $candidates[] = $namespace_plugin_name;
        }

        return array_values(array_unique($candidates));
    }

    protected function register_plugin_namespace_autoload(string $plugin_class, string $resolved_plugin_dir): void
    {
        $namespace = $this->plugin_namespace_prefix($plugin_class);
        if ($namespace === '' || isset($this->registered_autoloaders[$namespace])) {
            return;
        }

        $this->registered_autoloaders[$namespace] = $resolved_plugin_dir;

        spl_autoload_register(static function (string $class) use ($namespace, $resolved_plugin_dir): void {
            if (!str_starts_with($class, $namespace)) {
                return;
            }

            $relative_class = substr($class, strlen($namespace));
            if ($relative_class === '' || str_contains($relative_class, '..')) {
                return;
            }

            $file = $resolved_plugin_dir . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class)
                . '.php';

            $resolved_file = realpath($file);
            if (
                $resolved_file !== false
                && is_file($resolved_file)
                && is_readable($resolved_file)
                && str_starts_with($resolved_file, $resolved_plugin_dir . DIRECTORY_SEPARATOR)
            ) {
                require_once $resolved_file;
            }
        });
    }

    protected function plugin_namespace_prefix(string $plugin_class): string
    {
        $class = ltrim($plugin_class, '\\');
        $last_separator = strrpos($class, '\\');

        return $last_separator === false
            ? ''
            : substr($class, 0, $last_separator + 1);
    }

    protected function plugin_directory_name(string $plugin_class): string
    {
        $class_parts = explode('\\', ltrim($plugin_class, '\\'));
        return end($class_parts) ?: $plugin_class;
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
                throw new \Engine\Atomic\Exceptions\PluginDependencyException("Plugin {$plugin->get_plugin_name()} requires {$dependency_class}, but it is disabled.");
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

    /** @return array<string, string> */
    public function get_errors(): array
    {
        return $this->errors;
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
