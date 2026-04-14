<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Services;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\GoogleClientAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Interfaces\LoginInterface;
use Engine\Atomic\Auth\Interfaces\OAuthUserResolverInterface;

class GoogleAuthService
{
    private ?OAuthUserResolverInterface $user_resolver = null;

    public function __construct(
        private AppContextAdapter    $app,
        private GoogleClientAdapter  $google_client,
        private LogAdapter           $logger,
        private IdValidatorAdapter   $id_validator,
        private LoginInterface       $auth,
    ) {}

    public function set_user_resolver(OAuthUserResolverInterface $resolver): self
    {
        $this->user_resolver = $resolver;
        return $this;
    }

    public function getLoginUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $this->app->set('SESSION.oauth_google_state', $state);

        return $this->google_client->create_auth_url($state);
    }

    public function handleCallback(string $code, ?string $state = null): ?string
    {
        $stored_state = $this->app->get('SESSION.oauth_google_state');
        $this->app->clear('SESSION.oauth_google_state');

        if (
            !is_string($stored_state)
            || $stored_state === ''
            || !is_string($state)
            || $state === ''
            || !hash_equals($stored_state, $state)
        ) {
            $this->logger->warning('[GoogleAuth] Invalid OAuth state');
            return null;
        }

        if (!$this->user_resolver) {
            $this->logger->error('[GoogleAuth] User resolver is not configured');
            throw new \RuntimeException('OAuthUserResolverInterface not configured. Call set_user_resolver() before handleCallback().');
        }

        try {
            $info = $this->google_client->fetch_user_info_by_code($code);
            if ($info === null) {
                $this->logger->warning('[GoogleAuth] Token exchange failed');
                return null;
            }

            $claims = $this->normalize_claims($info);

            $user_identifier = $this->user_resolver->resolve_oauth_user($claims);
            if (!$user_identifier || !$this->id_validator->is_valid_uuid_v4($user_identifier)) {
                $this->logger->error('[GoogleAuth] User resolution failed', ['google_id' => $claims['google_id']]);
                return null;
            }

            $this->auth->login_by_id($user_identifier, [
                'auth_provider' => 'google',
                'google_id'     => $claims['google_id'],
            ]);

            return $user_identifier;
        } catch (\Throwable $e) {
            $this->logger->error('[GoogleAuth] Callback processing failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function isConfigured(): bool
    {
        $config = $this->app->get('OAUTH.google') ?: [];
        return !empty($config['client_id'])
            && !empty($config['client_secret'])
            && !empty($config['redirect_uri']);
    }

    private function normalize_claims(array $info): array
    {
        $google_id = $info['id'];
        $email     = $info['email'];

        return [
            'google_id'   => $google_id,
            'email'       => $email,
            'full_name'   => $info['name'] ?: ($email ? explode('@', $email)[0] : 'Google User'),
            'first_name'  => $info['given_name'],
            'last_name'   => $info['family_name'],
            'avatar_url'  => $info['picture'],
            'raw'         => $info,
        ];
    }
}
