<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$this->route('GET /error/404', 'App\Http\Controllers\ErrorController->notFound');
$this->route('GET /error/403', 'App\Http\Controllers\ErrorController->forbidden');
$this->route('GET /error/500', 'App\Http\Controllers\ErrorController->serverError');
$this->route('GET /error/503', 'App\Http\Controllers\ErrorController->maintenance');
