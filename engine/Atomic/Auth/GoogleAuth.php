<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\GoogleClientAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Interfaces\OAuthUserResolverInterface;
use Engine\Atomic\Auth\Services\GoogleAuthService;

class GoogleAuth extends \Prefab
{
    private ?GoogleAuthService $service = null;

    private function service(): GoogleAuthService
    {
        if ($this->service === null) {
            $app = new AppContextAdapter();
            $this->service = new GoogleAuthService(
                $app,
                new GoogleClientAdapter($app),
                new LogAdapter(),
                new IdValidatorAdapter(),
                Auth::instance(),
            );
        }
        return $this->service;
    }

    public function set_user_resolver(OAuthUserResolverInterface $resolver): self
    {
        $this->service()->set_user_resolver($resolver);
        return $this;
    }

    public function getLoginUrl(): string
    {
        return $this->service()->getLoginUrl();
    }

    public function handleCallback(string $code, ?string $state = null): ?string
    {
        return $this->service()->handleCallback($code, $state);
    }

    public function isConfigured(): bool
    {
        return $this->service()->isConfigured();
    }
}
