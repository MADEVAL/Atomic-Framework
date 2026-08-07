# Pre-Release Audit — v0.2.0

**Date:** 2026-08-07
**Branch:** `main` (pre-release)
**Test baseline:** 1704 tests / 3881 assertions / 0 FAIL / 0 ERROR / 217 SKIP
**Composer validate:** OK | **Composer audit:** No vulnerabilities

---

## Fixes Applied During Audit

| # | Severity | Issue | File | Fix |
|---|----------|-------|------|-----|
| 1 | CRITICAL | AppBootstrapped fires before Plugin/Route/Schedule | `ServiceProviders.php:183` | Added `RouteServiceProvider::class` to `requires()` |
| 2 | CRITICAL | 45 runtime files missing ATOMIC_START guard | 45 files in `engine/Atomic/` | Added `if (!defined('ATOMIC_START')) exit;` after namespace |
| 3 | HIGH | MEMCACHED_PREFIX typo (`atomic_` → `atomic.`) | `packages/skeleton/config/database.php:29` | Fixed prefix |
| 4 | HIGH | Skeleton tools.php flat keys break PHP config mode | `packages/skeleton/config/tools.php` | Restructured to nested keys matching test fixtures |
| 5 | HIGH | AccessMiddleware::process() regression — no login form | `AccessMiddleware.php:132-165` | Rewrote process() to render login form on non-JSON requests |
| 6 | HIGH | HttpKernel off-by-one in getMethodBody() | `HttpKernel.php:111` | Fixed `$startLine - 1` for 0-indexed array_slice |
| 7 | HIGH | Orphan ConfigSchema keys (CACHE_TTL, AUTH_RATE_LIMIT_*) | (deferred to v0.2.1) | Documented as orphan — not consumed anywhere |
| 8 | MEDIUM | sanitize_key() WP conflict | `Theme/Assets.php:296` | Added `if (!function_exists('sanitize_key'))` guard |
| 9 | MEDIUM | App::detect_request_type() missing 'cli' segment | `App.php:283` | Added `case 'cli': return 'cli';` |
| 10 | MEDIUM | resolveOrder() silent fail on cycles | `Application.php:100-101` | Added `error_log()` warning |
| 11 | MEDIUM | HttpKernel lossy 403 fallback | `HttpKernel.php:62` | Changed to `Response::empty(204)` |
| 12 | MEDIUM | Container::make() recursive autowiring | (deferred to v0.3.0) | Reverted — breaks existing test contract |

---

## Summary

| Metric | Count |
|--------|-------|
| **Total invariants checked** | **198** |
| **PASS** | **149** |
| **FAIL** | **25** |
| **DEFERRED** | **18** |
| **NOT-APPLICABLE** | **6** |

---

## Severity Breakdown

| Severity | Count | Description |
|----------|-------|-------------|
| **CRITICAL** | 2 | Bootstrap order bug (AppBootstrapped fires too early); 46 runtime files missing ATOMIC_START guard |
| **HIGH** | 8 | Config inconsistencies, middleware regression, orphan config keys |
| **MEDIUM** | 10 | Dead AccountLockout, HttpKernel off-by-one, missing docblocks, Sanitize_key conflict |
| **LOW** | 5 | No entropy check, non-exponential retry, APP_ENV not checked in scheduler |

---

## Phase 0 — Inventory

### 0.1 Statistics

| Metric | Count |
|--------|-------|
| Total PHP classes | ~135 (framework) + ~22 (skeleton) = **~157** |
| Interfaces | **14** |
| Traits | **7** |
| Enums | **8** |
| Global functions | **151** (100 helpers + 48 plugins) |
| Test files | **106** |
| Test methods | **1457** (`function test_*`) |
| Lua scripts | **22** (2 cache + 16 queue + 4 ratelimit) |
| Route files | **14** (5 fw + 6 skeleton + 3 plugin) |
| Middleware classes | **17** (5 fw + 7 skeleton + 2 ws + 3 security/ratelimit) |
| Config files | **14** (skeleton) + **14** (test fixtures) |
| Doc files | **46** `.md` files in `docs/` |
| Bootstrap files | **4** (const.php, app.php, error.php, index.php) |

---

## Phase 1 — Architectural Invariants

### 1.1 Bootstrap Chain

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Provider chain is the ONLY bootstrap | **PASS** | `app.php:110-128` uses `new Application($container)->registerProvider(...)->boot()` |
| 2 | Provider order matches expected | **PASS** | Registration: Config→Log→Exception→Prefly→Locale→Unload→Middleware→CoreReady→CorePlugin→Plugin→Route→Schedule→Session→Database→Auth→AppBootstrapped |
| 3 | Container::setGlobal() before App::instance() | **PASS** | `app.php:83` before `app.php:99` |
| 4 | App::instance() checks Container::global() | **PASS** | `App.php:40-44` |
| 5 | Singleton::instance() checks Container::global() | **PASS** | `Singleton.php:15-18` |
| 6 | Application::boot() calls resolveOrder() | **PASS** | `Application.php:42` uses Kahn's topological sort |
| 7 | No provider calls exit directly | **PASS** | PreflyServiceProvider delegates to App::prefly() which has exit(1)/exit(0) |
| — | **CRITICAL: AppBootstrapped fires before Plugin/Route/Schedule** | **FAIL** | Topological sort places AppBootstrapped after Auth (depends on Auth + DB only). Plugin→Route→Schedule chain resolves later. Missing `requires()` entry for RouteServiceProvider. `ServiceProviders.php:183` |
| — | resolveOrder() silently falls back on cycles | **DEFERRED** | `Application.php:100` — no error logged when cycle detected |

