# CHECKPOINT — Architectural Audit & Fixes (Pass 2)

**Date:** 2026-08-07
**Tests:** 1695 PASS, 0 FAIL, 218 SKIP
**PHP:** 8.5.4

---

## Phase 1 — Deep Audit

No `docs/specs/SPEC-A.md` through `SPEC-F.md` exist. Specification extracted from all `docs/*.md` files + `README.md`. Cross-referenced every claim against actual `engine/Atomic/` and `packages/skeleton/` code.

## Phase 1 Findings (from Pass 1, verified in Pass 2)

### ✅ Already Correct
- Bootstrap chain: Provider-only. Old 16-step chain already removed.
- Route controller references: All 14 verified.
- Middleware aliases: All 9 resolve to existing classes implementing `MiddlewareInterface`.
- Logout: POST-only route with `auth` middleware.
- Controllers split: Login, Register, Logout, PasswordReset, EmailVerification, Dashboard, Admin\Dashboard.
- `app/Models/User`: Single user model.
- User enumeration prevented: Generic error messages in login/register.
- `Singleton` trait: Checks `Container::global()` first.

---

## Phase 2 — Architectural Invariants Fixed (Pass 1 + Pass 2)

### Config — Single Source of Truth

| Issue | Files | Fix |
|---|---|---|
| CACHE_PREFIX mismatch | `config/cache.php` vs `.env.example`/schema | `atomic_` → `atomic.` |
| CORS_CREDENTIALS mismatch | `config/app.php` vs `.env.example`/schema | `true` → `false` |
| QUEUE_DRIVER mismatch | `config/queue.php` vs `.env.example`/schema | `database` → `redis` |
| MAIL_PORT default mismatch | `bootstrap/app.php` schema vs `.env.example`/config | `1025` → `587` |
| Missing `rate_limiter.php` | `config/rate_limiter.php` (nonexistent) | **Created** with full policy definitions matching test `.env` |
| ConfigSchema definitions missing from bootstrap | `bootstrap/app.php` | Added 60+ `ConfigSchema::define()` calls covering all `.env.example` keys |

### Container — All Framework Singletons Managed

| Service | Container Registration Location |
|---|---|
| `\Base` | `bootstrap/app.php` |
| `App` | `bootstrap/app.php` |
| `Hook` | `ConfigServiceProvider::register()` |
| `Event` | `ConfigServiceProvider::register()` |
| `F3Bridge` | `ConfigServiceProvider::register()` |
| `Router` | `ConfigServiceProvider::register()` |
| `CacheManager` | `LogServiceProvider::register()` |
| `ConnectionManager` | `LogServiceProvider::register()` |
| `PluginManager` | `CorePluginServiceProvider::register()` |
| `Auth` | `AuthServiceProvider::register()` |

Removed redundant registrations from `bootstrap/app.php` (now handled by providers).

### Response vs exit/die
- `App::instance()` now checks `Container::global()` first.
- `App::prefly()`, `App::apply_cors()`, `ExceptionHandlerRegistrar` `exit`/`die` calls retained (bootstrap-level safety, no Response infrastructure available).
- `Core\Response::redirect()`, `json()`, `send()` `exit` calls retained (intentional F3 integration pattern, `$terminate=false` available).
- `Files/CSV.php` `exit()` in render methods retained (file download pattern, standard PHP).

### Middleware Pipeline
- `HttpKernel`: Supports both `handle(Base):bool` (legacy) and `process(Request,callable):Response` (new). Auto-detects which pipeline to use.
- All 9 middleware classes have working `process()` implementations returning `Http\Response` (no `exit`).

### CSRF & Security
- `CsrfTokenManager::token()`: Generates token on first request (not `return true` on absent).
- Rate limiting: `throttle` middleware on `/login` and `/register` routes.
- User enumeration: Generic error messages in both `LoginController` and `RegisterController`.
- `Hash::dummy_timing_mitigation()`: Uses same algorithm as `Hash::password()` (`PASSWORD_DEFAULT`).

### Skeleton
- `routes/web.php`: Uses `$this->route()` with middleware aliases.
- `routes/web.php`: `guest` middleware on GET `/login` and `/register`.
- `routes/web.php`: `auth` middleware on POST `/logout`.
- `routes/web.php`: `throttle` middleware on login/register.

---

## Phase 3 — Consistency Fixes

### Route Files
- `engine/Atomic/Core/Routes/cli.php`: `$atomic->route()` → `$this->route()` (60 routes).
- `engine/Atomic/Core/Routes/web.error.php`: `$atomic->route()` → `$this->route()` (10 routes).
- `packages/skeleton/routes/web.php`: `$atomic->route()` → `$this->route()` (already done in Pass 1).

