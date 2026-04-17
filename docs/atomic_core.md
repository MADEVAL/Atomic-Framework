## Atomic Core ##

`Engine\Atomic\Core\App` is the main bootstrap wrapper around the Fat-Free `Base` instance.

Typical startup flow:

```php
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Config\ConfigLoader;

$f3 = \Base::instance();

ConfigLoader::init($f3, realpath(__DIR__ . '/../.env'));

$app = App::instance($f3);

$app->prefly()
    ->register_logger()
    ->register_exception_handler()
    ->register_locales()
    ->register_locale_hrefs()
    ->register_middleware()
    ->init_session()
    ->register_core_plugins()
    ->register_plugins()
    ->register_user_provider()
    ->register_routes()
    ->run();
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
