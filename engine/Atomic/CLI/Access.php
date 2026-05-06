<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\ConfigUserStore;
use Engine\Atomic\Core\Hash;
use Engine\Atomic\Core\ID;

trait Access
{
    public function access_user_create(): void
    {
        $this->prepare_access_config_io();
        [$positionals, $options] = $this->parse_access_args();

        $raw_username = trim((string)($positionals[1] ?? ''));
        if ($raw_username === '') {
            $this->output->err('Usage: access/user/create <guard> <username> [roles] [--role=role] [--secret=secret] [--force]');
            return;
        }

        $guard = $this->normalize_access_name($positionals[0] ?? 'telemetry');
        $username = $this->normalize_access_name($raw_username);
        $store = $this->config_user_store();
        if ($store->exists($guard, $username) && !isset($options['force'])) {
            $this->output->err("Config user '{$guard}.{$username}' already exists. Use --force to overwrite.");
            return;
        }

        $roles = $this->roles_from_args($positionals[2] ?? '', $options);
        $secret = (string)($options['secret'] ?? bin2hex(random_bytes(32)));

        $id = ID::uuid_v4();
        $secret_hash = Hash::password($secret);

        if (!$store->upsert_user($guard, $username, $id, $secret_hash, $roles)) {
            $this->output->err("Could not write config user '{$guard}.{$username}' to " . $store->path() . '.');
            return;
        }

        $this->output->writeln("Created config user '{$guard}.{$username}'.");
        $this->output->writeln('Store this secret securely. It will not be shown again:');
        $this->output->writeln('  username: ' . $username);
        $this->output->writeln('  secret: ' . $secret);
    }

    public function access_user_reset_secret(): void
    {
        $this->prepare_access_config_io();
        [$positionals, $options] = $this->parse_access_args();

        $raw_username = trim((string)($positionals[1] ?? ''));
        if ($raw_username === '') {
            $this->output->err('Usage: access/user/reset <guard> <username> [--secret=secret]');
            return;
        }

        $guard = $this->normalize_access_name($positionals[0] ?? 'telemetry');
        $username = $this->normalize_access_name($raw_username);
        $store = $this->config_user_store();
        if (!$store->exists($guard, $username)) {
            $this->output->err("Config user '{$guard}.{$username}' does not exist.");
            return;
        }

        $secret = (string)($options['secret'] ?? bin2hex(random_bytes(32)));
        if (!$store->reset_secret($guard, $username, Hash::password($secret))) {
            $this->output->err("Could not reset secret for config user '{$guard}.{$username}'.");
            return;
        }

        $this->output->writeln("Reset secret for config user '{$guard}.{$username}'.");
        $this->output->writeln('Store this secret securely. It will not be shown again:');
        $this->output->writeln('  username: ' . $username);
        $this->output->writeln('  secret: ' . $secret);
    }

    public function access_user_list(): void
    {
        $guards = $this->config_user_store()->guards();
        if ($guards === []) {
            $this->output->writeln('No config users found.');
            return;
        }

        foreach ($guards as $guard => $config) {
            $users = is_array($config) && is_array($config['users'] ?? null) ? $config['users'] : [];
            if ($users === []) {
                continue;
            }

            $this->output->writeln((string)$guard . ':');
            foreach ($users as $username => $user_config) {
                $roles = is_array($user_config) && is_array($user_config['roles'] ?? null)
                    ? implode(',', array_map('strval', $user_config['roles']))
                    : '';
                $this->output->writeln('  ' . (string)$username . ($roles !== '' ? " ({$roles})" : ''));
            }
        }
    }

    private function prepare_access_config_io(): void
    {
        $this->init_root = ATOMIC_DIR;
        $this->init_config_mode = $this->detect_config_mode();
        $this->init_env_path = $this->init_root . DIRECTORY_SEPARATOR . '.env';
    }

    /** @return array{0: list<string>, 1: array<string, string|true>} */
    private function parse_access_args(): array
    {
        $positionals = [];
        $options = [];

        foreach ($this->get_cli_args() as $arg) {
            if (!is_string($arg) || $arg === '') {
                continue;
            }

            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                [$key, $value] = array_pad(explode('=', $option, 2), 2, true);
                $options[(string)$key] = $value;
                continue;
            }

            $positionals[] = $arg;
        }

        return [$positionals, $options];
    }

    /** @param array<string, string|true> $options */
    private function roles_from_args(string $role_arg, array $options): array
    {
        $roles = [];
        foreach (['role', 'roles'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                $roles[] = $options[$key];
            }
        }
        if ($role_arg !== '') {
            $roles[] = $role_arg;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $role): string => trim($role),
            explode(',', implode(',', $roles)),
        ))));
    }

    private function config_user_store(): ConfigUserStore
    {
        return new ConfigUserStore(ATOMIC_DIR);
    }
}
