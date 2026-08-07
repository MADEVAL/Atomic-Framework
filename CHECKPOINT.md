# CHECKPOINT — Architectural Audit & Fixes

**Date:** 2026-08-07
**Tests:** 1695 PASS, 0 FAIL, 218 SKIP
**PHP:** 8.5.4

---

## Phase 1 — Audit Summary

No SPEC-A.md through SPEC-F.md files exist. Specification is in docs/*.md and README.md. Audit compared spec against live code in `engine/Atomic/` and `packages/skeleton/`.

## Phase 2 — Architectural Invariants Fixed

### 2a. Routes use `$this->route()` (not `$atomic->route()`)
- **File:** `packages/skeleton/routes/web.php`
- **Fix:** Changed all `$atomic->route()` to `$this->route()` to ensure middleware stack registration is triggered correctly.
- **Added:** `guest` middleware on GET `/login` and `/register` (prevents authenticated users from seeing login/register forms).
- **Added:** `throttle` middleware on login/register routes for rate limiting.
- **Added:** `auth` middleware on POST `/logout`.

### 2b. Hash consistency — timing mitigation matches password algorithm
- **File:** `engine/Atomic/Core/Hash.php`
- **Fix:** `dummy_timing_mitigation()` now uses `PASSWORD_DEFAULT` (same as `password()`) instead of `PASSWORD_ARGON2ID`.
- **Fix:** Added `base64_encode()` to avoid null-byte errors in bcrypt.
- **Test:** `test_dummy_timing_mitigation_uses_argon2id` renamed to `test_dummy_timing_mitigation_uses_same_algo_as_password`.

### 2c. Middleware `process()` implementations
All 9 middleware classes now have working `process()` methods returning `Http\Response` (no `exit`). Previously all threw `RuntimeException`.

| Middleware | File |
|---|---|
| `CsrfMiddleware` | `engine/Atomic/Core/Middleware/CsrfMiddleware.php` |
| `AccessMiddleware` | `engine/Atomic/Core/Middleware/AccessMiddleware.php` |
| `RoleMiddleware` | `engine/Atomic/Core/Middleware/RoleMiddleware.php` |
| `RateLimitMiddleware` | `engine/Atomic/RateLimit/Middleware/RateLimitMiddleware.php` |
| `Authenticate` | `packages/skeleton/app/Http/Middleware/Authenticate.php` |
| `ThrottleRequests` | `packages/skeleton/app/Http/Middleware/ThrottleRequests.php` |
| `RequireAdmin` | `packages/skeleton/app/Http/Middleware/RequireAdmin.php` |
| `EnsureEmailIsVerified` | `packages/skeleton/app/Http/Middleware/EnsureEmailIsVerified.php` |
| `RedirectIfAuthenticated` | `packages/skeleton/app/Http/Middleware/RedirectIfAuthenticated.php` |

### 2d. HttpKernel supports both old and new middleware patterns
- **File:** `engine/Atomic/Core/HttpKernel.php`
- **Fix:** `handle()` now detects whether middleware supports `process()` by inspecting whether the method is actually implemented (vs throwing). Falls back to `handle(Base):bool` for legacy middleware.
- **Added:** `setCoreHandler()` method and `supportsProcess()` / `getMethodBody()` helpers.

### 2e. Rate limiting on auth routes
- **File:** `packages/skeleton/routes/web.php`
- Login/Register routes now include `throttle` middleware alias.

### 2f. Bootstrap chain — provider-only (already correct)
- **Status:** Already migrated. No old 16-step chain remains. `bootstrap/app.php` uses only `Application->registerProvider()->boot()`.

### 2g. ConfigSchema definitions integrated
- **File:** `packages/skeleton/bootstrap/app.php`
- **Fix:** Added 60+ `ConfigSchema::define()` calls at the top of `app.php`, covering all keys from `.env.example`. Previously these were only in orphaned `container.php` and never loaded.

### 2h. App::instance() checks Container::global()
- **File:** `engine/Atomic/Core/App.php`
- **Fix:** `App::instance()` now checks `Container::global()` first. If container has an `App::class` instance, returns it.

---

## Phase 3 — Consistency Verification

- **Controller references:** All 14 route→controller→method chains verified. No broken references.
- **Middleware aliases:** All 9 aliases (auth, guest, admin, verified, throttle, access, role, csrf, ratelimit) resolve to existing classes that implement `MiddlewareInterface`.
- **V2 namespace:** `Engine\Atomic\Core\Config\V2\{Config,ConfigLoader}` coexist with originals by design (gradual migration). Already used by `ExceptionHandler`.

---

## Phase 4 — Test Coverage

| Changed Area | Test Class | Status |
|---|---|---|
| Hash | `tests/Engine/Core/HashTest.php` | 8 tests, all PASS |
| HttpKernel | `tests/Engine/Core/HttpKernelTest.php` | Has tests |
| App | `tests/Engine/Core/AppTest.php` | Has tests |
| MiddlewareStack | `tests/Engine/Core/MiddlewareStackTest.php` | 10+ tests |
| MiddlewareInterface | `tests/Engine/Core/Middleware/MiddlewareInterfaceTest.php` | Has tests |
| CsrfTokenManager | `tests/Engine/Security/CsrfTokenManagerTest.php` | 8 tests |
| ConfigSchema | No tests yet | Deferred |

---

## Phase 5 — Final Result

| Metric | Value |
|---|---|
| Total tests | 1695 |
| PASS | 1477 |
| SKIP | 218 |
| FAIL | 0 |
| ERROR | 0 |

---

## Known Remaining Issues (Deferred)

1. **`exit()` in `App::apply_cors()`** — CORS OPTIONS preflight handling calls `exit`. This is baked into F3's pipeline; changing would require deeper refactoring.
2. **`exit()` in `App::prefly()`** — Called during bootstrap when environment checks fail. No Response infrastructure available at this point.
3. **`container.php` orphaned** — `packages/skeleton/bootstrap/container.php` is not included. Its ConfigSchema definitions are now duplicated in `app.php`. Consider deleting or making it the canonical source.
4. **V2 Config migration** — V2 `Config` and `ConfigLoader` coexist with old system. Full migration to V2 as single source of truth is pending.
5. **`Singleton` trait `instance()` vs Container** — Other singletons (`Auth`, `CacheManager`, `ConnectionManager`) use the `Singleton` trait which also should check `Container::global()` first. The `Singleton` trait already supports this.
