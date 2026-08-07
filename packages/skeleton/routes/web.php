<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

// Public
$this->route('GET /', 'App\Http\Controllers\HomeController->index');

// Authentication (split into dedicated controllers)
$this->route('GET  /login', 'App\Http\Controllers\Auth\LoginController->showForm', ['guest', 'throttle']);
$this->route('POST /login', 'App\Http\Controllers\Auth\LoginController->login', ['throttle']);
$this->route('GET  /register', 'App\Http\Controllers\Auth\RegisterController->showForm', ['guest', 'throttle']);
$this->route('POST /register', 'App\Http\Controllers\Auth\RegisterController->register', ['throttle']);
$this->route('POST /logout', 'App\Http\Controllers\Auth\LogoutController->logout', ['auth', 'csrf_token']);

// Password reset
$this->route('GET  /password/reset', 'App\Http\Controllers\Auth\PasswordResetController->showRequestForm');
$this->route('POST /password/reset', 'App\Http\Controllers\Auth\PasswordResetController->sendResetLink');
$this->route('GET  /password/reset/@token', 'App\Http\Controllers\Auth\PasswordResetController->showResetForm');
$this->route('POST /password/reset/@token', 'App\Http\Controllers\Auth\PasswordResetController->reset');

// Email verification
$this->route('GET  /email/verify', 'App\Http\Controllers\Auth\EmailVerificationController->notice', ['auth']);
$this->route('GET  /email/verify/@token', 'App\Http\Controllers\Auth\EmailVerificationController->verify');

// Protected (requires authentication)
$this->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index', ['auth', 'verified']);
$this->route('GET /admin', 'App\Http\Controllers\Admin\DashboardController->index', ['auth', 'admin']);

// Example theme showcase — demonstrates all theme features
$this->route('GET /example', 'App\Http\Controllers\ExampleController->showcase');
