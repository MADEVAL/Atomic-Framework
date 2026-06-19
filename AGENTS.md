# AGENTS.md — Atomic Framework

## What is this?

A modular PHP framework built on **Fat-Free Framework (F3)**, not Laravel. Composer package: `globus-studio/atomic-framework`. Designed as a Composer dependency consumed by a separate application skeleton repo.

## Architecture

- **Framework core**: `engine/Atomic/` — the only code that ships in the Composer package. PSR-4 namespace `Engine\Atomic\` maps here.
- **Application skeleton**: `app/`, `bootstrap/`, `config/`, `routes/`, `storage/`, `public/`, `resources/` — excluded from git tracking (`.gitignore`). Used only for local framework development. Do not consider these directories as framework source.
- **Tests**: `tests/Engine/` mirrors `engine/Atomic/`. Test support classes in `tests/Support/`.
- **F3 integration**: `Engine\Atomic\Core\App` wraps F3's `\Base` as a singleton (has its own `instance()` — does NOT use the `Singleton` trait). It proxies unknown method calls to `\Base` via `__call`.
- **Entry point**: `engine/Atomic/index.php` (placeholder only — real bootstrapping happens in the application skeleton via `bootstrap/app.php`).

## Two-repo split

Framework repo (`globus-studio/atomic-framework`) and application skeleton (`MADEVAL/Atomic-Framework-Application`) differ in one critical constant:

- **Skeleton** — `bootstrap/const.php`: `ATOMIC_FRAMEWORK` = `vendor/globus-studio/atomic-framework/`
- **Framework dev** — `bootstrap/const.php`: `ATOMIC_FRAMEWORK` = `vendor/atomic/framework/`

`ATOMIC_FRAMEWORK` resolves to an absolute path via `realpath()`. `ATOMIC_ENGINE` and `ATOMIC_SUPPORT` are PHP constants that derive from it. Config keys like `FRAMEWORK_ROUTES`, `MIGRATIONS_CORE`, `LOCALES` are **F3 hive variables** set by `ConfigLoader` from `.env` — they use `ATOMIC_ENGINE` as base path but are not PHP constants themselves.

When writing framework code, only touch files inside `engine/Atomic/`. When writing app code, work in the skeleton repo instead.

### Bootstrap chain (skeleton canonical order)

The skeleton `bootstrap/app.php` is the authoritative reference. Order matters — hooks fire at specific points:

```
config_loaded → register_logger → register_exception_handler → prefly
→ register_locales → register_locale_hrefs → register_unload_handler
→ register_middleware → core_ready → register_core_plugins
→ register_plugins → register_routes → init_session
→ open_connections → register_user_provider → app_bootstrapped
```

`App\Hook\Application::init()` and `App\Event\Application::init()` are called **before** the fluent chain in the skeleton.

**DO NOT use the framework dev repo's `bootstrap/app.php` as reference.** Its method names are camelCase (`registerLogger`, `setDB`, etc.) that do NOT match `App`'s actual snake_case methods (`register_logger`, `open_connections`, etc.) — the dev bootstrap is non-functional structural placeholder.

## Configuration modes

Controlled by `ATOMIC_LOADER` in `bootstrap/const.php`:
- `env` (default) — reads `.env` file via `ConfigLoader`
- `php` — reads `config/*.php` array files via `PhpConfigLoader`

Config values become F3 variables (accessible via `$atomic->get('KEY')`). The `.env.example` in the skeleton is the complete reference of all recognized keys. For tests in this repo, the fixture at `tests/fixtures/.env` contains the test defaults.

## Route loading order

1. Framework routes: `engine/Atomic/Core/Routes/` (resolved from `FRAMEWORK_ROUTES` F3 hive variable)
2. App routes: `routes/` (skeleton, via `ATOMIC_APP_ROUTES`)
3. Plugin routes: loaded from each registered plugin's `routes/` directory

Request type detection in `App::detect_request_type()` — checks the **first URL segment** (not prefix):
- First segment is `api` → `api` (route file: `api.php`)
- First segment is `telemetry` → `telemetry` (`telemetry.php`)
- CLI → `cli` (`cli.php`)
- Everything else → `web` (`web.php`, `web.error.php`)

### Route registration API

Use `$this->route()` in route files, **not** `$atomic->route()`:
```php
// Without middleware
$this->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index');
// With middleware (3rd arg as array)
$this->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index', ['auth']);
```

`App::route()` wraps F3's route — it registers middleware aliases and disables TTL caching when controllers define custom `beforeroute`/`afterroute`.

## Plugins

- Plugins are **not** auto-discovered. Only classes listed in `config/providers.php` → `'plugins'` array are loaded.
- Load order: core plugins (`register_core_plugins`) → user plugins (`register_plugins` → `register_all` → `boot_all`).
- Plugin lifecycle managed by `Engine\Atomic\App\PluginManager`.
- Plugin dependencies live in the plugin's own `vendor/autoload.php`; Atomic loads it if it exists. Missing deps surface via `required_dependencies()`.

## App base classes

- Controllers extend `Engine\Atomic\App\Controller` (namespace: `App\Http\Controllers` in skeleton)
- Models extend `Engine\Atomic\App\Model` (namespace: `App\Http\Models` in skeleton)
- Middleware implements `Engine\Atomic\Core\Middleware\MiddlewareInterface` with `handle(\Base $atomic): bool`
- Plugins extend `Engine\Atomic\App\Plugin`
- Auth providers implement `Engine\Atomic\Auth\Interfaces\UserProviderInterface`:
  - `find_by_credentials(array $credentials): ?AuthenticatableInterface`
  - `find_by_id(string $auth_id): ?AuthenticatableInterface`

## Dev commands

```bash
# Install
composer install

# Run all tests (requires MySQL)
composer test
# Or:
php vendor/bin/phpunit --configuration tests/phpunit.xml

# Run a specific test group
php vendor/bin/phpunit --filter "Auth" --configuration tests/phpunit.xml

# Run a single test file
php vendor/bin/phpunit tests/Engine/Core/CryptoTest.php --configuration tests/phpunit.xml
```

## Test prerequisites

Tests are integration-style and require **MySQL** running on `127.0.0.1:3306` with:
- Database: `atomic_test`
- User: `atomic_test_user` / `atomic_test_pass`
- Tables are auto-created by `tests/bootstrap.php`

Credentials are set in `tests/phpunit.xml` `<php><env>` block and can be overridden via the real `.env` or environment variables. Test fixture `.env` exists at `tests/fixtures/.env`.

No linter, static analysis, or CI workflows are present in this repo.

## Key conventions

- Every file guarded by `if (!defined('ATOMIC_START')) exit;`
- `declare(strict_types=1)` in all PHP files
- Hook system (`Engine\Atomic\Hook\Hook`) is WordPress-compatible (actions + filters)
- Event system (`Engine\Atomic\Event\Event`) is hierarchical with priorities
- Cache: use `Transient` or `CacheManager::instance()->cascade()`, **not** F3's `\Cache::instance()` directly
- PHP ≥ 8.1 required with extensions: json, session, mbstring, fileinfo, pdo, pdo_mysql, curl

## Documentation

Docs in `docs/`. Root `README.md` is the comprehensive reference. `docs/testing_guide.md` covers test patterns. Application skeleton: https://github.com/MADEVAL/Atomic-Framework-Application
