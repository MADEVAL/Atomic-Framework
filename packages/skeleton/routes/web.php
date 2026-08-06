<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

// Public
$atomic->route('GET /', 'App\Http\Controllers\HomeController->index');

// Authentication
$atomic->route('GET  /login', 'App\Http\Controllers\AuthController->login');
$atomic->route('POST /login', 'App\Http\Controllers\AuthController->login');
$atomic->route('GET  /register', 'App\Http\Controllers\AuthController->register');
$atomic->route('POST /register', 'App\Http\Controllers\AuthController->register');
$atomic->route('GET  /logout', 'App\Http\Controllers\AuthController->logout');

// Protected (requires authentication)
$atomic->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index', ['auth']);
