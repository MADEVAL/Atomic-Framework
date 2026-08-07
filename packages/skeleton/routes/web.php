<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

// Public
$atomic->route('GET /', 'App\Http\Controllers\HomeController->index');

// Authentication (split into dedicated controllers)
$atomic->route('GET  /login', 'App\Http\Controllers\Auth\LoginController->showForm');
$atomic->route('POST /login', 'App\Http\Controllers\Auth\LoginController->login');
$atomic->route('GET  /register', 'App\Http\Controllers\Auth\RegisterController->showForm');
$atomic->route('POST /register', 'App\Http\Controllers\Auth\RegisterController->register');
$atomic->route('POST /logout', 'App\Http\Controllers\Auth\LogoutController->logout');

// Password reset
$atomic->route('GET  /password/reset', 'App\Http\Controllers\Auth\PasswordResetController->showRequestForm');
$atomic->route('POST /password/reset', 'App\Http\Controllers\Auth\PasswordResetController->sendResetLink');
$atomic->route('GET  /password/reset/@token', 'App\Http\Controllers\Auth\PasswordResetController->showResetForm');
$atomic->route('POST /password/reset/@token', 'App\Http\Controllers\Auth\PasswordResetController->reset');

// Email verification
$atomic->route('GET  /email/verify', 'App\Http\Controllers\Auth\EmailVerificationController->notice', ['auth']);
$atomic->route('GET  /email/verify/@token', 'App\Http\Controllers\Auth\EmailVerificationController->verify');

// Protected (requires authentication)
$atomic->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index', ['auth']);
$atomic->route('GET /admin', 'App\Http\Controllers\Admin\DashboardController->index', ['auth', 'admin']);