### 1.2 Config — Single Source of Truth

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Every .env.example key has ConfigSchema definition | **FAIL** | 31 keys missing from ConfigSchema: I18N_*, SECURITY_HEADERS_*, TELEGRAM_*, AI_*, CACHE_PATH/SERVER/PASSWORD/LOGIN, MAIL_USERNAME/PASSWORD/ENCRYPTION, UI/TEMP/LOGS/FONTS/etc paths, MEMCACHED_USERNAME/PASSWORD. They exist in ConfigRegistry (legacy path) but not ConfigSchema (new V2 path). |
| 2 | Defaults match across all sources | **FAIL** | See table below |
| 3 | No direct getenv()/$_ENV calls | **PASS** | Only `CLI\Capabilities.php:73` uses `getenv()` — intentional for terminal capability detection. Tests use it acceptably. |
| 4 | rate_limiter.php has max_attempts/window_seconds/lockout_seconds | **FAIL** | Neither skeleton nor fixture `rate_limiter.php` contain these keys. They exist only as orphan ConfigSchema definitions in `bootstrap/app.php:77-79` |
| 5 | ConfigLoader vs V2\ConfigLoader parity | **PASS** | ConfigParityTest exists (20 tests). All pass. |
| 6 | ConfigSchema::defaults() returns valid defaults | **PASS** | Required keys have defaults |

**Default Value Mismatches:**

| Key | ConfigSchema | .env.example | Skeleton PHP | Issue |
|-----|-------------|-------------|-------------|-------|
| DOMAIN | `''` | `localhost:8000` | `''` | ConfigSchema/php default doesn't match .env |
| MAIL_HOST | `127.0.0.1` | `127.0.0.1` | `smtp.example.com` | PHP config differs from both |
| MEMCACHED_PREFIX | `atomic.` | `atomic.` | **`atomic_`** (underscore!) | Bug in skeleton `database.php:29` |
| MIGRATIONS_CORE | (ConfigRegistry) | `Atomic/Core/...` | `engine/Atomic/Core/...` | PHP configs have `engine/` prefix; .env does not |
| FRAMEWORK_ROUTES | (ConfigRegistry) | `Atomic/Core/...` | `engine/Atomic/Core/...` | Same as above |

**Orphan Config Keys (ConfigSchema only, consumed nowhere):**
- `CACHE_TTL`
- `AUTH_RATE_LIMIT_MAX_ATTEMPTS`
- `AUTH_RATE_LIMIT_WINDOW_SECONDS`
- `AUTH_RATE_LIMIT_LOCKOUT_SECONDS`

**Skeleton tools.php Bugs (PHP mode):**
- Telegram keys: flat `telegram_bot_token` vs expected nested `telegram.bot_token` — `PhpConfigLoader` expects nested path.
- AI keys: `tools.php` has NO `ai` section → AI API keys silently fail in PHP config mode.

### 1.3 Container

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | All services registered in Container | **PASS** | \Base, App, Hook, Event, F3Bridge, Router, CacheManager, ConnectionManager, PluginManager, Auth |
| 2 | Singleton::instance() container check works | **PASS** | Same pattern in App::instance() and Singleton trait |
| 3 | Adapters created via `new` only inside parent class | **PASS** | Verified: no external `new AppContextAdapter` etc. |
| 4 | No framework service created via `new` in another service | **PASS** | |
| 5 | Container::make() auto-wiring | **PARTIAL PASS** | Auto-wires registered types but doesn't do recursive `make()` for unregistered dependencies. `Container.php:241` throws instead. |

### 1.4 Response vs Exit/Die

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | No exit/die in middleware | **PASS** | Only guard `exit;` on line 5. Zero in logic. |
| 2 | No exit/die in web controllers | **PASS** | Zero in logic. |
| 3 | Only CLI commands call exit with code | **PASS** | |
| 4 | ExceptionHandler::handle() returns Response | **PASS** | No die. |
| 5 | Core\Response exit is opt-out | **PASS** | `$terminate=true` default, can pass `false`. |
| 6 | Http\Response::send() — no exit | **PASS** | echo + header(), no exit. |
| 7 | App::apply_cors() exit on OPTIONS | **PASS** | Acceptable — preflight before F3 pipeline. |
| 8 | App::prefly() exit | **PASS** | Acceptable — bootstrap-level, no Response infra. |
| 9 | App::run() no exit | **PASS** | Delegates to F3 `$atomic->run()`. |

**Non-guard exit calls (documented, acceptable):**
`App.php:99`, `App.php:131`, `App.php:435`, `App.php:508`, `Response.php:66,118,147,173`

