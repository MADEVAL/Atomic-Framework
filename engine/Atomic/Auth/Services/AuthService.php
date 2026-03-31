<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Services;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\BcryptHasherAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\MetaStorageAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionManagerAdapter;
use Engine\Atomic\Auth\Adapters\SystemClockAdapter;
use Engine\Atomic\Auth\Adapters\TransientCacheAdapter;
use Engine\Atomic\Auth\Interfaces\AuthSessionInterface;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\HasRolesInterface;
use Engine\Atomic\Auth\Interfaces\LoginInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Enums\Role;

class AuthService implements LoginInterface
{
    private ?AuthenticatableInterface $current_user = null;
    private ?UserProviderInterface    $user_provider = null;

    public function __construct(
        private AppContextAdapter      $app,
        private AuthSessionInterface   $session,
        private MetaStorageAdapter     $meta,
        private TransientCacheAdapter  $cache,
        private LogAdapter             $logger,
        private SystemClockAdapter     $clock,
        private PhpSessionAdapter      $php_session,
        private BcryptHasherAdapter    $hasher,
        private SessionManagerAdapter  $session_manager,
    ) {}

    public function set_user_provider(UserProviderInterface $provider): self
    {
        $this->user_provider = $provider;
        return $this;
    }

    public function login_by_id(string $auth_id, array $context = []): void
    {
        $this->current_user = null;

        $this->session->start($auth_id);
        $this->php_session->regenerate_id(true);

        $session_data = array_merge($context, [
            'ip'          => $this->app->get('IP'),
            'user_agent'  => $this->app->get('AGENT'),
            'device_type' => $this->app->get_device_type(),
            'created_at'  => $this->clock->now(),
        ]);

        $this->meta->set_meta(
            $auth_id,
            'auth_session_' . $this->php_session->id(),
            json_encode($session_data)
        );
    }

    public function check_rate_limit(array $credentials, string $operation = 'register'): bool
    {
        $ip     = $this->app->get('IP');
        $config = $this->app->get('RATE_LIMIT')[$operation];

        $ip_limit   = (int) $config['ip'];
        $cred_limit = (int) $config['credential'];
        $ip_ttl     = (int) $config['ip_ttl'];
        $cred_ttl   = (int) $config['credential_ttl'];

        $ip_key      = "auth_{$operation}_ip_" . hash('sha256', $ip);
        $ip_attempts = $this->cache->get($ip_key) ?: 0;
        if ($ip_attempts >= $ip_limit) {
            return false;
        }

        $cred_for_hash = $credentials;
        ksort($cred_for_hash);
        $cred_key      = 'auth_' . $operation . '_cred_' . hash('sha256', json_encode($cred_for_hash));
        $cred_attempts = $this->cache->get($cred_key) ?: 0;
        if ($cred_attempts >= $cred_limit) {
            return false;
        }

        $this->cache->set($ip_key, $ip_attempts + 1, $ip_ttl);
        $this->cache->set($cred_key, $cred_attempts + 1, $cred_ttl);
        return true;
    }

    public function login_with_secret(array $credentials, string $secret): ?AuthenticatableInterface
    {
        if (!$this->user_provider) {
            throw new \RuntimeException('User provider not configured. Call set_user_provider() first.');
        }

        $credential_keys = array_keys(array_diff_key($credentials, array_flip(['password', 'secret', 'token'])));
        $user = $this->user_provider->find_by_credentials($credentials);
        if (!$user) {
            $this->logger->warning('Auth login failed: user not found', [
                'ip' => $this->app->get('IP'),
                'credential_keys' => $credential_keys,
            ]);
            return null;
        }

        $password_hash = $user->get_password_hash();
        if (!$password_hash || !$this->hasher->verify($secret, $password_hash)) {
            $this->logger->warning('Auth login failed: invalid secret', [
                'ip' => $this->app->get('IP'),
                'credential_keys' => $credential_keys,
            ]);
            return null;
        }

        $sanitized = $credentials;
        foreach (['password', 'secret', 'token'] as $key) {
            unset($sanitized[$key]);
        }
        ksort($sanitized);

        $this->login_by_id($user->get_auth_id(), [
            'auth_provider' => 'password',
            'credentials'   => $sanitized,
        ]);

        $ip = $this->app->get('IP');
        $cred_for_hash = $credentials;
        ksort($cred_for_hash);
        $this->cache->delete("auth_login_ip_" . hash('sha256', $ip));
        $this->cache->delete('auth_login_cred_' . hash('sha256', json_encode($cred_for_hash)));

        $this->current_user = $user;
        return $user;
    }

    public function logout(): void
    {
        $user = $this->get_current_user();
        if (!$user) {
            return;
        }
        $this->meta->delete_meta($user->get_auth_id(), 'auth_session_' . $this->php_session->id());
        $this->session->destroy();
        $this->current_user = null;
    }

