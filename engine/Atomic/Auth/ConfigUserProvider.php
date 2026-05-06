<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Core\App;

final class ConfigUserProvider implements UserProviderInterface
{
    public function __construct(private string $guard = 'dashboard') {}

    public function find_by_credentials(array $credentials): ?AuthenticatableInterface
    {
        $username = $this->normalize_username($credentials['username'] ?? null);
        if ($username === '') return null;

        $config = $this->configured_users()[$username] ?? null;
        return is_array($config) ? $this->create_user_from_config($username, $config) : null;
    }

    public function find_by_id(string $auth_id): ?AuthenticatableInterface
    {
        foreach ($this->configured_users() as $username => $config) {
            if (!is_string($username) || !is_array($config)) {
                continue;
            }

            $id = $this->normalize_auth_id($config['id'] ?? null);
            if ($id !== '' && hash_equals($id, $auth_id)) {
                return $this->create_user_from_config((string)$username, $config);
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function configured_users(): array
    {
        $users = App::instance()->get("ACCESS.guards.{$this->guard}.users");
        return is_array($users) ? $users : [];
    }

    /** @param array<string, mixed> $config */
    private function create_user_from_config(string $configured_username, array $config): ?ConfigUser
    {
        $id = $this->normalize_auth_id($config['id'] ?? null);
        $username = $this->normalize_username($config['username'] ?? null);
        $secret_hash = $this->normalize_secret_hash($config['secret_hash'] ?? null);
        if ($id === '' || $username === '' || $secret_hash === '' || $username !== $configured_username) {
            return null;
        }

        $roles = $config['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }

        return new ConfigUser(
            $id,
            $username,
            $secret_hash,
            array_values(array_map('strval', $roles)),
        );
    }

    private function normalize_auth_id(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function normalize_username(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    private function normalize_secret_hash(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
