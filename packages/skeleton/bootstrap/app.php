<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

if (!defined('ATOMIC_ROOT')) {
    define('ATOMIC_ROOT', __DIR__);
}
require_once ATOMIC_ROOT . DIRECTORY_SEPARATOR . 'const.php';
require_once ATOMIC_ROOT . DIRECTORY_SEPARATOR . 'error.php';
require_once ATOMIC_VENDOR . 'autoload.php';
require_once ATOMIC_SUPPORT . 'helpers.php';

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\PhpConfigLoader;

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
$container->singleton(\Engine\Atomic\Core\CacheManager::class, \Engine\Atomic\Core\CacheManager::class);
$container->singleton(\Engine\Atomic\Core\ConnectionManager::class, \Engine\Atomic\Core\ConnectionManager::class);
$container->singleton(\Engine\Atomic\Core\F3Bridge::class, fn() => new \Engine\Atomic\Core\F3Bridge($atomic));

// ── App hooks ──
\App\Event\Application::instance()->init();
\App\Hook\Application::instance()->init();

// ── Provider-based bootstrap (Container-native, replaces old 16-step chain) ──
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
