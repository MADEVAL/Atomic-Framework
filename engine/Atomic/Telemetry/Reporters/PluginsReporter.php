<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Telemetry\Data\PluginDependency;
use Engine\Atomic\Telemetry\Data\PluginInfo;
use Engine\Atomic\Telemetry\Data\PluginRequirement;
use Engine\Atomic\Telemetry\Data\PluginState;
use Engine\Atomic\Telemetry\Data\StepOutcome;

class PluginsReporter implements SnapshotProviderInterface
{
    public function report(): array
    {
        return ['plugins' => array_map(static fn (PluginInfo $plugin): array => $plugin->to_array(), $this->plugins())];
    }

    /** @return list<PluginInfo> registered plugins, in registration order */
    public function plugins(): array
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

        return $out;
    }

    /** @param array<string, true> $registered @param array<string, true> $booted */
    private function describe_plugin(
        string $name,
        Plugin $plugin,
        array $registered,
        array $booted,
        array $errors,
        array $load_order,
    ): PluginInfo {
        $is_enabled = $plugin->is_enabled();
        $is_registered = isset($registered[$name]);
        $is_booted = isset($booted[$name]);
        $error = $errors[$name] ?? null;

        $state = match (true) {
            !$is_enabled => PluginState::Disabled,
            $is_booted => PluginState::Booted,
            $error !== null => PluginState::Failed,
            $is_registered => PluginState::Registered,
            default => PluginState::Skipped,
        };

        $register = $is_registered ? StepOutcome::Ok : ($error !== null ? StepOutcome::Failed : StepOutcome::Skipped);
        $boot = match (true) {
            $is_booted => StepOutcome::Ok,
            !$is_registered || !$is_enabled => StepOutcome::Skipped,
            $error !== null => StepOutcome::Failed,
            default => StepOutcome::Pending,
        };

        $deps = [];
        foreach ($plugin->get_dependencies() as $dependency) {
            $dep_name = is_object($dependency) ? get_class($dependency) : (string)$dependency;
            $deps[] = new PluginDependency($dep_name, $this->dependency_state($dep_name, $registered, $booted));
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
            $requirements[] = new PluginRequirement(
                (string)($requirement['package'] ?? $requirement['name'] ?? 'unknown'),
                $missing,
            );
        }

        $migrations_path = null;
        try {
            $migrations_path = $plugin->get_migrations_path();
        } catch (\Throwable $e) {
            $migrations_path = null;
        }

        return new PluginInfo(
            name: $name,
            class: get_class($plugin),
            version: $plugin->get_version(),
            enabled: $is_enabled,
            state: $state,
            load_order: $load_order[$name] ?? null,
            deps: $deps,
            error: $error === null ? null : (string)$error,
            requirements: $requirements,
            migration_source: $plugin->get_migration_source(),
            has_migrations: $migrations_path !== null,
            construct: StepOutcome::Ok,
            register: $register,
            boot: $boot,
        );
    }

    private function dependency_state(string $class, array $registered, array $booted): PluginState
    {
        if (!class_exists($class)) {
            return PluginState::Skipped;
        }
        $name = null;
        try {
            $plugin = PluginManager::instance()->get_by_class($class);
            $name = $plugin?->get_plugin_name();
        } catch (\Throwable $e) {
            $name = null;
        }
        if ($name !== null && isset($booted[$name])) {
            return PluginState::Booted;
        }
        if ($name !== null && isset($registered[$name])) {
            return PluginState::Registered;
        }

        return PluginState::Failed;
    }

    public function snapshot(): array
    {
        try {
            $plugins = $this->plugins();
        } catch (\Throwable $e) {
            $plugins = [];
        }
        $problems = array_filter($plugins, static fn (PluginInfo $plugin): bool => $plugin->state->is_problem());

        return ['total' => count($plugins), 'problems' => count($problems)];
    }
}
