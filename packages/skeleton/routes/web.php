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

// Protected (requires authentication)
$atomic->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index', ['auth']);
