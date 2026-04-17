<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\BcryptHasherAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\MetaStorageAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Auth\Adapters\SessionManagerAdapter;
use Engine\Atomic\Auth\Adapters\SystemClockAdapter;
use Engine\Atomic\Auth\Adapters\TransientCacheAdapter;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\LoginInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Auth\Services\AuthService;
use Engine\Atomic\Auth\Services\SessionService;

class Auth extends \Prefab implements LoginInterface
{
    private ?AuthService $service = null;

    private function service(): AuthService
    {
        if ($this->service === null) {
            $app = new AppContextAdapter();
            $session_service = new SessionService(
                $app,
                new PhpSessionAdapter(),
                new SessionDriverFactoryAdapter($app),
                new SystemClockAdapter(),
                new IdValidatorAdapter(),
                new LogAdapter(),
            );
            $this->service = new AuthService(
                $app,
                $session_service,
                new MetaStorageAdapter(),
                new TransientCacheAdapter(),
                new LogAdapter(),
                new SystemClockAdapter(),
                new PhpSessionAdapter(),
                new BcryptHasherAdapter(),
                new SessionManagerAdapter(),
            );
        }
        return $this->service;
    }

    public function set_user_provider(UserProviderInterface $provider): self
    {
        $this->service()->set_user_provider($provider);
        return $this;
    }

    public function login_by_id(string $auth_id, array $context = []): void
    {
        $this->service()->login_by_id($auth_id, $context);
    }

    public function check_rate_limit(array $credentials, string $operation = 'register'): bool
    {
        return $this->service()->check_rate_limit($credentials, $operation);
    }

    public function login_with_secret(array $credentials, string $secret): ?AuthenticatableInterface
    {
        return $this->service()->login_with_secret($credentials, $secret);
    }

    public function logout(): void
    {
        $this->service()->logout();
    }

    public static function kill_all_sessions(string $user_id, bool $keep_current = true): int
    {
        return self::instance()->service()->kill_all_sessions($user_id, $keep_current);
    }

    public function get_current_user(): ?AuthenticatableInterface
    {
        return $this->service()->get_current_user();
    }

    public function impersonate_user(string $user_uuid): bool
    {
        return $this->service()->impersonate_user($user_uuid);
    }

    public function stop_impersonation(): bool
    {
        return $this->service()->stop_impersonation();
    }

    public function is_impersonating(): bool
    {
        return $this->service()->is_impersonating();
    }

    public function get_real_admin(): ?AuthenticatableInterface
    {
        return $this->service()->get_real_admin();
    }
}
