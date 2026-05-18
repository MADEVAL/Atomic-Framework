<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\RateLimit\Middleware\RateLimitMiddleware;
use Engine\Atomic\RateLimit\RateLimiter;

return [
    'fail' => RateLimiter::FAIL_OPEN,
    'policies' => [
        'default' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 60,
            'window'   => 60,
        ],
        'api' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 100,
            'window'   => 60,
        ],
        'user' => [
            'strategy' => RateLimiter::STRATEGY_SLIDING,
            'key'      => RateLimitMiddleware::KEY_USER,
            'limit'    => 1000,
            'window'   => 3600,
        ],
        'ai' => [
            'strategy' => RateLimiter::STRATEGY_CONCURRENCY,
            'key'      => RateLimitMiddleware::KEY_USER,
            'limit'    => 2,
            'window'   => 300,
        ],
        'search' => [
            'strategy' => RateLimiter::STRATEGY_SLIDING,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 30,
            'window'   => 60,
        ],
        'auth_register_ip' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 10,
            'window'   => 3600,
        ],
        'auth_register_credential' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 3,
            'window'   => 86400,
        ],
        'auth_login_ip' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 20,
            'window'   => 3600,
        ],
        'auth_login_credential' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 5,
            'window'   => 1800,
        ],
    ],
];