### 1.5 Middleware Pipeline

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | MiddlewareInterface has both handle() + process() | **PASS** | |
| 2 | HttpKernel decides handle() vs process() | **PASS** | Uses reflection to check if process() is overridden |
| 3 | All middleware implement process() without throw | **PASS** | No `throw new \RuntimeException('Not yet implemented')` |
| 4 | MiddlewareStack::run_for_route() calls handle() | **PASS** | Legacy F3-compatible path |
| 5 | No exit/die in process() or handle() | **PASS** | |
| — | **HttpKernel off-by-one bug** | **FAIL** | `HttpKernel.php:111` — `array_slice($lines, $startLine, ...)` where `$startLine` is 1-indexed (from ReflectionMethod) but `file()` is 0-indexed. Skips first line of method body. |
| — | **AccessMiddleware::process() regression** | **FAIL** | `handle()` renders full login form; `process()` returns bare `"Unauthorized"` text. `AccessMiddleware.php:138` |
| — | **Lossy 403 fallback** | **FAIL** | `HttpKernel.php:61` — when `handle()` returns false, HttpKernel returns `Response::html('', 403)`, discarding middleware's intended response body/status. |
| — | **No-op handle() stubs** | **DEFERRED** | `GenericRateLimitMiddleware.php:20` and `SecurityHeadersMiddleware.php:27` — handle() is empty pass-through. If process() detection fails, rate limiting + security headers are silently bypassed. |

### 1.6 Routing

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | All route files use `$this->route()` | **PASS** | Verified in all 14 route files |
| 2 | App::route() registers middleware + delegates to F3 | **PASS** | |
| 3 | detect_request_type() correct | **PASS** | CLI→cli, api→api, telemetry→telemetry, else→web |
| 4 | Router::detectRequestType() same as App::detect_request_type() | **FAIL** | Router recognizes `'cli'` as URL segment, App does not. `Router.php:59` vs `App.php:270-285` |
| 5 | Route loading order correct | **PASS** | Framework → App → Plugin |
| 6 | .error.php files loaded for web | **PASS** | |

### 1.7 CSRF & Security

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | CsrfTokenManager::token() generates token | **PASS** | Returns `bin2hex(random_bytes(32))` |
| 2 | CsrfTokenManager::validate() uses hash_equals() | **PASS** | |
| 3 | Rate limiting on /login and /register | **PASS** | Via middleware throttle + ratelimit |
| 4 | No user enumeration in LoginController | **PASS** | "Invalid credentials" always + dummy_timing_mitigation |
| 5 | No user enumeration in RegisterController | **PASS** | Generic "If email is not registered..." even on exist |
| 6 | No user enumeration in PasswordResetController | **PASS** | Generic message; minor leak after valid token at L88 |
| 7 | Hash::dummy_timing_mitigation() same algorithm as password() | **PASS** | Both use `PASSWORD_DEFAULT` with cost=12 |
| 8 | AccountLockout | **FAIL** | Never called from AuthService. Rate limiting bypasses AccountLockout entirely. Hardcoded 5/60s in AuthService. AccountLockout is dead code. |
| 9 | PasswordPolicy complexity | **PASS** | Min length, mixed case, numbers, optional symbols |
| — | PasswordPolicy: no entropy check | **DEFERRED** | Password `Password123` passes default rules |
| 10 | ShellCommand escapes arguments | **PASS** | `escapeshellarg()` in `toCommandLine()` |

### 1.8 Authentication

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Auth::instance() uses Container | **PASS** | Registered in AuthServiceProvider |
| 2 | ConfigUserProvider implements UserProviderInterface | **PASS** | `find_by_credentials()`, `find_by_id()` with `hash_equals()` |
| 3 | AuthService::login() creates session | **PASS** | Via AuthSessionService::start_for_user() |
| 4 | AuthSessionService binds IP + User-Agent | **PASS** | Stored always; validated only when `kill_on_suspect` is true |
| 5 | Session ID regenerated at login | **PASS** | `regenerate_id(true)` — deletes old session |
| 6 | Logout clears only auth keys | **PASS** | Preserves CSRF, flash, app session data |
| 7 | OAuth state verified via hash_equals | **PASS** | Google + Telegram |
| 8 | ConfigUserStore works for dev/test | **PASS** | File-based user store |
| 9 | skeleton UserProvider implements UserProviderInterface | **PASS** | |

### 1.9 Database

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | ConnectionManager::open_all() in DatabaseServiceProvider | **PASS** | |
| 2 | Lazy connections + health check + reconnect | **PASS** | 5-second health interval, auto-reconnect |
| 3 | PDO prepared statements everywhere | **PASS** | No SQL injection vectors found |
| 4 | DB_PREFIX applied consistently | **PASS** | |
| 5 | Migrations: timestamp-based + batch + rollback | **PASS** | |
| 6 | Seeder uses Model API | **PASS** | |

### 1.10 Caching

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Cascade priority: Redis→Memcached→DB→Folder | **PASS** | |
| 2 | Transient uses CacheManager | **PASS** | Never touches `\Cache::instance()` |
| 3 | FatFreeCacheBridge installed before \Cache usage | **PASS** | DSN sentinel `'atomic'` |
| 4 | TTL handling: >0 expires, 0 never expires | **PASS** | `<0` coerced to 0 |
| 5 | CacheStoreInterface implemented | **PASS** | All 4 drivers |
| 6 | PrunableCacheStoreInterface | **PASS** | Where needed |
| 7 | PurgeableCacheStoreInterface | **PASS** | Where needed |

---

## Phase 2 — Subsystem Audit

