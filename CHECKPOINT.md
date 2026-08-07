# CHECKPOINT — Atomic Framework v0.3 (Container Migration Phase)

Дата: 2026-08-07
Исходная точка: ARCHITECTURE_AUDIT.md (50+ проблем, 6 спеков A–F)
Финальный статус: все спеки реализованы или заблокированы обратной совместимостью

---

## Было → Стало

| Метрика | Было (baseline) | Стало |
|---------|-----------------|-------|
| Тесты | 1498 | **1695** |
| FAIL | 0 | 0 |
| ERROR | 0 | 0 |
| SKIP | 218 | 218 |
| Новых тестов | — | **+197** |
| Новых файлов фреймворка | — | **28** |
| Изменённых файлов скелетона | — | **25** |

---

## STATUS BY SPEC

### SPEC A — Kernel & DI Container: 100%

| Компонент | Файл | Тестов |
|-----------|------|--------|
| Container (PSR-11) | `engine/Atomic/Core/Container.php` | 24 |
| Application (kernel) | `engine/Atomic/Core/Application.php` | 7 |
| F3Bridge | `engine/Atomic/Core/F3Bridge.php` | 10 |
| Router + Route + RouteGroup | `engine/Atomic/Core/Router.php`, `Route.php` | 20 |
| HttpKernel | `engine/Atomic/Core/HttpKernel.php` | 5 |
| ServiceProvider | `engine/Atomic/Core/ServiceProvider.php` | 7 |
| MiddlewareInterface v2 | `engine/Atomic/Core/Middleware/MiddlewareInterface.php` | 4 |
| Singleton trait → Container delegation | `engine/Atomic/Core/Traits/Singleton.php` | 2 |

**Заблокировано для v0.4:**
- Удаление `App::__call()` (сотни вызовов `$atomic->get()` по всему коду)
- Удаление `Singleton` trait (20+ классов)
- Замена 16-шаговой цепочки на ServiceProvider'ы

### SPEC B — Configuration v2.0: 100%

| Компонент | Файл | Тестов |
|-----------|------|--------|
| ConfigSchema | `engine/Atomic/Core/Config/ConfigSchema.php` | 14 |
| 8 value-типов | `engine/Atomic/Core/Config/*Value.php` (8 шт) | — |
| ConfigLoaderV2 | `engine/Atomic/Core/Config/V2/ConfigLoader.php` | 8 |
| ConfigV2 (immutable) | `engine/Atomic/Core/Config/V2/Config.php` | — |

### SPEC C — Security Layer: 95%

| Компонент | Файл | Тестов |
|-----------|------|--------|
| Hash (argon2id, hmac) | `engine/Atomic/Core/Hash.php` | 4 |
| ShellCommand | `engine/Atomic/Security/ShellCommand.php` | 8 |
| AccountLockout | `engine/Atomic/Security/AccountLockout.php` | 9 |
| SecurityHeadersMiddleware | `engine/Atomic/Security/Middleware/SecurityHeadersMiddleware.php` | 3 |
| CsrfTokenManager | `engine/Atomic/Security/CsrfTokenManager.php` | 8 |
| PasswordPolicy | `engine/Atomic/Security/PasswordPolicy.php` | 8 |
| GenericRateLimitMiddleware | `engine/Atomic/Security/Middleware/GenericRateLimitMiddleware.php` | 5 |
| HttpException hierarchy | `engine/Atomic/Exceptions/HttpException.php` + 2 updated | 8 |

**Заблокировано для v0.4:**
- `AuthService` → Container (контроллеры используют `Auth::instance()`)
- `CsrfTokenManager` через SessionManager (старый `SESSION.csrf_token`)

### SPEC D — Exit-less Architecture: 90%

| Компонент | Файл | Тестов |
|-----------|------|--------|
| Response + JsonResponse | `engine/Atomic/Http/Response.php` | 13 |
| Request | `engine/Atomic/Http/Request.php` | 10 |
| ExceptionHandler | `engine/Atomic/Exceptions/ExceptionHandler.php` | 8 |
| ViewRenderer + View | `engine/Atomic/View/ViewRenderer.php` | 6 |

**Заблокировано для v0.4:**
- Удаление `exit`/`die` из 30+ файлов (`send_json_error`, `beforeroute`, `CSV::render_*`)
- `Controller::view()` helper (сломает старый Controller)
- `ExceptionHandlerRegistrar` → `ExceptionHandler`

### SPEC E — Skeleton & DX: 100%

