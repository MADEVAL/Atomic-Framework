<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

// API health check
$atomic->route('GET /api/health', 'App\Http\Controllers\Api\HealthController@index');

// API v1 routes
$atomic->route('POST /api/v1/auth/login', 'App\Http\Controllers\Auth\LoginController@login');
$atomic->route('POST /api/v1/auth/register', 'App\Http\Controllers\Auth\RegisterController@register');
