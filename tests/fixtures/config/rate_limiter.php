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
    ],
];
