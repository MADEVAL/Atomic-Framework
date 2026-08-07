<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'auth'  => App\Http\Middleware\Authenticate::class,
    'guest' => App\Http\Middleware\RedirectIfAuthenticated::class,
    'admin' => App\Http\Middleware\RequireAdmin::class,
    'throttle' => App\Http\Middleware\ThrottleRequests::class,
];
