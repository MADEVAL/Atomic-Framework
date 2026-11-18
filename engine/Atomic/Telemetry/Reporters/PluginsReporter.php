<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;

class PluginsReporter implements SnapshotProviderInterface
{
    /** @return array{plugins:list<array<string, mixed>>} */
    public function report(): array
    {
        $manager = PluginManager::instance();
        $registered = $manager->registered_map();
        $booted = $manager->booted_map();
        $errors = $manager->get_errors();

        $load_order = [];
        try {
            foreach (array_values($manager->ordered_enabled_plugins()) as $index => $plugin) {
                $load_order[$plugin->get_plugin_name()] = $index + 1;
            }
        } catch (\Throwable $e) {
            $load_order = [];
        }

        $out = [];
        foreach ($manager->all() as $name => $plugin) {
            $out[] = $this->describe_plugin(
                (string)$name,
                $plugin,
                $registered,
                $booted,
                $errors,
                $load_order,
            );
        }

        return ['plugins' => $out];
    }

    /** @param array<string, true> $registered @param array<string, true> $booted */
    private function describe_plugin(
        string $name,
        Plugin $plugin,
        array $registered,
        array $booted,
        array $errors,
        array $load_order,
    ): array {
        $is_enabled = $plugin->is_enabled();
        $is_registered = isset($registered[$name]);
        $is_booted = isset($booted[$name]);
        $error = $errors[$name] ?? null;

        $state = match (true) {
            !$is_enabled => 'disabled',
            $is_booted => 'booted',
            $error !== null => 'failed',
            $is_registered => 'registered',
            default => 'skipped',
        };

        $steps = [
            'construct' => 'ok',
            'register' => $is_registered ? 'ok' : ($error !== null ? 'failed' : 'skipped'),
            'boot' => $is_booted ? 'ok' : (!$is_registered || !$is_enabled ? 'skipped' : ($error !== null ? 'failed' : 'registered')),
        ];

        $deps = [];
        foreach ($plugin->get_dependencies() as $dependency) {
            $dep_name = is_object($dependency) ? get_class($dependency) : (string)$dependency;
            $deps[] = [
                'name' => $dep_name,
                'state' => $this->dependency_state($dep_name, $registered, $booted),
            ];
        }

        $requirements = [];
        foreach ($plugin->required_dependencies() as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $missing = false;
            foreach ((array)($requirement['classes'] ?? []) as $class) {
                if (!class_exists((string)$class)) {
                    $missing = true;
                }
            }
            foreach ((array)($requirement['functions'] ?? []) as $function) {
                if (!function_exists((string)$function)) {
                    $missing = true;
                }
            }
            $requirements[] = [
                'package' => (string)($requirement['package'] ?? $requirement['name'] ?? 'unknown'),
                'missing' => $missing,
            ];
        }

        $plugin_class = get_class($plugin);
        $migrations_path = null;
        try {
            $migrations_path = $plugin->get_migrations_path();
        } catch (\Throwable $e) {
            $migrations_path = null;
        }

        return [
            'name' => $name,
            'class' => $plugin_class,
            'version' => $plugin->get_version(),
            'enabled' => $is_enabled,
            'state' => $state,
            'load_order' => $load_order[$name] ?? null,
            'deps' => $deps,
            'error' => $error,
            'requirements' => $requirements,
            'migration_source' => $plugin->get_migration_source(),
            'has_migrations' => $migrations_path !== null,
            'steps' => $steps,
        ];
    }

    private function dependency_state(string $class, array $registered, array $booted): string
    {
        if (!class_exists($class)) {
            return 'skipped';
        }
        $name = null;
        try {
            $plugin = PluginManager::instance()->get_by_class($class);
            $name = $plugin?->get_plugin_name();
        } catch (\Throwable $e) {
            $name = null;
        }
        if ($name !== null && isset($booted[$name])) {
            return 'booted';
        }
        if ($name !== null && isset($registered[$name])) {
            return 'registered';
        }

        return 'failed';
    }

    public function snapshot(): array
    {
        $total = 0;
        $problems = 0;
        try {
            $data = $this->report();
        } catch (\Throwable $e) {
            $data = ['plugins' => []];
        }
        foreach ($data['plugins'] as $plugin) {
            $total++;
            if ($plugin['state'] === 'failed' || $plugin['state'] === 'skipped') {
                $problems++;
            }
        }

        return ['total' => $total, 'problems' => $problems];
    }
}
