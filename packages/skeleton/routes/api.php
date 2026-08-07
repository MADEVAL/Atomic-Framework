<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$this->route('GET /api/health', 'App\Http\Controllers\Api\HealthController->index');

$this->route('POST /api/v1/auth/login', 'App\Http\Controllers\Auth\LoginController->login', ['throttle']);
$this->route('POST /api/v1/auth/register', 'App\Http\Controllers\Auth\RegisterController->register', ['throttle']);
