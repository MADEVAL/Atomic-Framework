<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Google\Client as GoogleClient;
use Google\Service\Oauth2 as GoogleOauth2;

class GoogleClientAdapter
{
    private ?GoogleClient $client = null;

    public function __construct(private AppContextAdapter $app) {}

    private function client(): GoogleClient
    {
        if ($this->client === null) {
            $config = $this->app->get('OAUTH.google');
            $this->client = new GoogleClient();
            $this->client->setClientId($config['client_id']);
            $this->client->setClientSecret($config['client_secret']);
            $this->client->setRedirectUri($config['redirect_uri']);
            $this->client->addScope('email');
            $this->client->addScope('profile');
        }
        return $this->client;
    }

    public function create_auth_url(?string $state = null): string
    {
        if ($state !== null) {
            $this->client()->setState($state);
        }
        return $this->client()->createAuthUrl();
    }

    public function fetch_user_info_by_code(string $code): ?array
    {
        $client = $this->client();
        $token  = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return null;
        }

        $client->setAccessToken($token);
        $info = (new GoogleOauth2($client))->userinfo->get();

        return [
            'id'          => (string) $info->getId(),
            'email'       => $info->getEmail(),
            'name'        => $info->getName(),
            'given_name'  => $info->getGivenName(),
            'family_name' => $info->getFamilyName(),
            'picture'     => $info->getPicture(),
        ];
    }
}
