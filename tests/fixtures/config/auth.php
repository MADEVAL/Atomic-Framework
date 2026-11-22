<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'providers' => [
        'users' => [
            'driver' => 'db',
            'table'  => 'users',
        ],
    ],
    'password_timeout' => 10800,
    'password_confirm' => true,
    'oauth' => [
        'google' => [
            'client_id'     => 'fixture-google-client-id',  // OAUTH_GOOGLE_CLIENT_ID from .env
            'client_secret' => 'fixture-google-client-secret',  // OAUTH_GOOGLE_CLIENT_SECRET from .env
            'redirect_uri'  => 'https://example.test/auth/google/callback',  // OAUTH_GOOGLE_REDIRECT_URI from .env
        ],
        'telegram' => [
            'bot_username'  => 'fixture_bot',  // OAUTH_TELEGRAM_BOT_USERNAME from .env
            'bot_token'     => '123456:fixture-telegram-token',  // OAUTH_TELEGRAM_BOT_TOKEN from .env
            'callback_url'  => '/auth/telegram/fixture-callback',
            'auth_max_age'  => 120, // OAUTH_TELEGRAM_AUTH_MAX_AGE from .env; seconds signed login data stays valid (1..86400)
        ],
    ],
];
