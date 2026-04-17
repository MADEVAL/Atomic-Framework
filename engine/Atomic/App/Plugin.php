<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;

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
     * Used by `php atomic migrations/publish <plugin-name>` to auto-discover migrations.
     */
    public function get_migrations_path(): ?string
    {
        $path = $this->path . DIRECTORY_SEPARATOR . 'Migrations';
        return is_dir($path) ? $path : null;
    }
}