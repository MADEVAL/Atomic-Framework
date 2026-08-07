<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

// Custom error pages — override framework defaults
$atomic->route('GET /error/404', 'App\Http\Controllers\ErrorController@notFound');
$atomic->route('GET /error/403', 'App\Http\Controllers\ErrorController@forbidden');
$atomic->route('GET /error/500', 'App\Http\Controllers\ErrorController@serverError');
$atomic->route('GET /error/503', 'App\Http\Controllers\ErrorController@maintenance');
