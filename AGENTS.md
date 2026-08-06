# AGENTS.md — Atomic Framework

## What is this?

A modular PHP framework built on **Fat-Free Framework (F3)**, not Laravel. Composer package: `globus-studio/atomic-framework`. Designed as a Composer dependency consumed by a separate application skeleton package.

This is a **monorepo** containing both packages:
- `packages/framework/` — Composer package `globus-studio/atomic-framework`
- `packages/skeleton/` — Composer package `globus-studio/atomic-framework-application`

On release tag, GitHub Actions auto-splits each package to its own repo (`MADEVAL/Atomic-Framework`, `MADEVAL/Atomic-Framework-Application`) for Packagist.

## Architecture

- **Framework core**: `packages/framework/engine/Atomic/` — the only code that ships in the framework Composer package. PSR-4 namespace `Engine\Atomic\` maps here.
- **Application skeleton**: `packages/skeleton/` — app code (`app/`, `bootstrap/`, `config/`, `routes/`, `public/`, `resources/`, `database/`). Published as `globus-studio/atomic-framework-application`.
- **Tests**: `packages/framework/tests/Engine/` mirrors `packages/framework/engine/Atomic/`. Test support classes in `packages/framework/tests/Support/`. Integration tests in `tests/Integration/`.
- **F3 integration**: `Engine\Atomic\Core\App` wraps F3's `\Base` as a singleton (has its own `instance()` — does NOT use the `Singleton` trait). It proxies unknown method calls to `\Base` via `__call`.
- **Entry point**: `packages/skeleton/public/index.php` — bootstraps app via `bootstrap/app.php`.

## Monorepo layout

```
atomic-framework/                          ← single git repo
├── composer.json                          ← PRIVATE dev-only meta-package
├── phpunit.xml.dist                       ← root PHPUnit (runs all tests)
│
├── packages/
│   ├── framework/                         ← globus-studio/atomic-framework
│   │   ├── composer.json
│   │   ├── engine/Atomic/                 ← framework source
│   │   ├── tests/                         ← framework unit tests
│   │   └── docs/                          ← framework documentation
│   └── skeleton/                          ← globus-studio/atomic-framework-application
│       ├── composer.json
│       ├── app/                           ← app code (controllers, models, middleware)
│       ├── bootstrap/                     ← app bootstrap (const.php, app.php, error.php)
│       ├── config/                        ← config files
│       ├── routes/                        ← route definitions
│       └── public/                        ← web root (index.php entry point)
│
├── tests/Integration/                     ← cross-package integration tests
└── .github/workflows/split.yml            ← auto-split on tag
```

## Two-repo split (publishing)

The monorepo is for development only. Publishing uses subtree split via GitHub Actions:

- `packages/framework/` → `MADEVAL/Atomic-Framework` → Packagist: `globus-studio/atomic-framework`
- `packages/skeleton/` → `MADEVAL/Atomic-Framework-Application` → Packagist: `globus-studio/atomic-framework-application`

The skeleton's `bootstrap/const.php` detects the context automatically:

- **Monorepo** — vendor is two levels up from skeleton (`../../vendor/`)
- **Standalone** (after split) — vendor is at skeleton root (`skeleton/vendor/`)

## Bootstrap chain (skeleton canonical order)

The skeleton `packages/skeleton/bootstrap/app.php` is the authoritative reference. Order matters — hooks fire at specific points:

```
\App\Event\Application::init()
\App\Hook\Application::init()
config_loaded → register_logger → register_exception_handler → prefly
→ register_locales → register_locale_hrefs → register_unload_handler
→ register_middleware → core_ready → register_core_plugins
→ register_plugins → register_routes → init_session
→ open_connections → register_user_provider → app_bootstrapped
```

## Configuration modes

Controlled by `ATOMIC_LOADER` in `bootstrap/const.php`:
- `env` (default) — reads `.env` file via `ConfigLoader`
- `php` — reads `config/*.php` array files via `PhpConfigLoader`

Config values become F3 variables (accessible via `$atomic->get('KEY')`). The `.env.example` in the skeleton is the complete reference of all recognized keys. For tests in this repo, the fixture at `packages/framework/tests/fixtures/.env` contains the test defaults.

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
# Install (from repo root)
composer install

# Run all tests (requires MySQL)
composer test

# Run framework tests only
composer test-fw

# Run integration tests only
composer test-integration

# Run a specific test group within framework
php vendor/bin/phpunit --filter "Auth" --configuration packages/framework/phpunit.xml.dist

# Run a single test file
php vendor/bin/phpunit packages/framework/tests/Engine/Core/CryptoTest.php --configuration packages/framework/phpunit.xml.dist
```

## Testing & coverage requirements

- **Every bug fix MUST include a failing test first** (TDD — red-green-refactor).
- **Every new feature MUST have test coverage** before merging.
- **Framework tests** go in `packages/framework/tests/Engine/`, mirroring the namespace structure of `packages/framework/engine/Atomic/`.
- **Integration tests** go in `tests/Integration/`.
- Test classes extend `PHPUnit\Framework\TestCase`. No custom base class.
- Use `assertSame` over `assertEquals` when possible (strict type checks).
- Test methods use `snake_case` naming: `test_<what>_<expected_behavior>`.
- Platform-specific tests (pcntl, Redis, Memcached) MUST guard with `markTestSkipped()` or `#[RequiresPhpExtension]` in `setUp()` — never let them ERROR on unsupported platforms.
- After any code change, run the full test suite and verify: `PASS` count does not decrease, `FAIL` and `ERROR` counts do not increase.
- Current baseline: **1245 PASS, 0 FAIL, 1 ERROR (workerman), 217 SKIP** on Windows with MySQL.

## Test output formats

| Config | Output | When to use |
|--------|--------|-------------|
| `packages/framework/phpunit.xml.dist` | Dots + summary | Framework tests |
| `phpunit.xml.dist` (root) | Dots + summary | All tests (framework + integration) |
| `--no-extensions` flag | Standard PHPUnit dots | Quick debug without custom printer |

## Test prerequisites

Tests are integration-style and require **MySQL** running on `127.0.0.1:3306` with:
- Database: `atomic_test`
- User: `atomic_test_user` / `atomic_test_pass`
- Tables are auto-created by `packages/framework/tests/bootstrap.php`

Credentials are set in `packages/framework/phpunit.xml.dist` `<php><env>` block and can be overridden via the real `.env` or environment variables. Test fixture `.env` exists at `packages/framework/tests/fixtures/.env`.

No linter, static analysis, or CI workflows are present in this repo.

## Key conventions

- Every file guarded by `if (!defined('ATOMIC_START')) exit;`
- `declare(strict_types=1)` in all PHP files
- Hook system (`Engine\Atomic\Hook\Hook`) is WordPress-compatible (actions + filters)
- Event system (`Engine\Atomic\Event\Event`) is hierarchical with priorities
- Cache: use `Transient` or `CacheManager::instance()->cascade()`, **not** F3's `\Cache::instance()` directly
- PHP ≥ 8.1 required with extensions: json, session, mbstring, fileinfo, pdo, pdo_mysql, curl

## Documentation

Docs in `packages/framework/docs/`. Framework README at `packages/framework/README.md`. `docs/testing_guide.md` covers test patterns. Application skeleton: https://github.com/MADEVAL/Atomic-Framework-Application
