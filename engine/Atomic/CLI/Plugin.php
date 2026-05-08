<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin as AtomicPlugin;

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

    public function plugin_deps_install(): void
    {
        $args = $this->get_cli_args();
        $subcommand = strtolower(trim((string)($args[0] ?? '')));
        $requested_plugin = trim((string)($args[1] ?? ''));

        if ($subcommand !== 'install') {
            $this->output->err('Usage: php atomic plugin/deps install [PluginName]');
            exit(1);
            return;
        }

        $plugins = $this->enabled_plugin_dependency_targets($requested_plugin);
        if ($plugins === []) {
            $scope = $requested_plugin === '' ? 'enabled plugins' : "plugin {$requested_plugin}";
            $this->output->writeln("No plugin composer.json files found for {$scope}.");
            if ($requested_plugin !== '') {
                exit(1);
            }
            return;
        }

        $failed = false;
        foreach ($plugins as $plugin) {
            $failed = !$this->install_plugin_dependencies($plugin) || $failed;
        }

        if ($failed) {
            exit(1);
        }
    }

    private function enabled_plugin_dependency_targets(string $requested_plugin = ''): array
    {
        $providers_file = ATOMIC_CONFIG . 'providers.php';
        $resolved_providers_file = realpath($providers_file);
        if ($resolved_providers_file === false || !is_file($resolved_providers_file) || !is_readable($resolved_providers_file)) {
            $this->output->err("Providers config not found: {$providers_file}");
            return [];
        }

        $providers = require $resolved_providers_file;
        $plugin_classes = is_array($providers) ? (array)($providers['plugins'] ?? []) : [];
        $targets = [];

        foreach ($plugin_classes as $plugin_class) {
            if (!is_string($plugin_class) || !class_exists($plugin_class) || !is_subclass_of($plugin_class, AtomicPlugin::class)) {
                continue;
            }

            try {
                $plugin = new $plugin_class($this->atomic);
            } catch (\Throwable $e) {
                $this->output->err("Skipping {$plugin_class}: " . $e->getMessage());
                continue;
            }

            $plugin_name = $plugin->get_plugin_name();
            if ($requested_plugin !== '' && strcasecmp($requested_plugin, $plugin_name) !== 0 && strcasecmp($requested_plugin, $plugin_class) !== 0) {
                continue;
            }

            $composer_file = $plugin->get_plugin_path() . DIRECTORY_SEPARATOR . 'composer.json';
            if (!is_file($composer_file)) {
                continue;
            }

            $targets[] = [
                'name' => $plugin_name,
                'class' => $plugin_class,
                'path' => $plugin->get_plugin_path(),
                'composer' => $composer_file,
            ];
        }

        return $targets;
    }

    private function install_plugin_dependencies(array $plugin): bool
    {
        $composer = $this->find_composer_binary();
        if ($composer === null) {
            $this->output->err('Composer executable not found. Install Composer or add it to PATH.');
            return false;
        }

        $composer_data = json_decode((string)file_get_contents($plugin['composer']), true);
        $requires = is_array($composer_data) ? array_keys((array)($composer_data['require'] ?? [])) : [];
        $packages = array_values(array_filter($requires, static fn (string $name): bool => $name !== 'php' && !str_starts_with($name, 'ext-')));
        $autoload = $plugin['path'] . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        $this->output->writeln("Plugin {$plugin['name']}: " . ($packages === [] ? 'no package dependencies declared.' : 'requires ' . implode(', ', $packages) . '.'));
        if (is_file($autoload)) {
            $this->output->writeln("Plugin {$plugin['name']}: vendor/autoload.php exists; syncing Composer state.");
        } else {
            $this->output->writeln("Plugin {$plugin['name']}: vendor/autoload.php missing; installing dependencies.");
        }

        $cmd = $this->composer_command($composer, $plugin['path']);
        passthru($cmd, $status);

        if ($status === 0) {
            if (!is_file($autoload)) {
                $this->output->err("Plugin {$plugin['name']}: Composer finished but vendor/autoload.php is missing.");
                return false;
            }
            $this->output->writeln("Plugin {$plugin['name']}: dependencies installed.");
            return true;
        }

        $this->output->err("Plugin {$plugin['name']}: Composer install failed with status {$status}.");
        return false;
    }

    private function find_composer_binary(): ?string
    {
        $candidates = ['composer'];
        if (defined('ATOMIC_DIR')) {
            $candidates[] = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'composer.phar';
        }

        foreach ($candidates as $candidate) {
            if ($candidate === 'composer') {
                $path = trim((string)shell_exec('command -v composer 2>/dev/null'));
                if ($path !== '') {
                    return $path;
                }
                continue;
            }

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function composer_command(string $composer, string $working_dir): string
    {
        if (str_ends_with($composer, '.phar')) {
            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composer)
                . ' install --no-interaction --working-dir=' . escapeshellarg($working_dir);
        }

        return escapeshellarg($composer) . ' install --no-interaction --working-dir=' . escapeshellarg($working_dir);
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