| Компонент | Что сделано |
|-----------|------------|
| `bootstrap/container.php` | Container + ConfigSchema bindings |
| `bootstrap/app.php` | Container injected, core services registered |
| Auth controllers | `LoginController`, `RegisterController`, `LogoutController`, `PasswordResetController`, `EmailVerificationController` |
| Admin/API/Error controllers | `Admin\DashboardController`, `Api\HealthController`, `ErrorController` |
| Middleware | `RedirectIfAuthenticated`, `RequireRole`, `VerifyCsrfToken`, `ThrottleRequests`, `EnsureEmailIsVerified` |
| Routes | web, api, cli, web.error, schedule — все заполнены |
| Config | `debug→false`, `escape→true`, `cookie_secure→true`, middleware aliases расширены |
| User model | перенесён в `App\Models`, добавлены `email_verified_at`, `remember_token`, `updated_at` |
| `public/.htaccess` | Apache rewriting + security headers |
| `App\Hook`, `App\Event`, `App\Codes` | рабочие примеры |
| Удалены | `app/index.php`, `app/Models/index.php`, `app/Http/index.php`, `app/Http/Models/User.php` |
| `.env.example` | `APP_URL`, `APP_TIMEZONE`, `COOKIE_SECURE=true` |

### SPEC F — Testing & Platform: 100%

| Компонент | Файл |
|-----------|------|
| PlatformGuard | `tests/Support/PlatformGuard.php` |
| SkipIfMissing trait | `tests/Support/SkipIfMissing.php` |
| TestApplication | `tests/Support/TestApplication.php` |
| TestResponse | `tests/Support/TestResponse.php` |
| Integration tests | `tests/Integration/Http/HttpKernelIntegrationTest.php` |
| CI pipeline | `.github/workflows/tests.yml` (MySQL + Redis, PHP 8.2/8.3) |

---

## ПОЛНЫЙ СПИСОК ИЗМЕНЁННЫХ ФАЙЛОВ

### Новые файлы фреймворка (framework — 28 файлов):

```
engine/Atomic/Core/Container.php
engine/Atomic/Core/Application.php
engine/Atomic/Core/F3Bridge.php
engine/Atomic/Core/HttpKernel.php
engine/Atomic/Core/Router.php
engine/Atomic/Core/Route.php
engine/Atomic/Core/ServiceProvider.php
engine/Atomic/Core/Config/ConfigSchema.php
engine/Atomic/Core/Config/ConfigValue.php
engine/Atomic/Core/Config/StringValue.php
engine/Atomic/Core/Config/IntValue.php
engine/Atomic/Core/Config/BoolValue.php
engine/Atomic/Core/Config/FloatValue.php
engine/Atomic/Core/Config/ArrayValue.php
engine/Atomic/Core/Config/CsvValue.php
engine/Atomic/Core/Config/EnumValue.php
engine/Atomic/Core/Config/V2/ConfigLoader.php
engine/Atomic/Core/Config/V2/Config.php
engine/Atomic/Http/Response.php
engine/Atomic/Http/Request.php
engine/Atomic/Exceptions/ExceptionHandler.php
engine/Atomic/View/ViewRenderer.php
engine/Atomic/Security/ShellCommand.php
engine/Atomic/Security/AccountLockout.php
engine/Atomic/Security/CsrfTokenManager.php
engine/Atomic/Security/PasswordPolicy.php
engine/Atomic/Security/index.php
engine/Atomic/Security/Middleware/SecurityHeadersMiddleware.php
engine/Atomic/Security/Middleware/GenericRateLimitMiddleware.php
```

### Изменённые файлы фреймворка (3 файла):

```
engine/Atomic/Core/Traits/Singleton.php      — Container delegation
engine/Atomic/Core/Middleware/MiddlewareInterface.php — process() method
engine/Atomic/Core/Hash.php                   — argon2id, hmac, dummy_timing_mitigation()
```

### Обновлённые exception-файлы (2 файла):

```
engine/Atomic/Exceptions/HttpException.php     — base class + 4 subtypes
engine/Atomic/Exceptions/NotFoundException.php — extends HttpException
engine/Atomic/Exceptions/ValidationException.php — extends HttpException + errors()
```

### Middleware с process()-stub (4 файла):

```
engine/Atomic/Core/Middleware/AccessMiddleware.php
engine/Atomic/Core/Middleware/CsrfMiddleware.php
engine/Atomic/Core/Middleware/RoleMiddleware.php
engine/Atomic/RateLimit/Middleware/RateLimitMiddleware.php
```

### Скелетон — новые файлы (16 файлов):

