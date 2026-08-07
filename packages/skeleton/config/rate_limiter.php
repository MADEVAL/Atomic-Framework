<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'fail' => 'open',

    'policies' => [
        'default' => [
            'strategy' => 'fixed',
            'key'      => 'ip',
            'limit'    => 60,
            'window'   => 60,
        ],
        'api' => [
            'strategy' => 'fixed',
            'key'      => 'ip',
            'limit'    => 100,
            'window'   => 60,
        ],
        'user' => [
            'strategy' => 'sliding',
            'key'      => 'user',
            'limit'    => 1000,
            'window'   => 3600,
        ],
        'auth_register_ip' => [
            'strategy' => 'fixed',
            'key'      => 'ip',
            'limit'    => 10,
            'window'   => 3600,
        ],
        'auth_register_credential' => [
            'strategy' => 'fixed',
            'key'      => 'ip',
            'limit'    => 3,
            'window'   => 86400,
        ],
        'auth_login_ip' => [
            'strategy' => 'fixed',
            'key'      => 'ip',
            'limit'    => 20,
            'window'   => 3600,
        ],
        'auth_login_credential' => [
            'strategy' => 'fixed',
            'key'      => 'ip',
            'limit'    => 5,
            'window'   => 1800,
        ],
        'ai' => [
            'strategy' => 'concurrency',
            'key'      => 'user',
            'limit'    => 2,
            'window'   => 300,
        ],
        'search' => [
            'strategy' => 'sliding',
            'key'      => 'ip',
            'limit'    => 30,
            'window'   => 60,
        ],
    ],
];
