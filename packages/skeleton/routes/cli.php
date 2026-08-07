<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

// CLI commands
$atomic->route('GET /cache/clear [cli]', 'App\Http\Controllers\CLI\CacheController@clear');
$atomic->route('GET /make/controller [cli]', 'App\Http\Controllers\CLI\MakeController@handle');
$atomic->route('GET /make/model [cli]', 'App\Http\Controllers\CLI\MakeModel@handle');
$atomic->route('GET /make/middleware [cli]', 'App\Http\Controllers\CLI\MakeMiddleware@handle');
