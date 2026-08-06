# Atomic Framework

*Power in minimalism*

[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777BB4?logo=php&logoColor=white)](#)
[![Version](https://img.shields.io/github/v/tag/MADEVAL/Atomic-Framework?label=version&color=blue)](https://github.com/MADEVAL/Atomic-Framework/tags)
[![Tests](https://img.shields.io/badge/tests-1481%2F1481-brightgreen)](#testing)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/globus-studio/atomic-framework?label=packagist&color=orange)](https://packagist.org/packages/globus-studio/atomic-framework)
[![Downloads](https://img.shields.io/packagist/dt/globus-studio/atomic-framework?color=blue)](https://packagist.org/packages/globus-studio/atomic-framework)
[![Stars](https://img.shields.io/github/stars/MADEVAL/Atomic-Framework?style=social)](https://github.com/MADEVAL/Atomic-Framework)

> **Status: stable (`v0.1.4`)** — production-ready, 1481 tests, 0 failures, API is stable.

A modular, full-featured PHP framework built on [Fat-Free Framework](https://fatfreeframework.com/). Ships with authentication, queue processing, task scheduling, caching, CLI tooling, WebSockets, and plugin management — all in a ~2 MB core.

Download the application skeleton for a quick start: [Atomic Application](https://github.com/MADEVAL/Atomic-Framework-Application)

---

## Quick example

```bash
composer create-project globus-studio/atomic-framework-application myapp
cd myapp
cp .env.example .env
php atomic init/key
php -S localhost:8000 -t public
```

---

## Why Atomic

| Atomic | Fat-Free raw | Laravel |
|--------|-------------|---------|
| Full auth stack (bcrypt, OAuth, Telegram, impersonation) | DIY | Built-in (passport/Sanctum) |
| Queue + scheduler built-in | None | Horizon + scheduler |
| Plugin system with dependency checking | None | Packages |
| 45+ CLI commands | Minimal | Artisan (100+) |
| ~2 MB core | ~200 KB | ~30 MB |
| WordPress-compatible hooks | None | Events only |
| Redis-based cache, queue, session, mutex | None | Separate packages |

---

## Install

**Start a new project** (recommended):

```bash
composer create-project globus-studio/atomic-framework-application myapp
cd myapp
cp .env.example .env
php atomic init/key
```

**Add to existing project:**

```bash
composer require globus-studio/atomic-framework
```

---

## Capabilities

| Category | Highlights |
|----------|-----------|
| **Core** | Fluent bootstrap, dual config loaders (`.env` / PHP arrays), preflight environment checks |
| **Auth** | Bcrypt hashing, session binding (IP + User-Agent), dual rate limiting (IP + credential), OAuth 2.0 (Google), Telegram Login Widget, admin impersonation with audit trail |
| **Database** | MySQL via PDO, Redis, Memcached — managed through `ConnectionManager` with health-check pings |
| **Migrations** | Timestamp-based migration system with batch tracking, rollback support, plugin migration auto-discovery |
| **Queue** | Redis driver (Lua-scripted atomic ops) and Database driver (row-level locks) with retry, TTL, and monitoring |
| **Scheduler** | Full POSIX cron expression parser, timezone-aware, timeout protection (300 s) |
| **Cache** | Redis, Memcached, database, and folder drivers with cascade fallback, namespace-wide invalidation, transient storage |
| **Middleware** | Parameterized middleware stack with named aliases and route-pattern matching |
| **Events & Hooks** | Hierarchical event dispatcher with priorities + WordPress-compatible action/filter layer |
| **Mail** | SMTP mailer with multipart/alternative support, DNS deliverability scoring (SPF/DKIM/DMARC) |
| **i18n** | Multi-language with URL prefixing, cookie/session/header detection, automatic `hreflang` generation |
| **Files** | CSV parsing/generation, PDF generation with embedded TrueType fonts, XLS/OLE2 reading |
| **CLI** | 45+ built-in commands: init, migrations, seeding, cache operations, queue management, scheduling |
| **Crypto** | NaCl secretbox (libsodium) authenticated encryption with per-message random nonces |
| **Validation** | Trait-based model validation with 15+ rule types including UUID, regex, password entropy |
| **Telemetry** | Event tracking and monitoring endpoints |
| **WebSockets** | Workerman-based WebSocket server with Redis pub/sub |
| **Plugins** | Plugin lifecycle with dependency checking: WordPress REST, Monopay, RSS Reader, WooCommerce |
| **Theme** | Theme manager with asset enqueueing, head metadata, OpenGraph, path traversal protection |

---

## Application lifecycle

The bootstrap chain in `bootstrap/app.php` initializes the app via a fluent interface. **Order matters** — hooks fire at specific points:

```php
$application = App::instance($atomic);

\App\Event\Application::instance()->init();
\App\Hook\Application::instance()->init();

$application
    ->config_loaded($loader)
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
```

---

## Routing

Routes are organized by type and loaded automatically based on request context:

| Request Type | Route Files |
|-------------|-------------|
| Web | `routes/web.php`, `routes/web.error.php` |
| API | `routes/api.php` |
| CLI | `routes/cli.php` |
| Telemetry | `routes/telemetry.php` |

```php
// routes/web.php
$this->route('GET /dashboard', 'App\Http\Controllers\DashboardController->index');
$this->route('POST /contact', 'App\Http\Controllers\ContactController->submit', ['auth']);
```

---

## Middleware

Named, parameterized middleware attached to route patterns:

```php
// config/middleware.php
return [
    'auth'  => App\Http\Middleware\Authenticate::class,
    'admin' => App\Http\Middleware\RequireAdmin::class,
];

// routes/web.php
$this->route('GET /admin/*', 'AdminController->index', ['auth', 'admin']);
```

```php
use Engine\Atomic\Core\Middleware\MiddlewareInterface;

class Authenticate implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        return Guard::is_authenticated();
    }
}
```

---

## Controllers & Models

```php
use Engine\Atomic\App\Controller;

class DashboardController extends Controller
{
    public function index(\Base $f3): void
    {
        $this->render('dashboard/index.html');
    }
}
```

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

---

## Authentication

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

// Admin impersonation
Auth::instance()->impersonate_user($targetUuid);
Auth::instance()->stop_impersonation();
```

**Security features:** Bcrypt hashing (timing-safe), session regeneration on login, IP + User-Agent binding, dual rate limiting, OAuth state verification with `hash_equals()`, audit logging.

---

## Database & Migrations

```bash
php atomic migrations/create create_posts_table
php atomic migrations/migrate
php atomic migrations/rollback
php atomic migrations/status
```

```php
// database/migrations/20260101000000_create_posts_table.php
return [
    'table' => 'posts',
    'columns' => [
        'id'    => ['type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
        'title' => ['type' => 'VARCHAR', 'length' => 256, 'nullable' => false],
        'body'  => ['type' => 'TEXT', 'nullable' => true],
        'uuid'  => ['type' => 'VARCHAR', 'length' => 128, 'nullable' => false],
    ],
];
```

---

## Queue system

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
php atomic queue/retry          # Retry failed jobs
```

---

## Task scheduler

```php
// routes/schedule.php
$scheduler->call(function () {
    Log::info('Cleanup executed');
})->daily()->at('03:00')->timezone('UTC');
```

```bash
php atomic schedule/run          # Execute due tasks
php atomic schedule/work         # Continuous scheduler loop
php atomic schedule/list         # List scheduled tasks
```

---

## Plugins

Plugins are explicit providers — only classes listed in `config/providers.php` are loaded:

```php
// config/providers.php
return [
    'plugins' => [
        Engine\Atomic\Plugins\WebSockets\WebSockets::class,
        App\Plugins\ExamplePlugin\ExamplePlugin::class,
    ],
];
```

```
plugins/
`-- ExamplePlugin/
    |-- ExamplePlugin.php
    |-- composer.json
    |-- vendor/autoload.php
    `-- routes/
        `-- api.php
```

```bash
php atomic plugin/make ExamplePlugin
php atomic plugin/deps install
```

---

## Events & hooks

**Events** — hierarchical with priorities:

```php
use Engine\Atomic\Event\Event;

Event::instance()->on('user.created', function ($data) {
    // Send welcome email
}, priority: 10);

Event::instance()->emit('user.created', ['user' => $user]);
```

**Hooks** — WordPress-compatible actions and filters:

```php
use Engine\Atomic\Hook\Hook;

Hook::instance()->add_action('after_login', function ($user) {
    // Track login
});

$title = Hook::instance()->apply_filters('page_title', $rawTitle);
```

---

## Caching

Multi-driver caching with cascade fallback. Redis, Memcached, folder, and database adapters share the same interface:

```php
use Engine\Atomic\Tools\Transient;
use Engine\Atomic\Core\CacheManager;

// Transient storage (WordPress-like priority: Redis → Memcached → DB → Folder)
Transient::set('stats', $data, 3600);
$cached = Transient::get('stats');

// Cache cascade
$cache = CacheManager::instance()->cascade();
$cache->set('stats', $data, 3600);
$cache->clear('stats');

// Refresh cached generation in long-running workers
$cache->flush_local_cache();
```

---

## Internationalization

```php
use Engine\Atomic\Lang\I18n;

$i18n = I18n::instance();
echo $i18n->t('welcome_message');                // Simple translation
echo $i18n->tn('item', 'items', $count);         // Pluralization
echo $i18n->tx('menu', 'navigation');            // Contextual translation
echo $i18n->url('/about', 'fr');                 // Localized URL: /fr/about
```

Detection priority: URL prefix → GET parameter → Cookie → Session → `Accept-Language` header → default.

---

## CLI

```bash
php atomic init                  # Scaffold project structure
php atomic init/key              # Generate application keys
php atomic version               # Display framework version
php atomic routes                # List all registered routes

php atomic cache/invalidate      # Fast generation invalidation
php atomic cache/clear           # Physical cache deletion
php atomic cache/prune           # Remove expired cache entries
php atomic db/tables             # List database tables
php atomic seed/users            # Seed user data
php atomic file/csv2pdf          # Convert CSV to PDF
```

---

## Security

| Layer | Implementation |
|-------|---------------|
| **Encryption** | NaCl secretbox (libsodium) — authenticated encryption with random nonces |
| **Passwords** | Bcrypt with automatic salt, constant-time verification |
| **Sessions** | IP + User-Agent binding, regeneration on login, configurable lifetime |
| **Rate Limiting** | Dual counters (IP-based + credential-based) with configurable TTL windows |
| **OAuth** | CSRF state tokens verified with `hash_equals()`, replay protection |
| **CSRF** | Nonce tokens bound to IP and User-Agent |
| **Input** | `htmlspecialchars` escaping, parameterized database queries via PDO |
| **Logging** | Sensitive data (passwords, tokens, keys) automatically masked |
| **Authorization** | Role-based access via `Guard` with backed enum support |
| **Impersonation** | Admin-only with session regeneration and full audit trail |

---

## Dependencies

| Capability | Package | Required |
|-----------|---------|----------|
| Framework core | `globus-studio/fatfree-core` | Yes |
| ORM | `globus-studio/cortex-atomic` | Yes |
| WebSockets | `workerman/workerman`, `workerman/redis` | Optional |

**PHP extensions:** `json`, `session`, `mbstring`, `fileinfo`, `pdo`, `pdo_mysql`, `curl`

---

## Testing

```bash
composer test                    # Run all tests (1481 tests, 0 failures)
composer test-fw                 # Framework tests only
composer test-integration        # Integration tests only

php vendor/bin/phpunit --filter "Auth"    # Run specific test group
```

- **1481 tests** across **70+ test classes**
- **100% pass rate** — 0 failures, 218 skipped (platform-specific: Redis, Memcached, pcntl on Windows)
- Covers: cryptography, authentication, authorization, validation, middleware, CSRF, events, hooks, routing, caching, scheduling, session management, theme management

---

## Documentation

| You want to... | Read... |
|---------------|---------|
| Understand bootstrap lifecycle | [atomic_core.md](docs/atomic_core.md) |
| Configure the application | [config.md](docs/config.md) |
| Set up database | [database.md](docs/database.md) |
| Work with models | [model.md](docs/model.md) |
| Run migrations | [migrations.md](docs/migrations.md) |
| Set up caching | [cache.md](docs/cache.md) |
| Use the queue system | [queue.md](docs/queue.md) |
| Schedule tasks | [scheduler.md](docs/scheduler.md) |
| Write middleware | [middleware.md](docs/middleware.md) |
| Handle events and hooks | [event.md](docs/event.md) · [hook.md](docs/hook.md) |
| Send emails | [mailer.md](docs/mailer.md) |
| Set up i18n | [i18n.md](docs/i18n.md) |
| Use CLI commands | [cli.md](docs/cli.md) |
| Manage sessions | [session.md](docs/session.md) |
| Write plugins | [plugins.md](docs/plugins.md) |
| Use theme system | [theme.md](docs/theme.md) · [assets.md](docs/assets.md) |
| Set up logging | [log.md](docs/log.md) |
| Use rate limiting | [rate_limit.md](docs/rate_limit.md) |
| Secure the app | [security.md](docs/security.md) |
| Write tests | [testing_guide.md](docs/testing_guide.md) |

---

## Links

| Resource | URL |
|----------|-----|
| Packagist | [globus-studio/atomic-framework](https://packagist.org/packages/globus-studio/atomic-framework) |
| Application skeleton | [MADEVAL/Atomic-Framework-Application](https://github.com/MADEVAL/Atomic-Framework-Application) |
| Website | [atomic.globus.studio](https://atomic.globus.studio/) |

---

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
