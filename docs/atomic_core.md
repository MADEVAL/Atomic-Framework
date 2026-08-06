## Atomic Core ##

`Engine\Atomic\Core\App` is the main bootstrap wrapper around the Fat-Free `Base` instance.

Typical startup flow (as generated in `bootstrap/app.php`):

```php
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\PhpConfigLoader;

$f3 = \Base::instance();

switch (ATOMIC_LOADER) {
    case 'php':
        (new PhpConfigLoader($f3))->load();
        $loader = 'php';
        break;
    case 'env':
    default:
        ConfigLoader::init($f3, ATOMIC_ENV);
        $loader = 'env';
        break;
}

$app = App::instance($f3);

\App\Event\Application::instance()->init();
\App\Hook\Application::instance()->init();

$app->config_loaded($loader)
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
    ->app_bootstrapped()
;
```

### What `App` manages

- boot checks via `prefly()`
- logger and exception registration
- locale bootstrapping and language-prefix path normalization
- connection opening via `open_connections()`
- session initialization
- route loading from framework and app route files
- middleware alias registration
- core and user plugin registration
- CLI command dispatch via `handle_command()`

### Routing helper

`App::route()` accepts either cache TTL or middleware aliases as the third argument:

```php
$app->route('GET /dashboard', 'App\\Http\\Dashboard->index', ['auth', 'verified']);
$app->route('GET /posts/@id', 'App\\Http\\Posts->show', 60);
```

When middleware is passed, Atomic stores the middleware mapping in `MiddlewareStack` and disables F3 route caching for that route.

### Route loading

Request type is detected from the current path:

- CLI requests load CLI routes
- `/api/...` loads API routes
- `/telemetry/...` loads telemetry routes
- everything else loads web routes

Framework route files are resolved from `FRAMEWORK_ROUTES`.

`register_plugins()` loads, registers, and boots plugins, then fires `ApplicationHook::PLUGINS_LOADED`. `register_routes()` runs after plugins are ready, loads the detected framework/app route group, fires `ApplicationHook::ROUTES_REGISTERED` with source `app`, then loads booted plugin route files and fires `ApplicationHook::ROUTES_REGISTERED` with source `plugin`. Plugins can add route file types during plugin registration or `ApplicationHook::PLUGINS_LOADED` with `register_route_type()`.

Plugin registration and boot run before `open_connections()`. Plugin code that needs an early database, Redis, or Memcached connection can opt in with `ConnectionManager::instance()->get_db()`, `get_redis()`, or `get_memcached()`, but should handle connection failures explicitly.

### Bootstrap constants and PHP error logging

`bootstrap/const.php` defines Atomic bootstrap constants such as:

- loader mode (`ATOMIC_LOADER`)
- root and framework paths (`ATOMIC_DIR`, `ATOMIC_FRAMEWORK`, `ATOMIC_ENGINE`, etc.)
- environment file (`ATOMIC_ENV`)
- runtime toggles and defaults (`ATOMIC_PHP_ERRORS`, cache/image/http constants)

`bootstrap/error.php` configures native PHP error logging to:

- `storage/logs/php_errors-YYYY-MM-DD.log`
- only when `storage/logs` is writable

### Connection lifecycle

```php
$app->open_connections();
\Engine\Atomic\Core\ConnectionManager::instance()->close();
```

### CLI entry

```php
exit(App::instance()->handle_command($argv));
```

`handle_command()` normalizes commands like `schedule:run` into `/schedule/run` before calling `run()`.
