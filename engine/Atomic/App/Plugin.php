<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Exceptions\PluginDependencyException;

abstract class Plugin
{
    protected App $atomic;
    protected string $name;
    protected string $version = '1.0.0';
    protected string $path;
    protected bool $enabled = true;
    protected array $dependencies = [];

    public function __construct(?App $atomic = null)
    {
        $this->atomic = $atomic ?? App::instance();
        $this->name = $this->get_name();
        $this->path = $this->get_path();
    }

    abstract protected function get_name(): string;
    
    protected function get_path(): string
    {
        $reflection = new \ReflectionClass($this);
        return dirname($reflection->getFileName());
    }

    public function register(): void {}
    
    public function boot(): void {}
    
    public function activate(): void {}
    
    public function deactivate(): void {}

    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    public function set_enabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function get_version(): string
    {
        return $this->version;
    }

    public function get_dependencies(): array
    {
        return $this->dependencies;
    }

    public function required_dependencies(): array
    {
        return [];
    }

    public function assert_runtime_requirements(): void
    {
        foreach ($this->required_dependencies() as $requirement) {
            $normalized = $this->normalize_runtime_requirement($requirement);

            $missing = [];
            foreach ($normalized['classes'] as $class) {
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                    $missing[] = $class;
                }
            }
            foreach ($normalized['functions'] as $function) {
                if (!function_exists($function)) {
                    $missing[] = $function . '()';
                }
            }

            if ($missing === []) {
                continue;
            }

            $package = $normalized['package'];
            throw new PluginDependencyException(
                "Plugin {$this->name} is missing package {$package}. "
                . "Run: php atomic plugin/deps install {$this->name}"
            );
        }
    }

    private function normalize_runtime_requirement(mixed $requirement): ?array
    {
        if (is_string($requirement)) {
            throw new PluginDependencyException(
                "Plugin {$this->name} runtime dependency {$requirement} must declare at least one class or function check."
            );
        }

        if (!is_array($requirement)) {
            throw new PluginDependencyException("Plugin {$this->name} has invalid runtime dependency declaration.");
        }

        $package = (string)($requirement['package'] ?? $requirement['name'] ?? '');
        if ($package === '') {
            throw new PluginDependencyException("Plugin {$this->name} runtime dependency is missing package name.");
        }

        $classes = array_values(array_filter((array)($requirement['classes'] ?? $requirement['class'] ?? []), 'is_string'));
        $functions = array_values(array_filter((array)($requirement['functions'] ?? $requirement['function'] ?? []), 'is_string'));

        if ($classes === [] && $functions === []) {
            throw new PluginDependencyException(
                "Plugin {$this->name} runtime dependency {$package} must declare at least one class or function check."
            );
        }

        return [
            'package' => $package,
            'classes' => $classes,
            'functions' => $functions,
        ];
    }

    public function get_plugin_name(): string
    {
        return $this->name;
    }

    public function get_plugin_path(): string
    {
        return $this->path;
    }

    /**
     * Return the path to the plugin's migrations directory, or null if none.
     * Migrations are executed directly from this directory by the shared runner.
     */
    public function get_migrations_path(): ?string
    {
        $path = $this->path . DIRECTORY_SEPARATOR . 'Migrations';
        return is_dir($path) ? $path : null;
    }

    /**
     * Stable identity used by the source-aware migration ledger.
     * Plugins distributed as Composer packages should override this method with
     * their Composer package name so display-name changes do not alter history.
     */
    public function get_migration_source(): string
    {
        $name = strtolower(trim($this->get_plugin_name()));
        $name = preg_replace('/[^a-z0-9._\/-]+/', '-', $name) ?? $name;
        return 'plugin:' . trim($name, '-');
    }
}
