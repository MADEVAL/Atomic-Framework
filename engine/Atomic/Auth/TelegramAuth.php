<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\TelegramClientAdapter;
use Engine\Atomic\Auth\Interfaces\OAuthUserResolverInterface;
use Engine\Atomic\Auth\Services\TelegramAuthService;

class TelegramAuth extends \Prefab
{
    private ?TelegramAuthService $service = null;

    private function service(): TelegramAuthService
    {
        if ($this->service === null) {
            $this->service = new TelegramAuthService(
                new AppContextAdapter(),
                new TelegramClientAdapter(),
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

    public function get_bot_username(): string
    {
        return $this->service()->get_bot_username();
    }

    public function get_callback_url(): string
    {
        return $this->service()->get_callback_url();
    }

    public function is_configured(): bool
    {
        return $this->service()->is_configured();
    }

    public function verify_auth_data(array $auth_data): array|false
    {
        return $this->service()->verify_auth_data($auth_data);
    }

    public function handle_callback(array $auth_data): ?string
    {
        return $this->service()->handle_callback($auth_data);
    }

    public function get_widget_attributes(string $size = 'large', bool $request_access = false, bool $use_avatar = false, int $corner_radius = 20): array
    {
        return $this->service()->get_widget_attributes($size, $request_access, $use_avatar, $corner_radius);
    }
}
