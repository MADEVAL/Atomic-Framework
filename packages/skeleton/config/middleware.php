<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'auth'  => App\Http\Middleware\Authenticate::class,
    'admin' => App\Http\Middleware\RequireAdmin::class,
];