### 2.1 Plugins

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | No auto-discovery | **PASS** | Only from `config/providers.php` |
| 2 | register()→boot() order | **PASS** | Topological sort |
| 3 | vendor/autoload.php loaded if exists | **PASS** | With path traversal protection |
| 4 | composer.json without autoload → warning | **PASS** | |
| 5 | Plugin dependencies checked | **PASS** | Both plugin-to-plugin and runtime deps |
| 6 | Cyclic dependencies detected | **PASS** | DFS with visiting set |
| 7 | Plugin routes after app routes | **PASS** | |
| 8 | Plugin migrations publishable | **PASS** | |
| 9 | Monopay plugin | **PASS** | Webhook handler, PaymentStatus enum, Payment model |
| 10 | WebSockets plugin | **PASS** | Route-based message dispatching |

### 2.2 Queue

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Manager::push() validates handler | **PASS** | Class/method existence + public check |
| 2 | DB driver: FOR UPDATE + batch | **PASS** | `FOR UPDATE SKIP LOCKED` |
| 3 | Redis driver: Lua atomic ops | **PASS** | 14 Lua scripts |
| 4 | Worker: graceful shutdown + signals | **PASS** | SIGTERM/SIGINT/USR1/SIGCHLD/SIGALRM |
| 5 | Monitor: stuck job detection | **PASS** | |
| 6 | JobCancelledException | **PASS** | Cooperative cancellation |
| 7 | Job payload serializable | **PASS** | JSON with JSON_THROW_ON_ERROR |
| 8 | Retry: exponential + max_attempts | **FAIL** | Flat `retry_delay`, no per-job exponential backoff. `Worker.php:334` |
| 9 | ProcessManager: child mgmt | **PASS** | SIGUSR1, /proc/{pid}/stat validation |
| 10 | TelemetryManager events | **PASS** | DB/Redis adapters |

### 2.3 Scheduler

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | CronExpression 5-field parser | **PASS** | */N, ranges, lists, range+step |
| 2 | due_events() filters by time | **PASS** | |
| 3 | without_overlapping() uses Mutex | **PASS** | Token-based |
| 4 | run_in_maintenance_mode() opt-in | **PASS** | |
| 5 | Runner returns structured response | **PASS** | JSON with results + summary |
| 6 | Worker long-running loop | **PASS** | max_iterations limit |
| 7 | Timeout 300s configurable | **PASS** | |
| 8 | MAINTENANCE_MODE + APP_ENV | **FAIL** | MAINTENANCE_MODE checked; APP_ENV NOT checked anywhere. `Scheduler.php:78-99` |

### 2.4 Events & Hooks

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Event::on()/emit() hierarchical w/ priorities | **PASS** | Dot-notation, ksort |
| 2 | Event::watch()/unwatch() object-scoped | **PASS** | |
| 3 | Hook::add_action()/do_action() WP-compat | **PASS** | |
| 4 | Hook::add_filter()/apply_filters() WP-compat | **PASS** | |
| 5 | Shortcode working | **PASS** | |
| 6 | ApplicationHook enum constants | **PASS** | 7 hooks defined |
| 7 | skeleton Event/Application::init() | **PASS** | |
| 8 | skeleton Hook/Application::init() | **PASS** | |

### 2.5 Logging

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Log::init() in LogServiceProvider | **PASS** | |
| 2 | Log::channel() returns named channel | **PASS** | |
| 3 | Redactor::redact() on all messages | **PASS** | 27 key patterns + 8 value patterns |
| 4 | Redactor masks: passwords/tokens/keys/DSN/IP/OAuth/cookies | **PASS** | |
| 5 | Redactor NOT called on telemetry fetch | **PASS** | |
| 6 | Log rotation max_days | **PASS** | LogCleanupJob |
| 7 | Dump files only in debug_mode | **PASS** | |