    public function kill_all_sessions(string $userId, bool $keepCurrent = true): int
    {
        $current_session_id = $this->php_session->id();
        $all_sessions = $this->meta->get_meta_like($userId, 'auth_session_%');

        $sessions_to_delete = [];
        foreach ($all_sessions as $key => $value) {
            if ($keepCurrent && $key === 'auth_session_' . $current_session_id) {
                continue;
            }
            $sessions_to_delete[$key] = substr($key, 13);
        }

        $deleted_count = 0;
        if ($sessions_to_delete !== []) {
            if ($keepCurrent) {
                foreach ($sessions_to_delete as $key => $session_id) {
                    $meta_deleted    = $this->meta->delete_meta($userId, $key);
                    $session_deleted = $this->session_manager->delete_session($session_id);

                    if ($meta_deleted || $session_deleted) {
                        $deleted_count++;
                    }
                }
            } else {
                $meta_deleted = $this->meta->delete_meta_like($userId, 'auth_session_%');
                $session_deleted_count = $this->session_manager->delete_sessions(array_values($sessions_to_delete));
                $deleted_count = $meta_deleted ? count($sessions_to_delete) : $session_deleted_count;
            }
        }

        $this->logger->info('Sessions invalidated: ' . json_encode([
            'user_uuid'     => $userId,
            'deleted_count' => $deleted_count,
            'kept_current'  => $keepCurrent,
            'driver'        => $this->session_manager->get_driver(),
        ]));

        return $deleted_count;
    }

    public function get_current_user(): ?AuthenticatableInterface
    {
        if (!$this->session->is_started()) {
            return null;
        }

        if ($this->current_user !== null) {
            return $this->current_user;
        }

        if (!$this->user_provider) {
            throw new \RuntimeException('User provider not configured. Call set_user_provider() first.');
        }

        $uuid = $this->app->get('SESSION.user_uuid');
        if ($uuid) {
            $user = $this->user_provider->find_by_id($uuid);
            if ($user) {
                $this->current_user = $user;
                return $user;
            }
        }

        return null;
    }

    public function impersonate_user(string $user_uuid): bool
    {
        $admin = $this->get_current_user();
        if (!$admin) {
            return false;
        }

        if (
            !($admin instanceof HasRolesInterface)
            || !in_array(Role::ADMIN->value, $admin->get_role_slugs(), true)
        ) {
            $this->logger->warning('Impersonation denied: user lacks admin role', [
                'auth_id' => $admin->get_auth_id(),
            ]);
            return false;
        }

        if (!$this->app->get('SESSION.admin_uuid')) {
            $this->app->set('SESSION.admin_uuid', $admin->get_auth_id());
        }

        $this->app->set('SESSION.user_uuid', $user_uuid);
        $this->current_user = null;

        if ($this->session->is_started()) {
            $this->php_session->regenerate_id(true);
        }

        $this->meta->set_meta($user_uuid, 'auth_session_' . $this->php_session->id(), json_encode([
            'ip'              => $this->app->get('IP'),
            'user_agent'      => $this->app->get('AGENT'),
            'device_type'     => $this->app->get_device_type(),
            'created_at'      => $this->clock->now(),
            'impersonated_by' => $admin->get_auth_id(),
        ]));

        $this->logger->info('Admin impersonation started: ' . json_encode([
            'admin_auth_id'  => $admin->get_auth_id(),
            'target_auth_id' => $user_uuid,
            'ip'             => $this->app->get('IP'),
            'user_agent'     => $this->app->get('AGENT'),
            'session_id'     => $this->php_session->id(),
        ]));

        return true;
    }

    public function stop_impersonation(): bool
    {
        $admin_uuid = $this->app->get('SESSION.admin_uuid');
        if (!$admin_uuid) {
            return false;
        }

        $impersonated_uuid = $this->app->get('SESSION.user_uuid');

        $this->app->set('SESSION.user_uuid', $admin_uuid);
        $this->app->set('SESSION.admin_uuid', null);
        $this->current_user = null;

        if ($this->session->is_started()) {
            $this->php_session->regenerate_id(true);
        }

        $this->meta->set_meta($admin_uuid, 'auth_session_' . $this->php_session->id(), json_encode([
            'ip'          => $this->app->get('IP'),
            'user_agent'  => $this->app->get('AGENT'),
            'device_type' => $this->app->get_device_type(),
            'created_at'  => $this->clock->now(),
        ]));

        $this->logger->info('Admin impersonation stopped' . json_encode([
            'admin_auth_id'  => $admin_uuid,
            'target_auth_id' => $impersonated_uuid,
            'ip'             => $this->app->get('IP'),
            'user_agent'     => $this->app->get('AGENT'),
            'session_id'     => $this->php_session->id(),
        ]));

        return true;
    }

    public function is_impersonating(): bool
    {
        if (!$this->session->is_started()) {
            return false;
        }
        return (bool) $this->app->get('SESSION.admin_uuid');
    }

    public function get_real_admin(): ?AuthenticatableInterface
    {
        if (!$this->is_impersonating()) {
            return null;
        }

        if (!$this->user_provider) {
            throw new \RuntimeException('User provider not configured. Call set_user_provider() first.');
        }

        $admin_uuid = $this->app->get('SESSION.admin_uuid');
        if (!$admin_uuid) {
            return null;
        }

        return $this->user_provider->find_by_id($admin_uuid);
    }
}
