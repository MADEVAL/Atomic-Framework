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
            'client_id'     => '',  // OAUTH_GOOGLE_CLIENT_ID from .env
            'client_secret' => '',  // OAUTH_GOOGLE_CLIENT_SECRET from .env
            'redirect_uri'  => '',  // OAUTH_GOOGLE_REDIRECT_URI from .env
        ],
        'telegram' => [
            'bot_username'  => '',  // OAUTH_TELEGRAM_BOT_USERNAME from .env
            'bot_token'     => '',  // OAUTH_TELEGRAM_BOT_TOKEN from .env
            'callback_url'  => '/auth/telegram/callback',
        ],
    ],
];