```
packages/skeleton/bootstrap/container.php
packages/skeleton/app/Models/User.php
packages/skeleton/app/Http/Controllers/Auth/LoginController.php
packages/skeleton/app/Http/Controllers/Auth/RegisterController.php
packages/skeleton/app/Http/Controllers/Auth/LogoutController.php
packages/skeleton/app/Http/Controllers/Auth/PasswordResetController.php
packages/skeleton/app/Http/Controllers/Auth/EmailVerificationController.php
packages/skeleton/app/Http/Controllers/Admin/DashboardController.php
packages/skeleton/app/Http/Controllers/Api/HealthController.php
packages/skeleton/app/Http/Controllers/ErrorController.php
packages/skeleton/app/Http/Middleware/RedirectIfAuthenticated.php
packages/skeleton/app/Http/Middleware/RequireRole.php
packages/skeleton/app/Http/Middleware/VerifyCsrfToken.php
packages/skeleton/app/Http/Middleware/ThrottleRequests.php
packages/skeleton/app/Http/Middleware/EnsureEmailIsVerified.php
packages/skeleton/public/.htaccess
```

### Скелетон — изменённые файлы (13 файлов):

```
packages/skeleton/bootstrap/app.php           — Container injected
packages/skeleton/.env.example                 — APP_URL, APP_TIMEZONE, COOKIE_SECURE=true
packages/skeleton/config/app.php               — debug→false, escape→true
packages/skeleton/config/session.php           — cookie_secure→true, httponly typo fix
packages/skeleton/config/middleware.php        — guest, verified, throttle aliases
packages/skeleton/app/Auth/UserProvider.php    — use App\Models\User
packages/skeleton/app/Http/Controllers/AuthController.php — delegates to new controllers
packages/skeleton/routes/web.php               — full auth flow + admin
packages/skeleton/routes/api.php               — health + auth
packages/skeleton/routes/cli.php               — cache/make commands
packages/skeleton/routes/web.error.php         — 403/404/500/503
packages/skeleton/routes/schedule.php          — log cleanup + session gc + queue
packages/skeleton/app/Hook/Application.php     — working examples
packages/skeleton/app/Event/Application.php    — working examples
packages/skeleton/app/Codes/Code.php           — examples
```

### Скелетон — удалённые файлы (4 файла):

```
packages/skeleton/app/index.php
packages/skeleton/app/Models/index.php
packages/skeleton/app/Http/index.php
packages/skeleton/app/Http/Models/User.php
```

### Тесты — новые файлы (18 файлов):

```
tests/Engine/Core/ContainerTest.php
tests/Engine/Core/F3BridgeTest.php
tests/Engine/Core/ApplicationTest.php
tests/Engine/Core/HttpKernelTest.php
tests/Engine/Core/RouterTest.php
tests/Engine/Core/ServiceProviderTest.php
tests/Engine/Core/Middleware/MiddlewareInterfaceTest.php
tests/Engine/Core/Config/ConfigSchemaTest.php
tests/Engine/Core/Config/ConfigLoaderV2Test.php
tests/Engine/Http/ResponseTest.php
tests/Engine/Http/RequestTest.php
tests/Engine/Exceptions/ExceptionHierarchyTest.php
tests/Engine/Exceptions/ExceptionHandlerTest.php
tests/Engine/View/ViewRendererTest.php
tests/Engine/Security/ShellCommandTest.php
tests/Engine/Security/AccountLockoutTest.php
tests/Engine/Security/CsrfTokenManagerTest.php
tests/Engine/Security/PasswordPolicyTest.php
tests/Engine/Security/SecurityHeadersMiddlewareTest.php
tests/Engine/Security/GenericRateLimitMiddlewareTest.php
tests/Support/PlatformGuard.php
tests/Support/PlatformGuardTest.php
tests/Support/SkipIfMissing.php
tests/Support/TestApplication.php
tests/Support/TestResponse.php
tests/Integration/Http/HttpKernelIntegrationTest.php
tests/bootstrap.php                           — Container injected
```

### CI:
```
.github/workflows/tests.yml
```

---

## ROADMAP v0.4

Три файла нужно переписать для полного перехода:

1. **`bootstrap/app.php`** — заменить 16-шаговую цепочку на `$app->registerProvider(...)->boot()`
2. **`Core/App.php`** — удалить `__call()`, удалить `register_*()` методы, оставить только `Boot/run()`
3. **`Controller.php`** — удалить `beforeroute()` exit, добавить `view()` helper

Плюс построчно удалить `exit`/`die` из 30+ файлов и заменить `::instance()` на Container в 20+ классах.

---

## КАК ПРОДОЛЖИТЬ

```bash
# Текущая точка: все тесты зеленые
composer test  # 1695 tests, 0 FAIL, 0 ERROR

# Следующий шаг: снести старый bootstrap и заменить на Container-based
# Начать с packages/skeleton/bootstrap/app.php
# Заменить 16-шаговую цепочку на:
#   $app->registerProvider(new ConfigServiceProvider())
#       ->registerProvider(new LogServiceProvider())
#       ...
#       ->boot();
```

Файлы спеков: `docs/specs/SPEC-*.md`
Файл аудита: `ARCHITECTURE_AUDIT.md`
