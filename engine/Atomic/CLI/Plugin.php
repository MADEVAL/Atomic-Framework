<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

trait Plugin
{
    public function plugin_make(): void
    {
        $args = $this->get_cli_args();
        $name = trim((string)($args[0] ?? ''));

        if ($name === '') {
            $this->output->err('Usage: php atomic plugin/make <PluginName>');
            return;
        }

        if (!$this->is_valid_plugin_class_name($name)) {
            $this->output->err('Plugin name may contain only letters, numbers, and underscores, and must not start with a number.');
            return;
        }

        $class_name = $name;
        $plugin_dir = rtrim((string)$this->atomic->get('USER_PLUGINS'), '/\\');
        if ($plugin_dir === '') {
            $plugin_dir = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'plugins';
        } elseif (!$this->is_absolute_path($plugin_dir)) {
            $plugin_dir = ATOMIC_DIR . DIRECTORY_SEPARATOR . $plugin_dir;
        }

        $target_dir = $plugin_dir . DIRECTORY_SEPARATOR . $class_name;
        $routes_dir = $target_dir . DIRECTORY_SEPARATOR . 'routes';

        if (!is_dir($routes_dir) && !mkdir($routes_dir, 0755, true)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            $this->output->err("Could not create plugin directory: {$err}");
            return;
        }

        $created = 0;
        $created += $this->write_plugin_file_if_missing(
            $target_dir . DIRECTORY_SEPARATOR . 'plugin.php',
            $this->plugin_entrypoint_stub($class_name)
        );
        $created += $this->write_plugin_file_if_missing(
            $target_dir . DIRECTORY_SEPARATOR . $class_name . '.php',
            $this->plugin_class_stub($class_name)
        );
        $created += $this->write_plugin_file_if_missing(
            $target_dir . DIRECTORY_SEPARATOR . 'composer.json',
            $this->plugin_composer_stub($class_name)
        );
        $created += $this->write_plugin_file_if_missing(
            $routes_dir . DIRECTORY_SEPARATOR . 'api.php',
            $this->plugin_api_routes_stub($class_name)
        );

        $this->output->writeln("Plugin {$class_name} ready at {$target_dir}");
        $this->output->writeln("Created {$created} file" . ($created === 1 ? '' : 's') . '.');
    }

    private function is_valid_plugin_class_name(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    private function is_absolute_path(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function write_plugin_file_if_missing(string $path, string $content): int
    {
        if (is_file($path)) {
            return 0;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
        return 1;
    }

    private function plugin_entrypoint_stub(string $class_name): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

use Engine\\Atomic\\App\\PluginManager;

if (!defined('ATOMIC_START')) exit;

require_once __DIR__ . '/{$class_name}.php';

PluginManager::instance()->register(new \\App\\Plugins\\{$class_name}\\{$class_name}());

PHP;
    }

    private function plugin_class_stub(string $class_name): string
    {
        return <<<PHP
<?php
declare(strict_types=1);
namespace App\\Plugins\\{$class_name};

use Engine\\Atomic\\App\\Plugin;

if (!defined('ATOMIC_START')) exit;

final class {$class_name} extends Plugin
{
    protected string \$version = '1.0.0';
    protected array \$dependencies = [];

    protected function get_name(): string
    {
        return '{$class_name}';
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}

PHP;
    }

    private function plugin_composer_stub(string $class_name): string
    {
        $package = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $class_name));

        return <<<JSON
{
    "name": "app/{$package}",
    "type": "atomic-plugin",
    "autoload": {
        "psr-4": {
            "App\\\\Plugins\\\\{$class_name}\\\\": "./"
        }
    },
    "require": {}
}

JSON;
    }

    private function plugin_api_routes_stub(string $class_name): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

// API routes for {$class_name}

PHP;
    }
}
