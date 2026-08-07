<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

if (!defined('ATOMIC_ROOT')) {
    define('ATOMIC_ROOT', __DIR__);
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'const.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'error.php';

$autoloader = require ATOMIC_VENDOR . 'autoload.php';

// In monorepo dev mode, register skeleton App\ namespace
$skeletonAppDir = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'app';
if (is_dir($skeletonAppDir)) {
    $autoloader->setPsr4('App\\', [$skeletonAppDir . DIRECTORY_SEPARATOR]);
}
unset($skeletonAppDir, $autoloader);

require_once ATOMIC_SUPPORT . 'helpers.php';

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\PhpConfigLoader;
use Engine\Atomic\Core\Config\ConfigSchema;

// ── ConfigSchema definitions (single source of truth for all config keys) ──
ConfigSchema::string('APP_NAME')->default('Atomic');
ConfigSchema::string('APP_KEY')->required();
ConfigSchema::string('APP_UUID')->required();
ConfigSchema::string('APP_ENCRYPTION_KEY')->required();
ConfigSchema::string('APP_URL')->default('http://localhost:8000');
ConfigSchema::string('APP_TIMEZONE')->default('UTC');
ConfigSchema::string('APP_LOCALE')->default('en');
ConfigSchema::string('THEME')->default('default');
ConfigSchema::string('ENCODING')->default('UTF-8');
ConfigSchema::string('LANGUAGE')->default('en');
ConfigSchema::string('TZ')->default('UTC');
ConfigSchema::bool('DEBUG_MODE')->default(false);
ConfigSchema::string('DEBUG_LEVEL')->default('error');
ConfigSchema::string('DOMAIN')->default('');
ConfigSchema::string('DB_DRIVER')->default('mysql');
ConfigSchema::string('DB_HOST')->default('127.0.0.1');
ConfigSchema::string('DB_PORT')->default('3306');
ConfigSchema::string('DB_DB')->default('atomic');
ConfigSchema::string('DB_USERNAME')->default('root');
ConfigSchema::string('DB_PASSWORD')->required();
ConfigSchema::string('DB_CHARSET')->default('utf8mb4');
ConfigSchema::string('DB_COLLATION')->default('utf8mb4_general_ci');
ConfigSchema::string('DB_PREFIX')->default('atomic_');
ConfigSchema::string('CACHE_DRIVER')->default('folder');
ConfigSchema::string('CACHE_PREFIX')->default('atomic.');
ConfigSchema::int('CACHE_TTL')->default(3600);
ConfigSchema::string('SESSION_DRIVER')->default('db');
ConfigSchema::int('SESSION_LIFETIME')->default(259200);
ConfigSchema::string('SESSION_COOKIE')->default('Atomic_Session');
ConfigSchema::bool('SESSION_KILL_ON_SUSPECT')->default(true);
ConfigSchema::string('SESSION_REDIS_PREFIX')->default('atomic.session.');
ConfigSchema::int('COOKIE_EXPIRE')->default(259200);
ConfigSchema::string('COOKIE_PATH')->default('/');
ConfigSchema::string('COOKIE_DOMAIN')->default('');
ConfigSchema::bool('COOKIE_SECURE')->default(true);
ConfigSchema::bool('COOKIE_HTTPONLY')->default(true);
ConfigSchema::string('COOKIE_SAMESITE')->default('Lax');
ConfigSchema::string('MAIL_DRIVER')->default('smtp');
ConfigSchema::string('MAIL_HOST')->default('127.0.0.1');
ConfigSchema::int('MAIL_PORT')->default(587);
ConfigSchema::string('MAIL_FROM_ADDRESS')->default('no-reply@example.com');
ConfigSchema::string('MAIL_FROM_NAME')->default('Atomic');
ConfigSchema::string('QUEUE_DRIVER')->default('redis');
ConfigSchema::string('QUEUE_NAME')->default('default');
ConfigSchema::string('CORS_ORIGIN')->required();
ConfigSchema::string('CORS_HEADERS')->default('Content-Type,Authorization');
ConfigSchema::bool('CORS_CREDENTIALS')->default(false);
ConfigSchema::string('CORS_EXPOSE')->default('Authorization');
ConfigSchema::int('CORS_TTL')->default(86400);
ConfigSchema::string('REDIS_HOST')->default('127.0.0.1');
ConfigSchema::string('REDIS_PORT')->default('6379');
ConfigSchema::string('REDIS_PASSWORD')->default('');
ConfigSchema::string('REDIS_PREFIX')->default('atomic.');
ConfigSchema::string('MEMCACHED_HOST')->default('127.0.0.1');
ConfigSchema::string('MEMCACHED_PORT')->default('11211');
ConfigSchema::string('MEMCACHED_PREFIX')->default('atomic.');
ConfigSchema::string('MUTEX_DRIVER')->default('redis');
ConfigSchema::int('AUTH_RATE_LIMIT_MAX_ATTEMPTS')->default(5);
ConfigSchema::int('AUTH_RATE_LIMIT_WINDOW_SECONDS')->default(300);
ConfigSchema::int('AUTH_RATE_LIMIT_LOCKOUT_SECONDS')->default(900);

// ── Container (DI) ──
$container = new Container();
Container::setGlobal($container);

$atomic = \Base::instance();

// ── Config loading ──
switch (ATOMIC_LOADER) {
    case 'php':
        $phpLoader = new PhpConfigLoader($atomic);
        $phpLoader->load();
        break;
    case 'env':
    default:
        ConfigLoader::init($atomic, ATOMIC_ENV);
        break;
}

$application = App::instance($atomic);

// ── Register core bindings ──
$container->instance(\Base::class, $atomic);
$container->instance(App::class, $application);

// ── App hooks ──
\App\Event\Application::init();
\App\Hook\Application::init();

// ── Provider-based bootstrap (Container-native) ──
$newApp = new \Engine\Atomic\Core\Application($container);
$newApp
    ->registerProvider(new \Engine\Atomic\Core\Providers\ConfigServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\LogServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\ExceptionServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\PreflyServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\LocaleServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\UnloadServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\MiddlewareServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\CoreReadyServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\CorePluginServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\PluginServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\RouteServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\ScheduleServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\SessionServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\DatabaseServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\AuthServiceProvider())
    ->registerProvider(new \Engine\Atomic\Core\Providers\AppBootstrappedServiceProvider())
    ->boot();

return $application;
