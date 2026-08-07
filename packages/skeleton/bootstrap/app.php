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

// ── Container (DI kernel) — registered before everything so singletons delegate here ──
use Engine\Atomic\Core\Container;

$container = new Container();
Container::setGlobal($container);

$atomic = \Base::instance();

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\PhpConfigLoader;

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

// ── Register core services in Container (migrates ::instance() to Container) ──
$container->instance(\Base::class, $atomic);
$container->instance(App::class, $application);
$container->singleton(\Engine\Atomic\Core\CacheManager::class, \Engine\Atomic\Core\CacheManager::class);
$container->singleton(\Engine\Atomic\Core\ConnectionManager::class, \Engine\Atomic\Core\ConnectionManager::class);
$container->singleton(\Engine\Atomic\Core\F3Bridge::class, fn() => new \Engine\Atomic\Core\F3Bridge($atomic));

\App\Event\Application::instance()->init();
\App\Hook\Application::instance()->init();

$application
    ->config_loaded($loader ?? null)
    ->register_logger()
    ->register_exception_handler()
    ->prefly()
    ->register_locales()
    ->register_locale_hrefs()
    ->register_unload_handler()
    ->register_middleware()
    ->core_ready()
    ->register_core_plugins()
    ->register_plugins()
    ->register_routes()
    ->register_schedule()
    ->init_session()
    ->open_connections()
    ->register_user_provider()
    ->app_bootstrapped();

return $application;
