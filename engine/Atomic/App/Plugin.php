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
        $this->name = $this->getName();
        $this->path = $this->getPath();
    }

    abstract protected function getName(): string;
    
    protected function getPath(): string
    {
        $reflection = new \ReflectionClass($this);
        return dirname($reflection->getFileName());
    }

    public function register(): void {}
    
    public function boot(): void {}
    
    public function activate(): void {}
    
    public function deactivate(): void {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getPluginName(): string
    {
        return $this->name;
    }

    public function getPluginPath(): string
    {
        return $this->path;
    }
}