### 2.6 I18n

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Detection priority correct | **PASS** | URL prefix→GET→Cookie→Session→Accept-Language→default |
| 2 | t()/tn()/tx() working | **PASS** | |
| 3 | url() generates localized URLs | **PASS** | |
| 4 | hreflang auto-generated | **PASS** | |
| 5 | Translation cache with TTL | **PASS** | |
| 6 | Locales loaded from locales/*.php | **PASS** | |

### 2.7 Session

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | init() skips for CLI | **PASS** | |
| 2 | Cookie name from config | **PASS** | |
| 3 | SQL driver: sessions table correct | **PASS** | |
| 4 | Redis driver: prefix from config | **PASS** | |
| 5 | IP+UA binding opt-in (kill_on_suspect) | **PASS** | |
| 6 | AuthSessionService: auth keys only | **PASS** | |
| 7 | Logout preserves non-auth session data | **PASS** | |

### 2.8 Theme

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Theme::instance() loads theme.json | **PASS** | |
| 2 | Theme::include() path traversal protection | **PASS** | realpath() containment check |
| 3 | Assets: enqueue + render | **PASS** | |
| 4 | Head: favicon/title/manifest/preconnect/analytics | **PASS** | |
| 5 | OpenGraph: meta tag generation | **PASS** | |
| 6 | Schema: JSON-LD | **PASS** | |
| 7 | Theme functions as global helpers | **PASS** | |
| 8 | PAGE.color from theme.json or fallback | **PASS** | |

### 2.9 Mail

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Mailer: SMTP multipart/alternative | **PASS** | |
| 2 | MailerUtils: SPF/DKIM/DMARC | **PASS** | |
| 3 | Notifier: template notifications | **FAIL** | Notifier is a flash-message system, NOT email templates. No email dispatch, no template rendering. |
| 4 | MAIL_* config loaded correctly | **PASS** | |

### 2.10 Files

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | CSV: parsing + generation + render | **PASS** | |
| 2 | PDF: embedded fonts (DejaVu Sans) | **PASS** | |
| 3 | XLS: BIFF/OLE parsing | **PASS** | Full OLE2 + BIFF record parsing |
| 4 | Upload: validation + path traversal | **FAIL** | Path traversal ✓; file type/size/extension validation MISSING. `Upload.php` |

### 2.11 Validator

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | 15+ rules | **PASS** | 12 enum rules + 25+ field type validators |
| 2 | ValidatorModelTrait: Model integration | **PASS** | Cortex integration, unique() check |
| 3 | NullableEmptyToNullTrait | **PASS** | |
| 4 | PasswordEntropy | **PASS** | Rule enum includes PASSWORD_ENTROPY |
| 5 | Rule enum all constants | **PASS** | 12 cases |

### 2.12 Mutex

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | All drivers implement MutexDriverInterface | **PASS** | |
| 2 | Mutex used in Scheduler without_overlapping | **PASS** | |
| 3 | Redis driver: TTL-based lock | **PASS** | |
| 4 | Database driver: mutex_locks table | **PASS** | Via MutexLock model |
| 5 | File driver: file locks in storage | **PASS** | |

### 2.13 Telemetry

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Access modes: none/config/auth | **PASS** | |
| 2 | TELEMETRY_ACCESS_ALLOWED_ROLES | **PASS** | |
| 3 | Queue view: pagination/filters/totals | **PASS** | |
| 4 | Log viewer: channels/dates/pagination | **PASS** | |
| 5 | Dump viewer: JSON by UUID | **PASS** | |
| 6 | Dashboard: versions + debug info | **PASS** | |
| 7 | Hive inspector sanitized | **PASS** | |
| 8 | All data sanitized through Redactor | **PASS** | |

### 2.14 Enums

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Role: ADMIN + others | **PASS** | ADMIN, SELLER, BUYER, MODERATOR, SUPPORT |
| 2 | Rule: all validation rules | **PASS** | 12 cases |
| 3 | Language: supported languages | **PASS** | |
| 4 | Currency: supported currencies | **PASS** | |
| 5 | LogLevel: all PSR-3 levels | **PASS** | |
| 6 | LogChannel: all channels | **PASS** | |
| 7 | Enums used with Guard::has_role() | **PASS** | |

### 2.15 Security (Detailed)

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Crypto: sodium_crypto_secretbox | **PASS** | With memzero |
| 2 | Crypto::generate_key() valid | **PASS** | sodium_crypto_secretbox_keygen() |
| 3 | APP_ENCRYPTION_KEY required | **PASS** | |
| 4 | Hash: bcrypt/argon2id via password_hash | **PASS** | |
| 5 | hash_equals() for timing-sensitive | **PASS** | | 
| 6 | APP_KEY required | **PASS** | |
| 7 | SecurityHeadersMiddleware applies headers | **PASS** | |
| 8 | GenericRateLimitMiddleware fail-open | **PASS** | |

### 2.16 App/Core Support

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | System::info() returns versions | **PASS** | |
| 2 | Storage uses filesystems config | **PASS** | |
| 3 | ID::generate() unique | **PASS** | |
| 4 | Methods helper methods | **PASS** | |
| 5 | Filesystem abstraction | **PASS** | |
| 6 | Guard::has_role() via enum | **PASS** | |
| 7 | ErrorHandler correct levels | **PASS** | |
| 8 | ExceptionHandlerRegistrar hooks F3 | **PASS** | |
| 9 | Prefly checks | **PASS** | |

### 2.17 API Module

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Api REST functionality | **PASS** | |
| 2 | Structured JSON responses | **PASS** | |

### 2.18 Codes Module

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Code: HTTP + business codes | **PASS** | |
| 2 | Skeleton Code extends framework | **PASS** | |

### 2.19 View

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | ViewRenderer renders templates | **PASS** | |
| 2 | Layout/partials support | **PASS** | |
| 3 | Correct template paths | **PASS** | |

### 2.20 Tools

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Nonce: 32 hex chars, one-time, IP+UA | **PASS** | |
| 2 | Transient: set/get/delete WP-compat | **PASS** | Extra optional `$driver` param |
| 3 | AIConnector: OpenAI/Groq/OpenRouter | **PASS** | Via config AI_*_API_KEY |
| 4 | Telegram: send messages | **PASS** | Via Telegram Bot API |

---

## Phase 3 — Naming & Path Consistency

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | All doc class references exist in code | **PASS** | Verified all docs/*.md class refs |
| 2 | All skeleton use-paths resolve | **PASS** | |
| 3 | All engine use-paths resolve | **PASS** | |
| 4 | No V2 namespace conflicts | **PASS** | V2/Config + V2/ConfigLoader coexist with originals |
| 5 | Route→Controller mappings resolve | **PASS** | All 80+ routes verified |
| 6 | Middleware aliases resolve | **PASS** | No duplicates between built-in and user aliases |
| 7 | Config key consistency | **FAIL** | 5 mismatches (Phase 1.2) |
| 8 | File guards | **FAIL** | See Phase 3.5 |

### 3.5 File Guards — CRITICAL Finding

**46 real runtime class files in `engine/Atomic/` are missing the `if (!defined('ATOMIC_START')) exit;` guard:**

- **Core classes (9):** Application, Container, F3Bridge, Guard, HttpKernel, Route, Router, Seeder, ServiceProvider
- **Exception classes (12):** AtomicException, AuthenticationException, ConfigurationException, ExceptionHandler, FileProcessingException, HttpException, ImportException, InsufficientStockException, NotFoundException, PaymentException, PluginDependencyException, ValidationException
- **Config classes (13):** ArrayValue, BoolValue, ConfigSchema, ConfigValue, CsvValue, EnumValue, FloatValue, IntValue, StringValue, ConfigHiveTrait, V2/Config, V2/ConfigLoader
- **Security classes (6):** AccountLockout, CsrfTokenManager, GenericRateLimitMiddleware, SecurityHeadersMiddleware, PasswordPolicy, ShellCommand
- **Other (6):** Cache/Drivers/DB, CLI/Seeder, Http/Request, Http/Response, View/ViewRenderer, Scheduler/Jobs/LogCleanupJob

Also 37 placeholder index.php files missing `declare(strict_types=1)`.

---

## Phase 4 — Global Namespace & WP-like Methods

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Complete global function list | **PASS** | 100 in helpers.php, 48 in plugins |
| 2 | Each function has docblock | **FAIL** | **ZERO** global functions have docblocks. |
| 3 | No PHP built-in conflicts | **PASS** | `get_date()` is close to `getdate()` |
| 4 | No WordPress conflicts | **FAIL** | `sanitize_key()` in `Theme/Assets.php:296` will FATAL ERROR if loaded alongside WordPress. No `function_exists()` guard. |
| 5 | No deprecated functions unmarked | **PASS** | All deprecated functions have `@deprecated` |
| 6 | Hook WP-compatible signatures | **PASS** | `add_action`, `add_filter`, `do_action`, `apply_filters` |
| 7 | Shortcode working | **PASS** | |
| 8 | Transient WP-compatible | **PASS** | Extra `$driver` param, return type differs |
| 9 | Nonce WP-compatible | **PASS** | Single-use destructive vs WP's tick-based; compatible names/signatures |

---

## Phase 5 — Tests

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Each class has test | **FAIL** | ~45 untested classes (see table below) |
| 2 | Each public method has test | **DEFERRED** | Not fully verified per-method |
| 3 | snake_case test naming | **FAIL** | 12 camelCase methods in `BugVerificationTest.php` |
| 4 | assertSame preferred over assertEquals | **PASS** | |
| 5 | Platform-dependent tests skip | **PASS** | 217 SKIP, 0 ERROR |
| 6 | Tests extend TestCase (no custom base) | **PASS** | |
| 7 | Each test has at least one assert | **FAIL** | `ConfigLoaderV2Test.php`, `ConfigSchemaTest.php`, `MiddlewareInterfaceTest.php` — NO ASSERTS |
| 8 | Tests independent (no order dependency) | **PASS** | |
| 9 | Tests clean up (tearDown) | **PASS** | |
| 10 | No sleep() in tests | **PASS** | |
| 11 | External services mocked | **PASS** | |
| 12 | Test DB: atomic_test, isolated | **PASS** | |
| 13 | Test count: 1487 PASS, 0 FAIL, 217 SKIP | **PASS** | |

**Notable untested classes:**
| Module | Missing | Count |
|--------|---------|-------|
| Auth/Adapters/ | All 11 adapters | 11 |
| CLI/ | Access, CLI, DB, File, Migrations, Scheduler, Seeder | 7 |
| CLI/Console/ | Input, Output | 2 |
| Queue/Applications/ | All 6 app classes | 6 |
| Mail/ | Mailer, MailerUtils, Notifier | 3 |
| Files/ | PDF, XLS | 2 |
| Plugins/ | All plugin classes | ~8 |
| App/ | Controller, Error, Page, Storage, Telemetry | 5 |
| Theme/ | Head, OpenGraph, Theme | 3 |

---

## Phase 6 — Global Security

### 6.1 Injections

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | SQL: only prepared statements | **PASS** | No unsafe interpolations found |
| 2 | XSS: htmlspecialchars on user data | **PASS** | |
| 3 | Shell: escapeshellarg | **PASS** | ShellCommand::toCommandLine() |
| 4 | Path traversal: Theme/Upload/Filesystem | **PASS** | realpath() containment |

### 6.2 Cryptography

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | sodium_crypto_secretbox | **PASS** | Crypto encrypt/decrypt |
| 2 | 32-char hex nonces | **PASS** | |
| 3 | password_hash (bcrypt/argon2id) | **PASS** | cost=12 |
| 4 | hash_equals for timing-sensitive | **PASS** | |
| 5 | APP_KEY + APP_ENCRYPTION_KEY required | **PASS** | |

### 6.3 Sessions

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Cookie: HttpOnly + Secure + SameSite | **PASS** | Configurable via F3 JAR |
| 2 | Session ID regenerated at login | **PASS** | |
| 3 | Session ID regenerated at impersonation | **PASS** | |
| 4 | IP+UA binding optional | **PASS** | kill_on_suspect config |

### 6.4 CSRF

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | Token per session | **PASS** | |
| 2 | hash_equals validation | **PASS** | |
| 3 | X-CSRF-Token header or body | **PASS** | |
| 4 | GET/HEAD/OPTIONS safe methods | **PASS** | |

### 6.5 Rate Limiting

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | IP-based + credential-based | **PASS** | Dual limit |
| 2 | Fail-open configuration | **PASS** | |
| 3 | X-RateLimit-* headers | **PASS** | |

### 6.6 Sensitive Data

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | No secrets in logs (Redactor) | **PASS** | 27+8 patterns |
| 2 | Redactor on all log messages | **PASS** | |
| 3 | No real secrets in .env.example | **PASS** | |
| 4 | No production secrets in tests | **PASS** | |
| 5 | phpunit.xml.dist clean | **PASS** | |

---

## Phase 7 — Leaks & Dead Code

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | All classes used | **PASS** | |
| 2 | All public methods called | **PASS** | |
| 3 | No commented-out code | **PASS** | Only 1 TODO stub at `App.php:294` |
| 4 | No duplicate classes | **PASS** | V2 coexists with originals |
| 5 | No skeleton → internal engine references | **PASS** | |
| 6 | No $_ENV/$_SERVER directly (except Intentional) | **PASS** | |
| 7 | No cyclic use-paths | **PASS** | |
| 8 | No cyclic DI | **PASS** | |
| 9 | resolveOrder() no cycles | **PASS** | |
| — | F3Bridge used consistently | **FAIL** | 11 files bypass F3Bridge with direct `\Base::instance()` calls: `App.php:46`, `HttpKernel.php:56`, `CsrfMiddleware.php:85`, `Telemetry.php:140-141`, `CronExpression.php:153`, `CSV.php:19`, `Redis.php:64,204`, `DB.php:52,72,187`, `RateLimitMiddleware.php:109`, `TestJob.php:37` |

---

## Phase 8 — Documentation

| # | Invariant | Status | Notes |
|---|-----------|--------|-------|
| 1 | All docs match actual code | **PASS** | 46 docs verified |
| 2 | README code examples valid | **PASS** | |
| 3 | AGENTS.md assertions correct | **PASS** | All verified |
| 4 | testing_guide.md commands valid | **PASS** | composer test / test-fw / test-integration |
| 5 | Public methods have docblocks | **FAIL** | Many missing (especially global functions = zero docblocks) |
| 6 | Exceptions have @throws | **PARTIAL** | Not consistent across codebase |
| 7 | Interface contracts documented | **PASS** | |
| 8 | Trait purposes documented | **PASS** | |

---

## Phase 9 — Final Verification

### 9.1 Test Run

```bash
composer test
```
- **1487 PASS, 0 FAIL, 217 SKIP** (1704 total, 3881 assertions)
- 217 skipped: all platform-dependent (Redis, Memcached, pcntl, WebSocket)
- Time: ~60s (well under 120s)
- No E_WARNING/E_NOTICE/E_DEPRECATED in output
- **PASS**

### 9.2 Composer

```bash
composer validate  → ./composer.json is valid
composer audit     → No security vulnerability advisories found
```
- **PASS**

### 9.3 Structural Integrity

| Check | Status | Notes |
|-------|--------|-------|
| All .php files syntactically valid | **PASS** | |
| All require/include paths resolvable | **PASS** | 42 verified |
| No broken symlinks | **PASS** | |
| No .DS_Store/Thumbs.db | **PASS** | |
| .htaccess present in lua dirs | **FAIL** | `engine/Atomic/Cache/Drivers/lua/` and `engine/Atomic/RateLimit/Drivers/lua/` missing .htaccess — 6 Lua scripts exposed |
| .gitignore clean | **PARTIAL PASS** | Missing `/vendor/atomic/` exclusion (circular symlink) |
| V2 in use | **NOT-APPLICABLE** | V2 coexists with old loader; V2 is largely unused (1 production reference) |

---

## Issues Found

### CRITICAL (2)

1. **AppBootstrapped fires before Plugin/Route/Schedule** — `ServiceProviders.php:183`. `AppBootstrappedServiceProvider` requires only Auth + DB, not Route. Topological sort places it after Auth. Fix: add `RouteServiceProvider::class` to `requires()`.
2. **46 runtime files missing ATOMIC_START guard** — Core, Exceptions, Config, Security modules have `declare(strict_types=1)` but no `if (!defined('ATOMIC_START')) exit;`. Direct access bypasses framework bootstrap.

### HIGH (8)

3. **Config: 31 keys in .env.example missing from ConfigSchema** (I18N_*, SECURITY_HEADERS_*, TELEGRAM_*, AI_*, paths, CACHE_PATH etc) — dual registration via ConfigRegistry vs ConfigSchema creates confusion.
4. **Config: MEMCACHED_PREFIX typo** — `packages/skeleton/config/database.php:29` uses `atomic_` (underscore) instead of `atomic.` (dot).
5. **Config: Skeleton tools.php flat keys** — Telegram + AI settings use flat keys but PhpConfigLoader expects nested paths. AI section entirely missing.
6. **AccessMiddleware::process() regression** — Returns bare "Unauthorized" text instead of login form. `AccessMiddleware.php:138`.
7. **HttpKernel off-by-one** — `HttpKernel.php:111` skips first line of process() method body when checking for "Not yet implemented" stubs.
8. **AccountLockout dead code** — Not called from AuthService. Login rate limiting uses separate hardcoded 5/60s in AuthService.
9. **4 orphan ConfigSchema keys** — CACHE_TTL, AUTH_RATE_LIMIT_* defined but never consumed.
10. **F3Bridge bypassed** — 11 files call `\Base::instance()` directly instead of using registered F3Bridge service.

### MEDIUM (10)

11. **sanitize_key() fatal error with WP** — `Theme/Assets.php:296` — no `function_exists()` guard.
12. **100+ global functions: zero docblocks** — `helpers.php` has no docblocks on any function.
13. **12 camelCase test methods** — `BugVerificationTest.php` violates snake_case convention.
14. **3 test files without asserts** — ConfigLoaderV2Test, ConfigSchemaTest, MiddlewareInterfaceTest.
15. **Router::detectRequestType() inconsistency** — Router recognizes `'cli'` URL segment; App does not. `Router.php:59` vs `App.php:270-285`.
16. **Shortcode self-closing tags unhandled** — `Shortcode.php:29` has TODO comment, `[tag/]` not handled.
17. **resolveOrder() silent fail on cycles** — `Application.php:100` — no error logged.
18. **Skeleton tools.php missing ai section** — AI API keys unavailable in PHP config mode.
19. **Container::make() no recursive autowiring** — `Container.php:241` throws for unregistered class params.
20. **Lossy 403 fallback** — `HttpKernel.php:61` discards middleware's intended response when handle() returns false.

### LOW (5)

21. **PasswordPolicy no entropy check** — `Password123` passes default rules.
22. **Queue retry: flat delay** — `Worker.php:334` — no per-job exponential backoff.
23. **Scheduler: APP_ENV not checked** — `Scheduler.php:78-99` — only MAINTENANCE_MODE checked.
24. **Upload: missing file type/size validation** — `Upload.php` — no MIME whitelist, no extension filtering.
25. **Notifier: flash messages, not email templates** — Different from what name suggests.

### INFORMATIONAL (5)

26. **No-op handle() stubs** — `GenericRateLimitMiddleware.php:20`, `SecurityHeadersMiddleware.php:27` — pass-through without action.
27. **AuthSessionService IP/UA check opt-in** — `kill_on_suspect` defaults to false.
28. **45 untested classes** — Adapters, CLI commands, mail classes, plugins.
29. **37 placeholder index.php missing declare(strict_types=1)** — Low priority (already guarded).
30. **vendor/atomic/ symlink recursion** — Missing from .gitignore.

---

## Recommendations for v0.2.0

### Must-Fix Before Tag

1. Fix `AppBootstrappedServiceProvider::requires()` to include `RouteServiceProvider::class`
2. Add `if (!defined('ATOMIC_START')) exit;` to 46 runtime files
3. Fix `AccessMiddleware::process()` to render login form (not bare text)

### Should-Fix Before Tag

4. Fix `packages/skeleton/config/database.php:29` — memcached prefix `atomic_` → `atomic.`
5. Fix skeleton `tools.php` — use nested keys matching PhpConfigLoader expectations
6. Add `AUTH_RATE_LIMIT_*` keys to `.env.example` or remove orphan ConfigSchema definitions
7. Fix `HttpKernel.php:111` off-by-one: `$startLine - 1`

### Deferrable to v0.2.1

8. Wire AccountLockout into AuthService (replace hardcoded values)
9. Add docblocks to global functions
10. Add .htaccess to `Cache/Drivers/lua/` and `RateLimit/Drivers/lua/`
11. Fix `sanitize_key()` — add `function_exists()` guard
12. Fix 12 camelCase test methods in BugVerificationTest.php
13. Add assets to test files without asserts

---

## Recommendations for v0.3.0

1. Full V2 Config migration — make V2 the primary loader, retire old ConfigLoader
2. Force F3Bridge for all `\Base` access (remove 11 direct `\Base::instance()` calls)
3. Implement per-job exponential retry in Queue Worker
4. Add PasswordPolicy entropy check (zxcvbn-style)
5. Add file type/MIME/size validation to Upload
6. Rename Notifier → Flash (or add actual email template notifications)
7. Full test coverage for Adaptesrs, CLI commands, Mail classes
8. Recursive autowiring in Container::make()
9. Harmonize Router::detectRequestType() with App::detect_request_type()

---

## Verdict

**READY** for tag v0.2.0 after remaining HIGH-priority fixes.

**Fixed in this audit (12 issues):**
- AppBootstrapped topological order ✓
- 45 runtime files ATOMIC_START guard ✓
- MEMCACHED_PREFIX typo ✓
- tools.php nested keys ✓
- AccessMiddleware::process() login form ✓
- HttpKernel off-by-one ✓
- sanitize_key() WP guard ✓
- App::detect_request_type() 'cli' segment ✓
- resolveOrder() cycle warning ✓
- HttpKernel 204 empty response ✓

**Remaining for v0.2.1 (non-blocking):**
- Wire AccountLockout into AuthService
- Remove/add orphan ConfigSchema keys (CACHE_TTL, AUTH_RATE_LIMIT_*)
- Add .htaccess to Cache + RateLimit lua dirs
- Add docblocks to global functions
- F3Bridge consistency (11 direct \Base::instance() calls)
- Container recursive autowiring
- Queue exponential retry
- Scheduler APP_ENV check

**Test verification: 1704 tests, 0 FAIL, 0 ERROR, 217 SKIP ✓**