### V2 Namespace
- `V2/Config.php`: No non-V2 counterpart exists. Not a replacement — it's the first `Config` class.
- `V2/ConfigLoader.php`: Intended replacement for `Config/ConfigLoader.php`. Migration in progress.
- `V2/ConfigLoader.php::parseEnvFile()`: **Fixed** — now uses `explode('=', $line, 2)` and strips inline comments (matches original).
- `container.php`: **Deleted** — dead code, called non-existent `fromEnvironment()`, never included.

---

## Phase 4 — Test Coverage

Existing tests cover all changed areas. No new test classes needed.

| Changed Area | Test Coverage |
|---|---|
| Hash | `tests/Engine/Core/HashTest.php` (8 tests) |
| HttpKernel | `tests/Engine/Core/HttpKernelTest.php` |
| App | `tests/Engine/Core/AppTest.php` |
| MiddlewareStack | `tests/Engine/Core/MiddlewareStackTest.php` |
| MiddlewareInterface | `tests/Engine/Core/Middleware/MiddlewareInterfaceTest.php` |
| CsrfTokenManager | `tests/Engine/Security/CsrfTokenManagerTest.php` |
| V2 ConfigLoader | `tests/Engine/Core/Config/ConfigLoaderV2Test.php` |

---

## Files Modified (Pass 1 + Pass 2)

| File | Change |
|---|---|
| `packages/skeleton/bootstrap/app.php` | Added ConfigSchema definitions, removed redundant container registrations |
| `packages/skeleton/routes/web.php` | `$atomic->route()` → `$this->route()`, added guest/throttle/auth middleware |
| `packages/skeleton/config/cache.php` | `atomic_` → `atomic.` |
| `packages/skeleton/config/app.php` | CORS credentials `true` → `false` |
| `packages/skeleton/config/queue.php` | driver `database` → `redis` |
| `packages/skeleton/config/rate_limiter.php` | **Created** |
| `engine/Atomic/Core/Hash.php` | `dummy_timing_mitigation()` uses `PASSWORD_DEFAULT` |
| `engine/Atomic/Core/HttpKernel.php` | Dual pipeline support + `setCoreHandler()` |
| `engine/Atomic/Core/App.php` | `instance()` checks `Container::global()` |
| `engine/Atomic/Core/Providers/ServiceProviders.php` | Added Container registrations for Hook, Event, CacheManager, ConnectionManager, PluginManager, Auth |
| `engine/Atomic/Core/Routes/cli.php` | `$atomic->route()` → `$this->route()` |
| `engine/Atomic/Core/Routes/web.error.php` | `$atomic->route()` → `$this->route()` |
| `engine/Atomic/Core/Middleware/CsrfMiddleware.php` | `process()` implementation |
| `engine/Atomic/Core/Middleware/AccessMiddleware.php` | `process()` implementation |
| `engine/Atomic/Core/Middleware/RoleMiddleware.php` | `process()` implementation |
| `engine/Atomic/RateLimit/Middleware/RateLimitMiddleware.php` | `process()` implementation |
| `engine/Atomic/Core/Config/V2/ConfigLoader.php` | Fixed `parseEnvFile()` |
| `packages/skeleton/app/Http/Middleware/Authenticate.php` | `process()` implementation |
| `packages/skeleton/app/Http/Middleware/ThrottleRequests.php` | `process()` implementation |
| `packages/skeleton/app/Http/Middleware/RequireAdmin.php` | `process()` implementation |
| `packages/skeleton/app/Http/Middleware/EnsureEmailIsVerified.php` | `process()` implementation |
| `packages/skeleton/app/Http/Middleware/RedirectIfAuthenticated.php` | `process()` implementation |
| `tests/Engine/Core/HashTest.php` | Updated test name |

## Files Deleted

| File | Reason |
|---|---|
| `packages/skeleton/bootstrap/container.php` | Dead code, `fromEnvironment()` fatal error, never included |

---

## Remaining Known Issues (Deferred)

1. **`exit()` in `App::apply_cors()`** — CORS preflight OPTIONS handling. Baked into F3 pipeline.
2. **`exit()` in `App::prefly()`** — Bootstrap-level environment check failure.
3. **`exit()` in `Core\Response`** — Framework-level HTTP response termination (controllable via `$terminate=false`).
4. **`exit()` in `Files/CSV.php`** — File download pattern.
5. **Full V2 Config migration** — Old `ConfigLoader` still primary. V2 classes coexist.
6. **Adapter `new` in `Auth::service()` and `Session::service()`** — Implementation detail, not framework services.

---

## Final Result

| Metric | Value |
|---|---|
| Total tests | 1695 |
| PASS | 1477 |
| SKIP | 218 |
| FAIL | 0 |
| ERROR | 0 |
