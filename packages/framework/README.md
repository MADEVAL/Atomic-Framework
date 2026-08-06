# Atomic Framework

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF.svg)](https://www.php.net/releases/8.1/en.php)
[![License: GPL-3.0](https://img.shields.io/badge/license-GPL--3.0--or--later-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.1.0-orange.svg)]()

*Power in minimalism*

---

A modular, full-featured PHP framework built on top of [Fat-Free Framework](https://fatfreeframework.com/). Atomic provides a structured application skeleton with authentication, queue processing, scheduling, caching, CLI tooling, and more - while staying lightweight and unopinionated.

## Application skeleton
Download the application skeleton for a quick start:
[Atomic Application](https://github.com/MADEVAL/Atomic-Framework-Application)

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Project Structure](#project-structure)
- [Configuration](#configuration)
- [Core Concepts](#core-concepts)
  - [Application Lifecycle](#application-lifecycle)
  - [Routing](#routing)
  - [Middleware](#middleware)
  - [Controllers & Models](#controllers--models)
  - [Authentication](#authentication)
  - [Database & Migrations](#database--migrations)
  - [Queue System](#queue-system)
  - [Task Scheduler](#task-scheduler)
  - [Plugins](#plugins)
  - [Event & Hook System](#event--hook-system)
  - [Caching](#caching)
  - [Internationalization](#internationalization)
  - [CLI](#cli)
- [Security](#security)
- [Testing](#testing)
- [Documentation](#documentation)
- [License](#license)

## Features

| Category | Highlights |
|----------|-----------|
| **Core** | Fluent bootstrap, dual config loaders (`.env` / PHP arrays), preflight environment checks |
| **Auth** | Bcrypt password hashing, session binding (IP + User-Agent), dual rate limiting (IP + credential), OAuth 2.0 (Google), Telegram Login Widget, admin impersonation with audit trail |
| **Database** | MySQL via PDO, Redis, Memcached - managed through `ConnectionManager` with health-check pings |
| **Migrations** | Timestamp-based migration system with batch tracking, rollback support, and plugin migration auto-discovery |
| **Queue** | Redis driver (Lua-scripted atomic ops) and Database driver (row-level locks) with retry, TTL, and monitoring |
| **Scheduler** | Full POSIX cron expression parser, timezone-aware, timeout protection (300 s) |
| **Cache** | Stable Redis, Memcached, database, and folder (filesystem) drivers with cascade fallback, namespace-wide invalidation, and transient storage |
| **Middleware** | Parameterized middleware stack with named aliases and route-pattern matching |
| **Events & Hooks** | Hierarchical event dispatcher with priorities + WordPress-compatible action/filter layer |
| **Mail** | SMTP mailer with multipart/alternative support, DNS deliverability scoring (SPF/DKIM/DMARC) |
| **i18n** | Multi-language support with URL prefixing, cookie/session/header detection, automatic `hreflang` generation |
| **Files** | CSV parsing/generation, PDF generation with embedded TrueType fonts, XLS/OLE2 reading |
| **CLI** | 45+ built-in commands: init, migrations, seeding, cache operations, queue management, scheduling |
| **Crypto** | NaCl secretbox (libsodium) authenticated encryption with per-message random nonces |
| **Validation** | Trait-based model validation with 15+ rule types including UUID, regex, and password entropy |
| **Telemetry** | Event tracking and monitoring endpoints |
| **WebSockets** | Workerman-based WebSocket server with Redis pub/sub |
| **Plugins** | Plugin lifecycle management with dependency checking: WordPress REST, Monopay, RSS Reader, WooCommerce |
| **Theme** | Theme manager with asset enqueueing, head metadata, OpenGraph, and path traversal protection |

## Requirements

- **PHP ≥ 8.1** with extensions: `json`, `session`, `mbstring`, `fileinfo`, `pdo`, `pdo_mysql`, `curl`
- **Composer**
- **MySQL / MariaDB** (primary database)
- **Redis** (recommended for cache, queue, sessions, WebSockets)
- **Memcached** (optional, alternative cache backend)
- **libsodium** (bundled with PHP ≥ 7.2 for encryption)

## Installation

Quick start and install the framework via Composer:

```bash
composer require globus-studio/atomic-framework
```

Download the application skeleton for a quick start:
[Atomic Application](https://github.com/MADEVAL/Atomic-Framework-Application)

Create your project entry point:

```php
// public/index.php
<?php
define('ATOMIC_START', true);
require_once __DIR__ . '/../bootstrap/app.php';
$application->run();
```

Generate application keys and scaffold your project:

```bash
php atomic init
php atomic init/key
```

## Project Structure

```
├── app/                    # Application code (controllers, models, middleware, hooks, events)
│   ├── Auth/               # User provider implementation
│   ├── Event/              # Application event listeners
│   ├── Hook/               # Application hook handlers
│   └── Http/               # Controllers, middleware, models
├── bootstrap/              # Framework bootstrap (constants, error config, app init)
├── config/                 # Configuration files (app, auth, cache, database, mail, etc.)
├── database/
│   ├── migrations/         # Database migration files
│   └── seeds/              # Database seed files
├── engine/Atomic/          # Framework core
│   ├── API/                # REST API utilities
│   ├── App/                # Base controller, model, plugin, storage
│   ├── Auth/               # Authentication services, adapters, interfaces
│   ├── Cache/              # Cache contract and drivers (Redis, Memcached, DB, Folder)
│   ├── CLI/                # CLI commands and traits
│   ├── Core/               # App kernel, config, crypto, routing, middleware, migrations
│   ├── Enums/              # Backed enums (Currency, Language, Role, Rule)
│   ├── Event/              # Event dispatcher
│   ├── Files/              # CSV, PDF, XLS processors
│   ├── Hook/               # Hook/filter system
│   ├── Lang/               # i18n engine and locale files
│   ├── Mail/               # SMTP mailer and notifier
│   ├── Mutex/              # Distributed locking (Redis, DB, Memcached, file)
│   ├── Plugins/            # Built-in plugin integrations
│   ├── Queue/              # Queue managers, drivers, interfaces
│   ├── Scheduler/          # Cron-based task scheduler
│   ├── Session/            # Session handlers (Redis)
│   ├── Support/            # Helper functions
│   ├── Telemetry/          # Event tracking
│   ├── Theme/              # Theme management and asset pipeline
│   ├── Tools/              # Nonce, Transient
│   └── Validator/          # Validation traits
├── plugins/                # User plugins
├── public/                 # Web root (themes and uploads)
├── resources/views/        # View templates
├── routes/                 # Route definitions (web, API, CLI, schedule)
├── storage/                # Logs, cache, sessions, compiled views
└── tests/                  # PHPUnit test suite
```

## Configuration

Atomic supports two configuration modes controlled by `ATOMIC_LOADER` in `bootstrap/const.php`:

### `.env` Mode (default)

```dotenv
APP_KEY=your-secret-key
APP_UUID=your-app-uuid
DOMAIN=https://example.com

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_DATABASE=atomic
DB_USERNAME=root
DB_PASSWORD=secret

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_DRIVER=redis
```

### PHP Array Mode

Configuration files in `config/` return associative arrays:

```php
// config/database.php
return [
    'default'     => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'database' => 'atomic',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
        ],
    ],
];
```

`bootstrap/error.php` configures native PHP error logging to `storage/logs/php_errors-YYYY-MM-DD.log` when the log directory is writable.

## Core Concepts

### Application Lifecycle

The bootstrap chain in `bootstrap/app.php` initializes the application via a fluent interface:

```php
$application = App::instance($atomic);

\App\Event\Application::instance()->init();
\App\Hook\Application::instance()->init();

$application
    ->config_loaded($loader)
    ->register_logger()            // Initialize structured logging
    ->register_exception_handler()
    ->prefly()                     // Verify PHP version, extensions, directory permissions
    ->register_locales()           // Set up i18n
    ->register_locale_hrefs()      // Normalize prefixed locale path before route detection
    ->register_unload_handler()
    ->register_middleware()        // Load middleware aliases
    ->register_core_plugins()      // Register framework plugin providers
    ->register_plugins()           // Activate registered plugins
    ->register_routes()            // Load app and plugin route files by request type
    ->init_session()               // Start session (lazy: only if cookie exists)
    ->open_connections()           // Opens redis, memcached and db connections
    ->register_user_provider()     // Wire authentication backend
    ->app_bootstrapped();
```

Core framework plugins are loaded before user/application plugins. User and core plugins are both resolved from `config/providers.php`; plugin directories are not scanned automatically.

### Routing

Routes are organized by type and loaded automatically based on request context:

| Request Type | Route Files |
|-------------|-------------|
| Web | `routes/web.php`, `routes/web.error.php` |
| API | `routes/api.php` |
| CLI | `routes/cli.php` |
| Telemetry | `routes/telemetry.php` |

```php
// routes/web.php
$f3->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index');
$f3->route('POST /contact', 'App\Http\Controllers\ContactController->submit');
```

### Middleware

Named, parameterized middleware attached to route patterns:

```php
// config/middleware.php
return [
    'auth'  => App\Http\Middleware\Authenticate::class,
    'admin' => App\Http\Middleware\RequireAdmin::class,
];
```

```php
// Usage with parameters
$middleware->for_route('/admin/*', ['auth', 'admin']);
$middleware->for_route('/store/*', ['store:banned']);  // Parameterized: Store('banned')
```

Middleware implements `MiddlewareInterface`:

```php
use Engine\Atomic\Core\Middleware\MiddlewareInterface;

class Authenticate implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        // Return true to continue, false to abort
        return Guard::is_authenticated();
    }
}
```

### Plugins

Plugins are explicit providers. Atomic only loads plugin classes listed in `config/providers.php`:

```php
return [
    'plugins' => [
        Engine\Atomic\Plugins\WebSockets\WebSockets::class,
        App\Plugins\ExamplePlugin\ExamplePlugin::class,
    ],
];
```

The framework does not include every file in a plugin directory and does not load unregistered plugin folders. It resolves each provider class, loads the plugin's main class file when needed, then registers, boots, and loads routes only for enabled registered plugins.

User plugins live under `USER_PLUGINS` and should follow the generated layout:

```text
plugins/
`-- ExamplePlugin/
    |-- ExamplePlugin.php
    |-- composer.json
    |-- vendor/autoload.php
    `-- routes/
        `-- api.php
```

Create a plugin scaffold with:

```bash
php atomic plugin/make ExamplePlugin
```

If a plugin uses Composer dependencies, install them for the plugin before booting the application. Atomic provides a CLI command for all provider-registered plugins, or one named plugin:

```bash
php atomic plugin/deps install
php atomic plugin/deps install ExamplePlugin
```

The CLI route for this command is registered in `engine/Atomic/Core/Routes/cli.php` as `/plugin/deps`.

Each plugin that depends on Composer packages should declare runtime checks with `required_dependencies()`. This gives a clear error when `vendor/autoload.php` or required package symbols are missing:

```php
use Engine\Atomic\App\Plugin;

final class ExamplePlugin extends Plugin
{
    protected function get_name(): string
    {
        return 'ExamplePlugin';
    }

    public function required_dependencies(): array
    {
        return [
            [
                'package' => 'vendor/package',
                'classes' => [
                    Vendor\Package\Client::class,
                ],
            ],
        ];
    }
}
```

Composer autoloading remains the plugin's responsibility: keep plugin dependencies in the plugin's `composer.json`, run the dependency install command, and ensure the plugin has a readable `vendor/autoload.php`. Atomic will load that autoloader during provider loading when it exists.

### Controllers & Models

```php
use Engine\Atomic\App\Controller;

class DashboardController extends Controller
{
    public function index(\Base $f3): void
    {
        // Middleware is enforced automatically
        $this->render('dashboard/index.html');
    }
}
```

Models extend the base `Model` with built-in validation:

```php
use Engine\Atomic\App\Model;

class User extends Model
{
    protected function get_rules(): array
    {
        return [
            'email'    => ['rule' => Rule::EMAIL, 'required' => true],
            'uuid'     => ['rule' => Rule::UUID_V4, 'required' => true],
            'password' => ['rule' => Rule::PASSWORD_ENTROPY, 'min_entropy' => 40],
        ];
    }
}
```

### Authentication

Atomic provides a full authentication stack with adapter-based dependency injection:

```php
use Engine\Atomic\Auth\Auth;

// Password-based login
$user = Auth::instance()->login_with_secret(
    ['email' => $email],
    $password
);

// OAuth login
$url = Auth::instance()->google()->get_login_url();
$userId = Auth::instance()->google()->handle_callback($code, $state);

// Session management
$currentUser = Auth::instance()->get_current_user();
Auth::instance()->logout();
Auth::instance()->kill_all_sessions($userId);

// Auth throttling: use RateLimit middleware or app-specific policies.

// Admin impersonation
Auth::instance()->impersonate_user($targetUuid);
Auth::instance()->stop_impersonation();
```

**Security features:**
- **Bcrypt** password hashing (timing-safe verification)
- Session regeneration on login (session fixation protection)
- IP and User-Agent binding with suspicious activity detection
- RateLimit middleware for IP, user, and route throttling
- OAuth state parameter with `hash_equals()` verification
- Comprehensive audit logging (no credentials in logs)

### Database & Migrations

```bash
# Create a migration
php atomic migrations/create create_posts_table

# Run pending migrations
php atomic migrations/migrate

# Rollback last batch
php atomic migrations/rollback

# Check status
php atomic migrations/status
```

### Queue System

Dual-driver queue with Redis (Lua-scripted atomics) and Database (row-level locks):

```php
use Engine\Atomic\Queue\Managers\Manager;

Manager::instance()->push('email', [
    'to'      => 'user@example.com',
    'subject' => 'Welcome',
]);
```

```bash
php atomic queue/worker         # Start worker
php atomic queue/monitor        # View queue status
php atomic queue/retry           # Retry failed jobs
```

### Task Scheduler

```php
// routes/schedule.php
$scheduler->call(function () {
    // Cleanup expired sessions
})->daily()->at('03:00')->timezone('UTC');
```

```bash
php atomic schedule/run          # Execute due tasks
php atomic schedule/work         # Continuous scheduler loop
php atomic schedule/list         # List scheduled tasks
```

### Event & Hook System

**Events** (modern, hierarchical with priorities):

```php
use Engine\Atomic\Event\Event;

Event::instance()->on('user.created', function ($data) {
    // Send welcome email
}, priority: 10);

Event::instance()->emit('user.created', ['user' => $user]);
```

**Hooks** (WordPress-compatible actions and filters):

```php
use Engine\Atomic\Hook\Hook;

Hook::instance()->add_action('after_login', function ($user) {
    // Track login
});

$title = Hook::instance()->apply_filters('page_title', $rawTitle);
```

### Caching

Multi-driver caching with cascade fallback. Redis, Memcached, folder, and database adapters share the same public behavior: exact-key `set`, `get`, `exists`, `clear`, namespace-wide `reset`, and per-instance generation refresh. Prefix deletion is intentionally not part of the shared cache contract because Memcached cannot provide it with the same guarantees.

Atomic also installs a wrapper for Fat-Free Framework's own cache singleton. That keeps F3-native features such as route TTL caching, SQL/schema TTL caching, minify cache, and F3 lexicons working with the selected Atomic backend. The wrapper supports basic F3 cache operations and full reset; suffix-specific reset is not part of Atomic's cache contract. Application code should use `Transient` or `CacheManager` instead of calling `\Cache::instance()` directly. The two layers share the backend choice, not the key format.

```php
use Engine\Atomic\Tools\Transient;
use Engine\Atomic\Core\CacheManager;

// Store a value with TTL
Transient::set('stats', $data, 3600);
$cached = Transient::get('stats');

// Cache cascade: honors CACHE_CONFIG and falls back through Redis → Memcached → Folder
$cache = CacheManager::instance()->cascade();
$cache->set('stats', $data, 3600);
$cache->clear('stats');

// Long-running workers can refresh the cached generation after an external reset.
$cache->flush_local_cache();

// Transients use WordPress-like priority:
// Redis → Memcached → DB → Folder
```

### Internationalization

```php
use Engine\Atomic\Lang\I18n;

$i18n = I18n::instance();
echo $i18n->t('welcome_message');                // Simple translation
echo $i18n->tn('item', 'items', $count);         // Pluralization
echo $i18n->tx('menu', 'navigation');             // Contextual translation
echo $i18n->url('/about', 'fr');                  // Localized URL: /fr/about
```

Language detection priority: URL prefix → GET parameter → Cookie → Session → `Accept-Language` header → default.

### CLI

```bash
php atomic init                  # Scaffold project structure
php atomic init/key              # Generate application keys
php atomic version               # Display framework version
php atomic routes                # List all registered routes

php atomic cache/invalidate      # Fast generation invalidation; old entries become unreachable
php atomic cache/clear           # Physical cache deletion where supported
php atomic cache/prune           # Remove expired/corrupt cache entries only
php atomic db/tables             # List database tables
php atomic seed/users            # Seed user data

php atomic file/csv2pdf          # Convert CSV to PDF
```

## Security

| Layer | Implementation |
|-------|---------------|
| **Encryption** | NaCl secretbox (libsodium) - authenticated encryption with random nonces |
| **Passwords** | Bcrypt with automatic salt, constant-time verification |
| **Sessions** | IP + User-Agent binding, regeneration on login, configurable lifetime |
| **Rate Limiting** | Dual counters (IP-based + credential-based) with configurable TTL windows |
| **OAuth** | CSRF state tokens verified with `hash_equals()`, replay protection |
| **CSRF** | Nonce tokens bound to IP and User-Agent |
| **Input** | `htmlspecialchars` escaping, parameterized database queries via PDO |
| **Logging** | Sensitive data (passwords, tokens, keys) automatically masked by `Sanitizer` |
| **Authorization** | Role-based access via `Guard` with backed enum support |
| **Impersonation** | Admin-only with session regeneration and full audit trail |

## Testing

Atomic ships with a comprehensive PHPUnit test suite:

```bash
# Run the full test suite (compact output)
composer test
# or:
php vendor/bin/phpunit --configuration tests/phpunit.xml

# Standard dot-progress output with percentage:
php vendor/bin/phpunit --configuration tests/phpunit.dots.xml

# Run a specific test group
php vendor/bin/phpunit --filter "Auth" --configuration tests/phpunit.xml
```

- **1478 tests** across **70+ test classes**
- **97.6% pass rate** (1245 passed, 9 pre-existing failures, 203 skipped on Windows)
- All 9 failures and 21 errors are pre-existing (pcntl/SIGTERM unavailable on Windows, fixture mismatches)
- Covers: cryptography, authentication, authorization, validation, middleware, CSRF, events, hooks, routing, sanitization, scheduling, caching, theme management

### Test output formats

| Config | Output style | Use case |
|--------|-------------|----------|
| `tests/phpunit.xml` | Verbose `[PASS]/[FAIL]` per test | Development, debugging |
| `tests/phpunit.dots.xml` | Standard dots + percentage | CI, quick overview |

To see dots without a separate config:
```bash
php vendor/bin/phpunit --configuration tests/phpunit.xml --no-extensions
```

## Documentation

Full documentation is available in the [`docs/`](docs/) directory:

| Topic | File |
|-------|------|
| Core Bootstrap | [atomic_core.md](docs/atomic_core.md) |
| Configuration | [config.md](docs/config.md) |
| Database | [database.md](docs/database.md) |
| Cache and Storage | [cache.md](docs/cache.md) |
| Migrations | [migrations.md](docs/migrations.md) |
| Models | [model.md](docs/model.md) |
| Middleware | [middleware.md](docs/middleware.md) |
| Request | [request.md](docs/request.md) |
| Queue | [queue.md](docs/queue.md) |
| Scheduler | [scheduler.md](docs/scheduler.md) |
| Events | [event.md](docs/event.md) |
| Hooks | [hook.md](docs/hook.md) |
| Mailer | [mailer.md](docs/mailer.md) |
| i18n | [i18n.md](docs/i18n.md) |
| Sessions | [session.md](docs/session.md) |
| CLI | [cli.md](docs/cli.md) |
| Logging | [log.md](docs/log.md) |
| Assets & Themes | [assets.md](docs/assets.md) |
| Mutex | [mutex.md](docs/mutex.md) |
| Telegram | [telegram.md](docs/telegram.md) |
| AI Connector | [ai_connector.md](docs/ai_connector.md) |

## License

Atomic Framework is open-source software licensed under the [GPL-3.0-or-later](LICENSE).
