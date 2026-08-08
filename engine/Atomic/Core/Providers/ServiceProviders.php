<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Providers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\ServiceProvider;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\F3Bridge;
use Engine\Atomic\Core\Router;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Event\Event;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\App\PluginManager;

class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Hook::class, fn() => Hook::instance());
        $this->container->singleton(Event::class, fn() => Event::instance());
        $this->container->singleton(F3Bridge::class, fn() => new F3Bridge($this->container->get(App::class)->atomic()));
        $this->container->singleton(Router::class, fn() => new Router($this->container));
    }

    public function boot(): void
    {
        $app = $this->container->get(App::class);
        $loader = defined('ATOMIC_LOADER') ? ATOMIC_LOADER : 'env';
        $app->config_loaded($loader);
    }
}

class LogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(CacheManager::class, fn() => CacheManager::instance());
        $this->container->singleton(ConnectionManager::class, fn() => ConnectionManager::instance());
    }

    public function boot(): void
    {
        $this->container->get(App::class)->register_logger();
    }
}

class ExceptionServiceProvider extends ServiceProvider
{
    public function requires(): array { return [LogServiceProvider::class]; }

    public function boot(): void
    {
        $this->container->get(App::class)->register_exception_handler();
    }
}

class PreflyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->container->get(App::class)->prefly();
    }
}

class LocaleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->container->get(App::class)
            ->register_locales()
            ->register_locale_hrefs();
    }
}

class UnloadServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->container->get(App::class)->register_unload_handler();
    }
}

class MiddlewareServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->container->get(App::class)->register_middleware();
    }
}

class CoreReadyServiceProvider extends ServiceProvider
{
    public function requires(): array { return [MiddlewareServiceProvider::class]; }

    public function boot(): void
    {
        $this->container->get(App::class)->core_ready();
    }
}

class CorePluginServiceProvider extends ServiceProvider
{
    public function requires(): array { return [CoreReadyServiceProvider::class]; }

    public function register(): void
    {
        $this->container->singleton(PluginManager::class, fn() => PluginManager::instance());
    }

    public function boot(): void
    {
        $this->container->get(App::class)->register_core_plugins();
    }
}

class PluginServiceProvider extends ServiceProvider
{
    public function requires(): array { return [CorePluginServiceProvider::class]; }

    public function boot(): void
    {
        $this->container->get(App::class)->register_plugins();
    }
}

class RouteServiceProvider extends ServiceProvider
{
    public function requires(): array { return [PluginServiceProvider::class]; }

    public function boot(): void
    {
        $this->container->get(App::class)->register_routes();
    }
}

class ScheduleServiceProvider extends ServiceProvider
{
    public function requires(): array { return [RouteServiceProvider::class]; }

    public function boot(): void
    {
        $this->container->get(App::class)->register_schedule();
    }
}

class SessionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->container->get(App::class)->init_session();
    }
}

class DatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->container->get(App::class)->open_connections();
    }
}

class AuthServiceProvider extends ServiceProvider
{
    public function requires(): array { return [SessionServiceProvider::class]; }

    public function register(): void
    {
        $this->container->singleton(Auth::class, fn() => Auth::instance());
        // Register the SESSION_STARTED listener during the register phase so it
        // fires on the first session start (SessionServiceProvider::boot runs later).
        Auth::instance()->register_session_hooks();
    }

    public function boot(): void
    {
        $this->container->get(App::class)->register_user_provider();
    }
}

class AppBootstrappedServiceProvider extends ServiceProvider
{
    public function requires(): array { return [AuthServiceProvider::class, DatabaseServiceProvider::class, RouteServiceProvider::class]; }

    public function boot(): void
    {
        $this->container->get(App::class)->app_bootstrapped();
    }
}
