<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

define('ATOMIC_ROOT', __DIR__);
require_once ATOMIC_ROOT . DIRECTORY_SEPARATOR . 'const.php';
require_once ATOMIC_ROOT . DIRECTORY_SEPARATOR . 'error.php';
require_once ATOMIC_VENDOR . 'autoload.php';
require_once ATOMIC_SUPPORT . 'helpers.php';

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
    ->init_session()
    ->open_connections()
    ->register_user_provider()
    ->app_bootstrapped();

return $application;
