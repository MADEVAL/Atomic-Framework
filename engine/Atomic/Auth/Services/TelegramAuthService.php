<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Services;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\TelegramClientAdapter;
use Engine\Atomic\Auth\Interfaces\LoginInterface;
use Engine\Atomic\Auth\Interfaces\OAuthUserResolverInterface;

class TelegramAuthService
{
    private ?OAuthUserResolverInterface $user_resolver = null;

    public function __construct(
        private AppContextAdapter     $app,
        private TelegramClientAdapter $telegram_client,
        private LogAdapter            $logger,
        private IdValidatorAdapter    $id_validator,
        private LoginInterface        $auth,
    ) {}

    public function set_user_resolver(OAuthUserResolverInterface $resolver): self
    {
        $this->user_resolver = $resolver;
        return $this;
    }

    public function get_bot_username(): string
    {
        return $this->config()['bot_username'] ?? '';
    }

    public function get_callback_url(): string
    {
        return $this->config()['callback_url'] ?? '/auth/telegram/callback';
    }

    public function is_configured(): bool
    {
        $config = $this->config();
        return !empty($config['bot_username']) && !empty($config['bot_token']);
    }

    public function verify_auth_data(array $auth_data): array|false
    {
        $bot_token = $this->config()['bot_token'] ?? null;
        return $this->telegram_client->verify_login_widget($auth_data, (string) $bot_token);
    }

    public function handle_callback(array $auth_data): ?string
    {
        if (!$this->user_resolver) {
            $this->logger->error('[TelegramAuth] User resolver is not configured');
            throw new \RuntimeException('OAuthUserResolverInterface not configured. Call set_user_resolver() before handle_callback().');
        }

        try {
            $verified_data = $this->verify_auth_data($auth_data);
            if ($verified_data === false) {
                $this->logger->warning('[TelegramAuth] Auth data verification failed');
                return null;
            }

            $claims = $this->normalize_claims($verified_data);

            $user_identifier = $this->user_resolver->resolve_oauth_user($claims);
            if (!$user_identifier || !$this->id_validator->is_valid_uuid_v4($user_identifier)) {
                $this->logger->error('[TelegramAuth] User resolution failed', ['telegram_id' => $claims['telegram_id']]);
                return null;
            }

            $this->auth->login_by_id($user_identifier, [
                'auth_provider' => 'telegram',
                'telegram_id'   => $claims['telegram_id'],
            ]);

            return $user_identifier;
        } catch (\Throwable $e) {
            $this->logger->error('[TelegramAuth] Callback processing failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function get_widget_attributes(string $size = 'large', bool $requestAccess = false, bool $useAvatar = false, int $cornerRadius = 20): array
    {
        return $this->telegram_client->get_widget_attributes($size, $requestAccess, $useAvatar, $cornerRadius);
    }

    private function normalize_claims(array $verified_data): array
    {
        $telegram_id = (string) $verified_data['id'];
        $first_name  = $verified_data['first_name'] ?? '';
        $last_name   = $verified_data['last_name'] ?? '';
        $username    = $verified_data['username'] ?? null;
        $full_name   = trim($first_name . ' ' . $last_name);

        return [
            'telegram_id' => $telegram_id,
            'username'    => $username,
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'full_name'   => $full_name ?: ($username ?: 'Telegram User'),
            'avatar_url'  => $verified_data['photo_url'] ?? null,
            'raw'         => $verified_data,
        ];
    }

    private function config(): array
    {
        return $this->app->get('OAUTH.telegram') ?: [];
    }
}
