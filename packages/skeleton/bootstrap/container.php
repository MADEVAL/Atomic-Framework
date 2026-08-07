<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\F3Bridge;
use Engine\Atomic\Core\Router;
use Engine\Atomic\Core\Config\V2\Config;
use Engine\Atomic\Core\Config\V2\ConfigLoader;
use Engine\Atomic\Core\Config\ConfigSchema;

// ── Define ConfigSchema keys ──
ConfigSchema::string('APP_NAME')->default('Atomic');
ConfigSchema::string('APP_KEY')->required();
ConfigSchema::string('APP_UUID')->required();
ConfigSchema::string('APP_ENCRYPTION_KEY')->required();
ConfigSchema::string('APP_URL')->default('http://localhost:8000');
ConfigSchema::string('APP_TIMEZONE')->default('UTC');
ConfigSchema::string('APP_LOCALE')->default('en');
ConfigSchema::bool('DEBUG_MODE')->default(false);
ConfigSchema::string('DB_DSN')->default('mysql:host=127.0.0.1;port=3306;dbname=atomic');
ConfigSchema::string('DB_USERNAME')->default('root');
ConfigSchema::string('DB_PASSWORD')->required();
ConfigSchema::string('DB_PREFIX')->default('atomic_');
ConfigSchema::string('CACHE_DRIVER')->default('folder');
ConfigSchema::string('CACHE_PREFIX')->default('atomic.');
ConfigSchema::int('CACHE_TTL')->default(3600);
ConfigSchema::string('SESSION_DRIVER')->default('db');
ConfigSchema::int('SESSION_LIFETIME')->default(259200);
ConfigSchema::bool('SESSION_SECURE')->default(true);
ConfigSchema::bool('SESSION_HTTPONLY')->default(true);
ConfigSchema::string('SESSION_SAMESITE')->default('lax')->in(['lax', 'strict', 'none']);
ConfigSchema::string('MAIL_DRIVER')->default('smtp')->in(['smtp', 'sendmail', 'log']);
ConfigSchema::string('MAIL_HOST')->default('127.0.0.1');
ConfigSchema::int('MAIL_PORT')->default(1025);
ConfigSchema::string('MAIL_FROM_ADDRESS')->default('no-reply@example.com');
ConfigSchema::string('QUEUE_DRIVER')->default('database');
ConfigSchema::int('QUEUE_WORKER_COUNT')->default(5);
ConfigSchema::int('QUEUE_TTL')->default(604800);
ConfigSchema::string('CORS_ORIGIN')->required();
ConfigSchema::bool('CORS_CREDENTIALS')->default(false);
ConfigSchema::int('AUTH_RATE_LIMIT_MAX_ATTEMPTS')->default(5);
ConfigSchema::int('AUTH_RATE_LIMIT_WINDOW_SECONDS')->default(300);
ConfigSchema::int('AUTH_RATE_LIMIT_LOCKOUT_SECONDS')->default(900);

// ── Build Container ──
$container = new Container();

// F3 bridge (explicit access to \Base)
$container->singleton(F3Bridge::class, fn() => new F3Bridge(\Base::instance()));

// Config (V2 cascade loader)
$container->singleton(Config::class, function () {
    return ConfigLoader::create()
        ->fromDefaults()
        ->fromEnvFile(ATOMIC_DIR . '/.env')
        ->fromEnvironment()
        ->load();
});

// Router
$container->singleton(Router::class, fn() => new Router($container));

// Export container for Application
return $container;
