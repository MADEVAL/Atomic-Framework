# TOTAL PRE-RELEASE AUDIT — v0.2.0

Ты — Lead Architect Atomic Framework. Проведи ТОТАЛЬНЫЙ предрелизный аудит.
Ни один файл не остаётся непроверенным. Каждое нарушение → в отчёт.

После этого аудита проект должен быть готов к тегу v0.2.0.

Выведи итоговый файл `CHECKPOINT.md` в корень репо со статусом каждого
инварианта (PASS / FAIL / DEFERRED / NOT-APPLICABLE) и списком найденных
проблем.

---

## ФАЗА 0 — ПОЛНАЯ ИНВЕНТАРИЗАЦИЯ

### 0.1 Составь КАРТУ КАЖДОГО ФАЙЛА

Для КАЖДОГО PHP-файла в репозитории выведи:
- Полный путь
- Пространство имён
- Класс / интерфейс / трейт / enum
- Все публичные методы с сигнатурами
- Все зависимости (use-пути)
- Все конфигурационные ключи, которые он читает
- Все хуки/события, которые он испускает или слушает

Обработай ВСЕ поддеревья:

```
engine/Atomic/
├── API/                    engine/Atomic/API/Api.php
├── App/                    Controller, Error, Model, Page, Plugin,
│   │                       Storage, System, Telemetry
│   └── Models/             Options, Meta, MutexLock
├── Auth/                   Auth, ConfigUser, ConfigUserProvider,
│   │                       ConfigUserStore, GoogleAuth, TelegramAuth
│   ├── Adapters/           AppContextAdapter, GoogleClientAdapter,
│   │                       IdValidatorAdapter, LogAdapter,
│   │                       MetaStorageAdapter, PhpSessionAdapter,
│   │                       SessionDriverFactoryAdapter,
│   │                       SessionManagerAdapter, SystemClockAdapter,
│   │                       TelegramClientAdapter, TransientCacheAdapter
│   ├── Interfaces/         AuthenticatableInterface, AuthSessionInterface,
│   │                       HasRolesInterface, LoginInterface,
│   │                       OAuthUserResolverInterface, UserProviderInterface
│   └── Services/           AuthService, AuthSessionService,
│                           GoogleAuthService, TelegramAuthService
├── Cache/                  FatFreeCacheBridge
│   ├── Drivers/            DB, Folder, Memcached, Redis
│   ├── Helpers/            Payload
│   └── Interfaces/         CacheStoreInterface, PrunableCacheStoreInterface,
│                           PurgeableCacheStoreInterface
├── CLI/                    Access, Capabilities, CLI, DB, File, Init,
│   │                       Migrations, Plugin, Queue, Scheduler, Seeder, Style
│   ├── Console/            Input, Output
│   └── Init/               InitInstaller, InitScaffold
├── Codes/                  Code
├── Core/                   App, Application, CacheManager, ConnectionManager,
│   │                       Container, Crypto, ErrorHandler,
│   │                       ExceptionHandlerRegistrar, F3Bridge, Filesystem,
│   │                       Guard, Hash, HttpKernel, ID, Log, LogChannel,
│   │                       Methods, Migrations, Prefly, Redactor, Request,
│   │                       Response, Route, RouteLoader, Router, Seeder,
│   │                       ServiceProvider, Upload
│   ├── Config/             ArrayValue, BoolValue, ConfigHiveTrait,
│   │   │                   ConfigLoader, ConfigRegistry, ConfigSchema,
│   │   │                   ConfigValue, CsvValue, EnumValue, FloatValue,
│   │   │                   IntValue, PathResolutionTrait, PhpConfigLoader,
│   │   │                   StringValue
│   │   └── V2/             Config, ConfigLoader
│   ├── Middleware/         AccessMiddleware, CsrfMiddleware,
│   │                       MiddlewareInterface, MiddlewareStack, RoleMiddleware
│   ├── Providers/          ServiceProviders (16 providers)
│   ├── Routes/             api, cli, telemetry, web, web.error
│   └── Traits/             Singleton
├── Enums/                  Currency, Language, LogChannel, LogLevel, Role, Rule
├── Event/                  Event, System
├── Exceptions/             AtomicException, AuthenticationException,
│                           ConfigurationException, ExceptionHandler,
│                           FileProcessingException, HttpException,
│                           ImportException, InsufficientStockException,
│                           NotFoundException, PaymentException,
│                           PluginDependencyException, ValidationException
├── Files/                  CSV, PDF, XLS (+ fonts/)
├── Hook/                   ApplicationHook, Hook, Shortcode, System
├── Http/                   Request, Response (новые иммутабельные)
├── Lang/                   I18n (+ locales/en, locales/ru)
├── Mail/                   Mailer, MailerUtils, Notifier
├── Mutex/                  DatabaseMutexDriver, FileMutexDriver,
│                           MemcachedMutexDriver, Mutex, MutexDriverInterface,
│                           RedisMutexDriver
├── Plugins/                GlobusStudio, Google, GoogleAnalytics,
│   │                       RssReader, WordPress, WooCommerce
│   ├── Monopay/            Api, Monopay, Order, WebhookHandler +
│   │                       Enums/, Migrations/, Models/, routes/
│   └── WebSockets/         Connection, RoutedWebSocketServer, Server,
│                           TestClient, WebSocketConnectMiddleware,
│                           WebSocketDispatcher, WebSocketMiddleware,
│                           WebSocketRouter, WebSockets + routes/, tests/
├── Queue/                  Applications/ (7 app), Drivers/ (DB, Redis),
│   │                       Enums/ (Driver, State), Exceptions/ (JobCancelled),
│   │                       Interfaces/ (Base, Management, Telemetry),
│   │                       Managers/ (Manager, ProcessManager, TelemetryManager),
│   │                       Monitor/ (Monitor + Adapters + ProcessProbe),
│   │                       Tests/ (TestJob), Worker/ (Worker)
├── RateLimit/              RateLimiter, RateLimitResult, RateLimitStoreInterface
│   ├── Drivers/            Redis + lua/
│   └── Middleware/         RateLimitMiddleware
├── Scheduler/              CronExpression, Event, Lister,
│                           ManagesFrequencies, Runner, Scheduler, Tester, Worker
│   └── Jobs/               LogCleanupJob
├── Security/               AccountLockout, CsrfTokenManager,
│                           PasswordPolicy, ShellCommand
│   └── Middleware/         GenericRateLimitMiddleware, SecurityHeadersMiddleware
├── Session/                RedisSessionTrait, Session, SessionManager,
│                           SqlSessionTrait
│   ├── Drivers/            DB, Redis
│   ├── Models/             Session
│   └── Services/           SessionService
├── Support/                helpers.php
├── Telemetry/Queue/        Entry, EventType + Adapters/ (DB, Redis)
├── Theme/                  Assets, Head, OpenGraph, Schema, Theme
├── Tools/                  AIConnector, Nonce, Telegram, Transient
├── Validator/              Validator, ValidatorModelTrait
│   └── PreValidation/      NullableEmptyToNullTrait
└── View/                   ViewRenderer

packages/skeleton/
├── app/
│   ├── Auth/               UserProvider
│   ├── Codes/              Code
│   ├── Event/              Application
│   ├── Hook/               Application
│   ├── Http/Controllers/   Home, Error, Dashboard, Auth, AuthController,
│   │   │                   Auth/Login, Auth/Register, Auth/Logout,
│   │   │                   Auth/PasswordReset, Auth/EmailVerification,
│   │   │                   Api/Health, Admin/Dashboard
│   │   └── Middleware/     Authenticate, EnsureEmailIsVerified,
│   │                       RedirectIfAuthenticated, RequireAdmin,
│   │                       RequireRole, ThrottleRequests, VerifyCsrfToken
│   └── Models/             User
├── bootstrap/              app.php, const.php, error.php
├── config/                 app, auth, cache, database, filesystems, i18n,
│                           logging, mail, middleware, providers, queue,
│                           rate_limiter, session, tools
├── routes/                 api, cli, schedule, web, web.error
├── database/               migrations/create_users_table
├── public/                 index.php + .htaccess
└── resources/              views/

tests/
├── Engine/                 (110+ test files, зеркалит engine/Atomic/)
├── Support/                PassFailPrinter, TestApplication, TestConfig,
│                           TestResponse, Environment, ReflectionHelper,
│                           SkipIfMissing, TempPath, Wait, PlatformGuard, etc.
├── Integration/            BootstrapTest, HttpKernelIntegrationTest
└── fixtures/               .env + config/*.php
```

### 0.2 Статистика

Выведи:
- Общее количество PHP-классов
- Общее количество интерфейсов
- Общее количество трейтов
- Общее количество enum
- Общее количество глобальных функций
- Общее количество тестовых файлов
- Общее количество тестовых методов
- Количество Lua-скриптов
- Количество route-файлов
- Количество middleware-классов
- Количество config-файлов
- Количество doc-файлов

---

## ФАЗА 1 — АРХИТЕКТУРНЫЕ ИНВАРИАНТЫ

### 1.1 BOOTSTRAP CHAIN

Файлы для проверки:
- `packages/skeleton/bootstrap/app.php`
- `engine/Atomic/Core/Application.php`
- `engine/Atomic/Core/App.php`
- `engine/Atomic/Core/ServiceProvider.php`
- `engine/Atomic/Core/Providers/ServiceProviders.php`

Инварианты:
1. `bootstrap/app.php` использует Provider-цепочку:
   `new Application($container)->registerProvider(...)->boot()`
   — это ЕДИНСТВЕННЫЙ способ bootstrap. Старая 16-шаговая
   fluent-цепочка `$app->prefly()->...->app_bootstrapped()`
   НЕ вызывается напрямую из bootstrap/app.php.
2. Provider-классы внутри `ServiceProviders.php`:
   ConfigServiceProvider → LogServiceProvider → ExceptionServiceProvider →
   PreflyServiceProvider → LocaleServiceProvider → UnloadServiceProvider →
   MiddlewareServiceProvider → CoreReadyServiceProvider →
   CorePluginServiceProvider → PluginServiceProvider →
   RouteServiceProvider → ScheduleServiceProvider →
   SessionServiceProvider → DatabaseServiceProvider →
   AuthServiceProvider → AppBootstrappedServiceProvider
3. `Container::setGlobal()` вызывается **до** `App::instance()` и
   **до** `new Application($container)`.
4. `App::instance()` проверяет `Container::global()->has(App::class)`
   перед созданием нового экземпляра (см. App.php:40-43).
5. `Singleton::instance()` проверяет `Container::global()->has(static::class)`
   перед `new static()` (см. Singleton.php:15-18).
6. `Application::boot()` вызывает `resolveOrder()` — topological sort
   на основе `ServiceProvider::requires()`. Если cycle detected —
   fallback к порядку регистрации.
7. Ни один Provider **не** вызывает `exit` напрямую.

### 1.2 CONFIG — ЕДИНСТВЕННЫЙ ИСТОЧНИК ИСТИНЫ

Файлы для проверки:
- `packages/skeleton/bootstrap/app.php:19-79` (ConfigSchema definitions)
- `packages/skeleton/.env.example` (все 121 строк)
- `packages/skeleton/config/*.php` (14 конфиг-файлов)
- `tests/fixtures/.env`
- `tests/fixtures/config/*.php`
- `engine/Atomic/Core/Config/ConfigSchema.php`
- `engine/Atomic/Core/Config/ConfigLoader.php`
- `engine/Atomic/Core/Config/PhpConfigLoader.php`
- `engine/Atomic/Core/Config/V2/ConfigLoader.php`
- `engine/Atomic/Core/Config/ConfigValue.php` + все подклассы
  (StringValue, IntValue, BoolValue, FloatValue, ArrayValue,
   CsvValue, EnumValue)

Инварианты:
1. **Каждый** ключ из `.env.example` имеет определение `ConfigSchema::*()`
   в `bootstrap/app.php`. Проверь ПОЛНЫЙ cross-reference:
   - APP_NAME, APP_KEY, APP_UUID, APP_ENCRYPTION_KEY
   - APP_URL (присутствует в ConfigSchema но НЕ в .env.example — проверь)
   - APP_TIMEZONE (присутствует в ConfigSchema но НЕ в .env.example — проверь)
   - DOMAIN, TZ, THEME, ENCODING, LANGUAGE
   - I18N_LANGUAGES, I18N_DEFAULT, I18N_URL_MODE, I18N_TTL,
     I18N_COOKIE, I18N_SESSION (присутствуют в .env но НЕ в ConfigSchema — проверь)
   - Все DB_*, MAIL_*, CACHE_*, SESSION_*, COOKIE_*
   - Все CORS_*, REDIS_*, MEMCACHED_*
   - QUEUE_*, MUTEX_*
   - SECURITY_HEADERS_ENABLED, SECURITY_HEADERS_XFO,
     SECURITY_HEADERS_HSTS, SECURITY_HEADERS_CSP
     (присутствуют в .env но НЕ в ConfigSchema — проверь)
   - TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID
     (присутствуют в .env но НЕ в ConfigSchema — проверь)
   - AI_OPENAI_API_KEY, AI_GROQ_API_KEY, AI_OPENROUTER_API_KEY,
     AI_GLOBUS_API_KEY
     (присутствуют в .env но НЕ в ConfigSchema — проверь)
   - UI, TEMP, LOGS, FONTS, FONTS_TEMP, MIGRATIONS, LOCALES,
     USER_PLUGINS, SEEDS, MIGRATIONS_CORE, FRAMEWORK_ROUTES
     (присутствуют в .env но НЕ в ConfigSchema — проверь)
   - CACHE_PATH, CACHE_SERVER, CACHE_PASSWORD, CACHE_LOGIN
     (присутствуют в .env но НЕ в ConfigSchema — проверь)
   - MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION
     (присутствуют в .env но НЕ в ConfigSchema — проверь)
   - DEBUG_MODE, DEBUG_LEVEL (есть в ConfigSchema ✓)
   - AUTH_RATE_LIMIT_MAX_ATTEMPTS, AUTH_RATE_LIMIT_WINDOW_SECONDS,
     AUTH_RATE_LIMIT_LOCKOUT_SECONDS
     (есть в ConfigSchema НО отсутствуют в .env.example — проверь)
2. Значения по умолчанию в `ConfigSchema` СОВПАДАЮТ со значениями в:
   - `.env.example` (для ключей, присутствующих в обоих)
   - `tests/fixtures/.env`
   - `tests/fixtures/config/*.php` (для php-режима)
   - `packages/skeleton/config/*.php`
3. **Ни один** ключ не читается через `getenv()` / `$_ENV` напрямую
   минуя `ConfigLoader` / `PhpConfigLoader`. Проверь grep-ом
   весь репозиторий.
4. `config/rate_limiter.php` **существует** и содержит полный набор
   параметров (max_attempts, window_seconds, lockout_seconds).
5. `ConfigLoader::parseEnvFile()` (старый) и
   `V2\ConfigLoader::parseEnvFile()` дают **идентичный** результат
   на одном и том же .env-файле. Запусти PARITY-тест если есть
   (`tests/Engine/Core/ConfigParityTest.php`).
6. `ConfigSchema::defaults()` возвращает корректные значения для
   всех required-ключей с дефолтами.

### 1.3 CONTAINER — ВСЕ СЕРВИСЫ УПРАВЛЯЮТСЯ ЧЕРЕЗ НЕГО

Файлы для проверки:
- `engine/Atomic/Core/Container.php`
- `engine/Atomic/Core/Traits/Singleton.php`
- `engine/Atomic/Core/Providers/ServiceProviders.php`
- `engine/Atomic/Core/App.php:38-51` (App::instance)

Инварианты:
1. Каждый фреймворк-сервис зарегистрирован в Container:
   - `\Base::class` → экземпляр F3
   - `App::class` → экземпляр App
   - `Hook::class` → фабрика `Hook::instance()`
   - `Event::class` → фабрика `Event::instance()`
   - `F3Bridge::class` → `new F3Bridge($atomic)`
   - `Router::class` → `new Router($container)`
   - `CacheManager::class` → фабрика `CacheManager::instance()`
   - `ConnectionManager::class` → фабрика `ConnectionManager::instance()`
   - `PluginManager::class` → фабрика `PluginManager::instance()`
   - `Auth::class` → фабрика `Auth::instance()`
2. **Singleton::instance()** проверяет `Container::global()` перед
   созданием нового экземпляра. Это работает для:
   - `Hook`, `Event`, `CacheManager`, `ConnectionManager`,
     `PluginManager`, `Auth` (используют Singleton trait)
   - **App НЕ использует Singleton trait** — у него свой `instance()`
     с контейнерной проверкой. Оба паттерна валидны.
3. Адаптеры (AppContextAdapter, PhpSessionAdapter, ...) создаются через
   `new` **только** внутри метода своего родительского класса.
   Проверь grep-ом что `new AppContextAdapter` не вызывается снаружи
   `AuthService` и т.д.
4. **Ни один** фреймворк-сервис (App, Hook, Event, Router, etc.)
   не создаётся через `new` внутри другого сервиса — только через
   `Container::get()` или `::instance()`.
5. `Container::make()` auto-wiring разрешает зависимости из контейнера
   и работает для типизированных параметров конструктора.

### 1.4 RESPONSE vs EXIT / DIE

Файлы для проверки:
- `engine/Atomic/Core/Response.php` (старый, с exit)
- `engine/Atomic/Http/Response.php` (новый, иммутабельный)
- `engine/Atomic/Core/App.php:57-140` (prefly с exit)
- `engine/Atomic/Core/App.php:403-437` (apply_cors с exit на OPTIONS)
- `engine/Atomic/Core/App.php:498-509` (App::die)
- `engine/Atomic/Core/ExceptionHandlerRegistrar.php`
- `engine/Atomic/Core/HttpKernel.php`
- `engine/Atomic/Core/Application.php`
- Все middleware-классы (17 штук)
- Все controller-классы в skeleton

Инварианты:
1. **Ни один** middleware не вызывает `exit` / `die` напрямую.
   Проверь grep-ом `\bexit\b` и `\bdie\b` во всех middleware-файлах.
2. **Ни один** web-контроллер не вызывает `exit` / `die` напрямую.
   Проверь grep-ом все `app/Http/Controllers/**/*.php`.
3. **Только** CLI-команды вызывают `exit` с кодом возврата.
4. `ExceptionHandler::handle()` возвращает `Response`, не вызывает `die`.
5. `Core\Response::send_json_*` вызывает `exit` внутри — это допустимо
   для старого паттерна (параметр `$terminate=true` по умолчанию,
   можно передать `false`).
6. Новый `Http\Response` — иммутабельный, без `exit`. Метод `send()`
   вызывает `echo` и `header()`, но НЕ `exit`.
7. `App::apply_cors()` вызывает `exit` на OPTIONS — допустимо
   (preflight-обработка, до запуска F3 pipeline).
8. `App::prefly()` вызывает `exit(1)` или `exit(0)` при ошибках —
   допустимо (bootstrap-level, нет инфраструктуры Response).
9. `App::run()` НЕ вызывает `exit` напрямую (делегирует F3 `$atomic->run()`).

### 1.5 MIDDLEWARE PIPELINE

Файлы для проверки:
- `engine/Atomic/Core/Middleware/MiddlewareInterface.php`
- `engine/Atomic/Core/Middleware/MiddlewareStack.php`
- `engine/Atomic/Core/HttpKernel.php`
- Все middleware-реализации:
  - `engine/Atomic/Core/Middleware/AccessMiddleware.php`
  - `engine/Atomic/Core/Middleware/CsrfMiddleware.php`
  - `engine/Atomic/Core/Middleware/RoleMiddleware.php`
  - `engine/Atomic/RateLimit/Middleware/RateLimitMiddleware.php`
  - `engine/Atomic/Security/Middleware/GenericRateLimitMiddleware.php`
  - `engine/Atomic/Security/Middleware/SecurityHeadersMiddleware.php`
  - `engine/Atomic/Plugins/WebSockets/WebSocketMiddleware.php`
  - `engine/Atomic/Plugins/WebSockets/WebSocketConnectMiddleware.php`
  - `packages/skeleton/app/Http/Middleware/Authenticate.php`
  - `packages/skeleton/app/Http/Middleware/EnsureEmailIsVerified.php`
  - `packages/skeleton/app/Http/Middleware/RedirectIfAuthenticated.php`
  - `packages/skeleton/app/Http/Middleware/RequireAdmin.php`
  - `packages/skeleton/app/Http/Middleware/RequireRole.php`
  - `packages/skeleton/app/Http/Middleware/ThrottleRequests.php`
  - `packages/skeleton/app/Http/Middleware/VerifyCsrfToken.php`

Инварианты:
1. `MiddlewareInterface` содержит ОБА метода:
   - `handle(\Base $atomic): bool` (deprecated)
   - `process(mixed $request, callable $next): Http\Response` (новый)
2. `HttpKernel::handle()` определяет, какой метод вызвать:
   - Если `process()` реально реализован в middleware (не из интерфейса
     и не содержит "Not yet implemented" / "Not yet migrated") →
     использует `process()`
   - Иначе → использует `handle()`
3. **ВСЕ** middleware-классы, которые наследник Interfac'а, реализуют
   `process()` без `throw`. Проверь, что ни один `process()` не
   содержит `throw new \RuntimeException('Not yet implemented')`.
4. `MiddlewareStack::run_for_route()` вызывает `handle()` —
   старый паттерн, F3-совместимый.
5. Ни `process()` ни `handle()` не вызывают `exit`.

### 1.6 ROUTING

Файлы для проверки:
- Все route-файлы (13 штук):
  - `engine/Atomic/Core/Routes/*.php` (5)
  - `packages/skeleton/routes/*.php` (6)
  - `engine/Atomic/Plugins/*/routes/*.php` (2)
- `engine/Atomic/Core/App.php:334-361` (App::route)
- `engine/Atomic/Core/App.php:270-285` (detect_request_type)
- `engine/Atomic/Core/App.php:167-172` (register_routes)
- `engine/Atomic/Core/App.php:230-268` (load_app_routes, load_plugin_routes)
- `engine/Atomic/Core/RouteLoader.php`
- `engine/Atomic/Core/Router.php`
- `engine/Atomic/Core/Route.php`

Инварианты:
1. **ВСЕ** route-файлы используют `$this->route()` (не `$atomic->route()`),
   где `$this` — это экземпляр `App` (установлен как `$atomic = $this`
   в `load_routes_for()`).
2. `App::route()` регистрирует middleware в `MiddlewareStack::for_route()`,
   затем делегирует `$this->atomic->route()`.
3. `detect_request_type()`:
   - CLI → `'cli'`
   - Первый URL-сегмент `api` → `'api'`
   - Первый URL-сегмент `telemetry` → `'telemetry'`
   - Всё остальное → `'web'`
4. `Router::detectRequestType()` (новый Router, `engine/Atomic/Core/Router.php`)
   даёт тот же результат что и `App::detect_request_type()`.
5. Route loading order:
   - `activate_route_type()` → определяет active types
   - `load_app_routes()` → framework routes (`load_routes_for`) + app routes
   - `load_plugin_routes()` → plugin routes через PluginManager
6. Файлы `.error.php` загружаются для web-типа (через RouteLoader).

### 1.7 CSRF И БЕЗОПАСНОСТЬ

Файлы для проверки:
- `engine/Atomic/Security/CsrfTokenManager.php`
- `engine/Atomic/Core/Middleware/CsrfMiddleware.php`
- `engine/Atomic/Security/PasswordPolicy.php`
- `engine/Atomic/Security/AccountLockout.php`
- `engine/Atomic/Security/ShellCommand.php`
- `engine/Atomic/Core/Hash.php`
- `packages/skeleton/app/Http/Controllers/Auth/LoginController.php`
- `packages/skeleton/app/Http/Controllers/Auth/RegisterController.php`
- `packages/skeleton/app/Http/Controllers/Auth/PasswordResetController.php`

Инварианты:
1. `CsrfTokenManager::token()` генерирует токен при первом запросе
   (НЕ `return true` при отсутствии).
2. `CsrfTokenManager::validate()` использует `hash_equals()`.
3. Rate limiting применяется к `/login` и `/register` через
   middleware `throttle` + `ratelimit`.
4. User enumeration невозможен:
   - LoginController: **"Invalid credentials"** всегда (не
     "User not found" vs "Wrong password").
   - RegisterController: generic message всегда.
   - PasswordResetController: generic message всегда.
5. `Hash::dummy_timing_mitigation()` использует **тот же алгоритм**
   что и `Hash::password()`: `PASSWORD_DEFAULT` (bcrypt / argon2id).
6. `AccountLockout`: блокировка после N неудачных попыток.
   Конфиг: AUTH_RATE_LIMIT_MAX_ATTEMPTS, AUTH_RATE_LIMIT_LOCKOUT_SECONDS.
7. `PasswordPolicy`: валидация сложности пароля (минимальная длина,
   энтропия, специальные символы).
8. `ShellCommand::__construct()` экранирует аргументы.

### 1.8 АУТЕНТИФИКАЦИЯ

Файлы для проверки:
- `engine/Atomic/Auth/Auth.php`
- `engine/Atomic/Auth/ConfigUserProvider.php`
- `engine/Atomic/Auth/ConfigUser.php`
- `engine/Atomic/Auth/ConfigUserStore.php`
- `engine/Atomic/Auth/GoogleAuth.php`
- `engine/Atomic/Auth/TelegramAuth.php`
- `engine/Atomic/Auth/Services/AuthService.php`
- `engine/Atomic/Auth/Services/AuthSessionService.php`
- `engine/Atomic/Auth/Services/GoogleAuthService.php`
- `engine/Atomic/Auth/Services/TelegramAuthService.php`
- ВСЕ 10 адаптеров в `Auth/Adapters/`
- ВСЕ 6 интерфейсов в `Auth/Interfaces/`

Инварианты:
1. `Auth::instance()` управляется через Container (зарегистрирован в
   AuthServiceProvider).
2. `ConfigUserProvider` реализует `UserProviderInterface` с методами
   `find_by_credentials()` и `find_by_id()`.
3. `AuthService::login()` создаёт сессию через `AuthSessionService`.
4. `AuthSessionService` привязывает сессию к IP + User-Agent.
5. Регенерация session ID при `login()`.
6. Logout очищает **только** auth-ключи сессии (НЕ все данные).
7. Имперсонация: admin-only, audit trail, регенерация сессии.
8. OAuth `state` верифицируется через `hash_equals()` (GoogleAuth,
   TelegramAuth).
9. `ConfigUserStore` — файловое хранилище для dev/test — работает
   корректно.

### 1.9 БАЗА ДАННЫХ

Файлы для проверки:
- `engine/Atomic/Core/ConnectionManager.php`
- `engine/Atomic/Core/Migrations.php`
- `engine/Atomic/Core/Seeder.php`
- `engine/Atomic/Core/Database/Migrations/*.php` (4 миграции)
- `packages/skeleton/database/migrations/create_users_table.php`
- Все Model-классы
- `engine/Atomic/Session/SqlSessionTrait.php`
- `engine/Atomic/Queue/Drivers/DB.php`

Инварианты:
1. `ConnectionManager::open_all()` вызывается в DatabaseServiceProvider.
2. Все соединения: ленивые (lazy), health-check, reconnect.
3. **PDO prepared statements везде**. Проверь grep-ом на паттерн
   `->query\(.*\$` и `->exec\(.*\$` без плейсхолдеров.
4. `DB_PREFIX` применяется консистентно во всех SQL-запросах.
5. Миграции: timestamp-based, batch tracking, rollback.
6. `Seeder` работает через Model API (не сырой SQL).

### 1.10 КЭШИРОВАНИЕ

Файлы для проверки:
- `engine/Atomic/Core/CacheManager.php`
- `engine/Atomic/Cache/FatFreeCacheBridge.php`
- `engine/Atomic/Cache/Drivers/*.php` (4 драйвера)
- `engine/Atomic/Cache/Interfaces/*.php` (3 интерфейса)
- `engine/Atomic/Cache/Helpers/Payload.php`
- `engine/Atomic/Tools/Transient.php`

Инварианты:
1. `CacheManager::cascade()` приоритет:
   Redis → Memcached → DB → Folder.
2. `Transient` использует `CacheManager`, не `\Cache::instance()` напрямую.
3. `FatFreeCacheBridge` установлен до использования `\Cache`.
4. TTL: `>0` истекает, `0` не истекает, `<0` → приводится к `0`.
5. Кэш-драйверы реализуют `CacheStoreInterface`.
6. `PrunableCacheStoreInterface` реализован где нужно.
7. `PurgeableCacheStoreInterface` реализован где нужно.

---

## ФАЗА 2 — ПОЛНЫЙ АУДИТ ПОДСИСТЕМ

### 2.1 PLUGINS

Файлы для проверки:
- `engine/Atomic/App/PluginManager.php`
- `engine/Atomic/App/Plugin.php` (базовый класс)
- Каждый plugin-файл (GlobusStudio, Google, GoogleAnalytics,
  RssReader, WordPress, WooCommerce, Monopay, WebSockets)

Инварианты:
1. Плагины **НЕ** auto-discovered — только из `config/providers.php`.
2. `PluginManager`: `register()` → `boot()` порядок соблюдён.
3. `plugin/vendor/autoload.php` загружается если существует.
4. `plugin/composer.json` без autoload.php → warning в лог.
5. Plugin-зависимости проверяются: `Plugin::required_dependencies()`.
6. Циклические зависимости обнаружены и залогированы.
7. Plugin-роуты загружаются **после** app-роутов.
8. Plugin-миграции публикуются корректно.
9. Monopay plugin: webhook handler, payment statuses,
   `MonopayHook` enum, `PaymentStatus` enum, `Payment` model,
   `PaymentHistory` model.
10. WebSockets plugin: `WebSocketMiddleware` vs HTTP Middleware
    разделены. `WebSocketConnectMiddleware` — connect-level.
    `WebSocketDispatcher` — message routing.
    `RoutedWebSocketServer` — server-level.

### 2.2 QUEUE

Файлы для проверки:
- `engine/Atomic/Queue/Managers/Manager.php`
- `engine/Atomic/Queue/Drivers/DB.php`
- `engine/Atomic/Queue/Drivers/Redis.php`
- `engine/Atomic/Queue/Worker/Worker.php`
- `engine/Atomic/Queue/Monitor/Monitor.php`
- `engine/Atomic/Queue/Managers/ProcessManager.php`
- `engine/Atomic/Queue/Managers/TelemetryManager.php`
- `engine/Atomic/Queue/Enums/Driver.php`, `State.php`
- `engine/Atomic/Queue/Exceptions/JobCancelledException.php`
- `engine/Atomic/Queue/Interfaces/*.php` (Base, Management, Telemetry)
- `engine/Atomic/Queue/Tests/TestJob.php`
- ВСЕ 17 Lua-скриптов в `Queue/Drivers/lua/`

Инварианты:
1. `Manager::push()` валидирует handler class + method.
2. DB driver: row-level locks (`FOR UPDATE`), batch processing.
3. Redis driver: Lua-скрипты для атомарных операций.
4. Worker: graceful shutdown (SIGTERM), signal handling,
   таймауты, memory limit.
5. Monitor: проверка stuck-джобов, PosixProcessProbe.
6. Telemetry: события при create / fetch / complete / fail / cancel.
7. `JobCancelledException`: cooperative cancellation.
8. Retry: экспоненциальная задержка, `max_attempts`.
9. Job payload **сериализуемый** (без замыканий, без ресурсов).
10. `ProcessManager`: управление дочерними процессами.
11. `TelemetryManager`: запись событий телеметрии в БД/Redis.

### 2.3 SCHEDULER

Файлы для проверки:
- `engine/Atomic/Scheduler/CronExpression.php`
- `engine/Atomic/Scheduler/Scheduler.php`
- `engine/Atomic/Scheduler/Event.php`
- `engine/Atomic/Scheduler/Runner.php`
- `engine/Atomic/Scheduler/Worker.php`
- `engine/Atomic/Scheduler/Lister.php`
- `engine/Atomic/Scheduler/Tester.php`
- `engine/Atomic/Scheduler/ManagesFrequencies.php`
- `engine/Atomic/Scheduler/Jobs/LogCleanupJob.php`

Инварианты:
1. `CronExpression`: полный парсер 5 полей (minute, hour, day,
   month, weekday). Поддерживает `*`, `*/N`, ranges, lists.
2. `Scheduler::due_events()` фильтрует по времени.
3. `Event::without_overlapping()` использует `Mutex`.
4. `Event::run_in_maintenance_mode()` опционально.
5. `Runner::run_due_tasks()` возвращает структурированный ответ.
6. `Scheduler\Worker`: долгоживущий цикл (`schedule/work`).
7. Таймаут защиты: 300 секунд (настраивается).
8. `MAINTENANCE_MODE` и `APP_ENV` учитываются.

### 2.4 EVENTS & HOOKS

Файлы для проверки:
- `engine/Atomic/Event/Event.php`, `engine/Atomic/Event/System.php`
- `engine/Atomic/Hook/Hook.php`, `engine/Atomic/Hook/System.php`
- `engine/Atomic/Hook/ApplicationHook.php` (enum)
- `engine/Atomic/Hook/Shortcode.php`
- `packages/skeleton/app/Event/Application.php`
- `packages/skeleton/app/Hook/Application.php`

Инварианты:
1. `Event::on()` / `emit()`: иерархические, с приоритетами.
2. `Event::watch()` / `unwatch()`: object-scoped события.
3. `Hook::add_action()` / `do_action()`: WordPress-совместимые.
4. `Hook::add_filter()` / `apply_filters()`: WordPress-совместимые.
5. `Shortcode::add()` / `Shortcode::do()`: работают.
6. Все константы хуков в `ApplicationHook` enum:
   - `CONFIG_LOADED` — передаёт App + loader name
   - `PREFLY_FAILED` — передаёт App + failed + checks
   - `CORE_READY` — после middleware registration
   - `ROUTES_REGISTERED` — вызывается ДВАЖДЫ (app + plugin)
   - `PLUGINS_LOADED` — передаёт App + PluginManager
   - `APP_BOOTSTRAPPED` — финальный хук
   - `BEFORE_SERVER_START` — вызывается один раз
7. `app/Event/Application.php::init()` вызывает `Event::on()` для
   всех фреймворк-событий.
8. `app/Hook/Application.php::init()` вызывает `Hook::add_action()`
   для всех фреймворк-хуков.

### 2.5 ЛОГИРОВАНИЕ

Файлы для проверки:
- `engine/Atomic/Core/Log.php`
- `engine/Atomic/Core/LogChannel.php`
- `engine/Atomic/Core/Redactor.php`
- `engine/Atomic/Enums/LogLevel.php`
- `engine/Atomic/Enums/LogChannel.php` (enum)

Инварианты:
1. `Log::init()` вызывается в `LogServiceProvider`.
2. `Log::channel()` возвращает именованный канал.
3. `Redactor::redact()` вызывается на ВСЕХ log-сообщениях.
4. `Redactor` маскирует: пароли, токены, ключи, сессии,
   DSN, IP, OAuth-токены, cookie-значения.
5. `Redactor` НЕ вызывается при чтении (`telemetry fetch_*`).
6. Log-файлы ротируются (`max_days`).
7. Dump-файлы создаются только при `debug_mode`.
8. Чувствительные заголовки маскируются (Authorization, Cookie,
   Set-Cookie, X-CSRF-Token).

### 2.6 I18N

Файлы для проверки:
- `engine/Atomic/Lang/I18n.php`
- `engine/Atomic/Lang/locales/en.php`
- `engine/Atomic/Lang/locales/ru.php`
- `config/i18n.php`

Инварианты:
1. Detection priority:
   URL prefix → GET param → Cookie → Session →
   Accept-Language header → default.
2. `I18n::t()` / `I18n::tn()` / `I18n::tx()` работают.
3. `I18n::url()` генерирует локализованные URL.
4. `hreflang` генерируется автоматически.
5. Кэширование переводов (TTL настраивается: `I18N_TTL`).
6. Переводы загружаются из `locales/*.php`.

### 2.7 SESSION

Файлы для проверки:
- `engine/Atomic/Session/Session.php`
- `engine/Atomic/Session/SessionManager.php`
- `engine/Atomic/Session/SqlSessionTrait.php`
- `engine/Atomic/Session/RedisSessionTrait.php`
- `engine/Atomic/Session/Drivers/DB.php`
- `engine/Atomic/Session/Drivers/Redis.php`
- `engine/Atomic/Session/Models/Session.php`
- `engine/Atomic/Session/Services/SessionService.php`
- `engine/Atomic/Auth/Services/AuthSessionService.php`

Инварианты:
1. `Session::init()` НЕ стартует сессию для CLI.
2. Session cookie name из конфига (`SESSION_COOKIE`).
3. SQL driver: таблица `sessions`, поля корректны.
4. Redis driver: префикс из `REDIS.prefix`.
5. IP + User-Agent binding опционально (`SESSION_KILL_ON_SUSPECT`).
6. `AuthSessionService`: работает только с auth-ключами.
7. Logout НЕ очищает не-auth данные сессии.

### 2.8 THEME

Файлы для проверки:
- `engine/Atomic/Theme/Theme.php`
- `engine/Atomic/Theme/Assets.php`
- `engine/Atomic/Theme/Head.php`
- `engine/Atomic/Theme/OpenGraph.php`
- `engine/Atomic/Theme/Schema.php`

Инварианты:
1. `Theme::instance()` загружает `theme.json`.
2. `Theme::include()` защищает от path traversal.
3. `Assets`: enqueue + render.
4. `Head`: favicon, title, iconset, manifest, preconnect,
   preload, analytics, schema.
5. `OpenGraph`: генерация meta-тегов.
6. `Schema`: структурированные данные (JSON-LD).
7. Theme-функции доступны как глобальные хелперы.
8. `PAGE.color` из `theme.json` или fallback.

### 2.9 MAIL

Файлы для проверки:
- `engine/Atomic/Mail/Mailer.php`
- `engine/Atomic/Mail/MailerUtils.php`
- `engine/Atomic/Mail/Notifier.php`

Инварианты:
1. `Mailer`: SMTP с multipart/alternative.
2. `MailerUtils`: DNS проверка (SPF / DKIM / DMARC).
3. `Notifier`: шаблонные уведомления.
4. Конфигурация `MAIL_*` загружается корректно:
   `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
   `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.

### 2.10 FILES

Файлы для проверки:
- `engine/Atomic/Files/CSV.php`
- `engine/Atomic/Files/PDF.php`
- `engine/Atomic/Files/XLS.php`
- `engine/Atomic/Core/Upload.php`

Инварианты:
1. CSV: парсинг + генерация + `render_csv` (exit в render —
   допустимо для file download).
2. PDF: генерация с embedded шрифтами (DejaVu Sans).
3. XLS: парсинг BIFF / OLE (`.xls` только, НЕ `.xlsx`).
4. Upload: валидация + перемещение, защита от path traversal.

### 2.11 VALIDATOR

Файлы для проверки:
- `engine/Atomic/Validator/Validator.php`
- `engine/Atomic/Validator/ValidatorModelTrait.php`
- `engine/Atomic/Validator/PreValidation/NullableEmptyToNullTrait.php`
- `engine/Atomic/Enums/Rule.php`

Инварианты:
1. `Validator`: 15+ правил (email, uuid, regex, password...).
2. `ValidatorModelTrait`: интеграция с Model.
3. `NullableEmptyToNullTrait`: пре-валидация (пустые строки → null).
4. PasswordEntropy: расчёт энтропии пароля.
5. `Rule` enum содержит константы для всех правил.

### 2.12 MUTEX

Файлы для проверки:
- `engine/Atomic/Mutex/Mutex.php`
- `engine/Atomic/Mutex/MutexDriverInterface.php`
- `engine/Atomic/Mutex/DatabaseMutexDriver.php`
- `engine/Atomic/Mutex/FileMutexDriver.php`
- `engine/Atomic/Mutex/MemcachedMutexDriver.php`
- `engine/Atomic/Mutex/RedisMutexDriver.php`

Инварианты:
1. Все драйверы реализуют `MutexDriverInterface`.
2. `Mutex` используется в Scheduler для `without_overlapping()`.
3. Redis-драйвер: TTL-based lock.
4. Database-драйвер: таблица `mutex_locks` через модель `MutexLock`.
5. File-драйвер: файловые локи в `storage/framework/cache/`.

### 2.13 TELEMETRY

Файлы для проверки:
- `engine/Atomic/Telemetry/Queue/Entry.php`
- `engine/Atomic/Telemetry/Queue/EventType.php`
- `engine/Atomic/Telemetry/Queue/Adapters/DB.php`
- `engine/Atomic/Telemetry/Queue/Adapters/Redis.php`
- `engine/Atomic/App/Telemetry.php` (доступ к telemetry-данным)
- `engine/Atomic/Core/Routes/telemetry.php`

Инварианты:
1. Режимы доступа: none, config, auth.
2. `TELEMETRY_ACCESS_ALLOWED_ROLES`: CSV ролей.
3. Queue view: пагинация, фильтры, totals.
4. Log viewer: каналы, даты, пагинация, live stat.
5. Dump viewer: JSON по UUID.
6. Dashboard: PHP version, framework version, DB, debug.
7. Hive inspector: санитизированный.
8. ВСЕ данные санитизируются через `Redactor`.

### 2.14 ENUMS

Файлы для проверки:
- `engine/Atomic/Enums/Role.php`
- `engine/Atomic/Enums/Rule.php`
- `engine/Atomic/Enums/Language.php`
- `engine/Atomic/Enums/Currency.php`
- `engine/Atomic/Enums/LogLevel.php`
- `engine/Atomic/Enums/LogChannel.php`

Инварианты:
1. `Role`: ADMIN (и другие роли если есть), бэкинговые значения.
2. `Rule`: EMAIL, UUID_V4, PASSWORD_ENTROPY, все правила валидации.
3. `Language`: все поддерживаемые языки.
4. `Currency`: все поддерживаемые валюты.
5. `LogLevel`: debug, info, warning, error, critical, emergency.
6. `LogChannel`: atomic, error, auth, queue_worker, etc.
7. Все enum используются с `Guard::has_role()`.

### 2.15 SECURITY (ДЕТАЛЬНО)

Файлы для проверки (детализация поверх 1.7):
- `engine/Atomic/Core/Crypto.php`
- `engine/Atomic/Core/Hash.php`
- `engine/Atomic/Security/PasswordPolicy.php`
- `engine/Atomic/Security/AccountLockout.php`
- `engine/Atomic/Security/ShellCommand.php`
- `engine/Atomic/Security/Middleware/GenericRateLimitMiddleware.php`
- `engine/Atomic/Security/Middleware/SecurityHeadersMiddleware.php`
- `engine/Atomic/RateLimit/RateLimiter.php`
- `engine/Atomic/RateLimit/RateLimitMiddleware.php`

Инварианты:
1. `Crypto`: `sodium_crypto_secretbox`, encrypt / decrypt.
2. `Crypto::generate_key()` создаёт валидный ключ.
3. `APP_ENCRYPTION_KEY` обязателен для Crypto.
4. `Hash`: `bcrypt`/`argon2id` через `password_hash`.
5. `hash_equals()` для всех timing-sensitive сравнений.
6. `APP_KEY` обязателен.
7. `SecurityHeadersMiddleware` применяет заголовки безопасности.
8. `GenericRateLimitMiddleware` — fail-open конфигурация.

### 2.16 APP / CORE SUPPORT CLASSES

Файлы для проверки:
- `engine/Atomic/App/System.php` — системная информация
- `engine/Atomic/App/Storage.php` — файловое хранилище
- `engine/Atomic/App/Page.php` — базовый класс страницы
- `engine/Atomic/App/Telemetry.php` — доступ к телеметрии
- `engine/Atomic/App/Models/Options.php` — хранилище опций
- `engine/Atomic/App/Models/Meta.php` — мета-данные
- `engine/Atomic/App/Models/MutexLock.php` — модель блокировок
- `engine/Atomic/Core/ID.php` — генерация ID
- `engine/Atomic/Core/Methods.php` — утилитарные методы
- `engine/Atomic/Core/Filesystem.php` — файловая система
- `engine/Atomic/Core/Guard.php` — guard / проверка ролей
- `engine/Atomic/Core/ErrorHandler.php` — error handler
- `engine/Atomic/Core/ExceptionHandlerRegistrar.php` — регистрация exception handler
- `engine/Atomic/Core/Prefly.php` — preflight checks

Инварианты:
1. `System::info()` возвращает PHP version, framework version,
   memory usage, server info.
2. `Storage` использует конфигурацию `filesystems.php`.
3. `ID::generate()` создаёт уникальные идентификаторы.
4. `Methods` содержит вспомогательные статические методы.
5. `Filesystem` — абстракция над файловой системой.
6. `Guard::has_role()` проверяет роль через enum.
7. `ErrorHandler` корректно обрабатывает все уровни ошибок.
8. `ExceptionHandlerRegistrar::register()` устанавливает
   exception handler для F3.
9. `Prefly` проверяет: PHP version, extensions (json, session,
   mbstring, fileinfo, pdo, pdo_mysql, curl), writable dirs.

### 2.17 API MODULE

Файлы для проверки:
- `engine/Atomic/API/Api.php`

Инварианты:
1. `Api` класс предоставляет базовую функциональность REST API.
2. Все методы возвращают структурированный JSON.

### 2.18 CODES MODULE

Файлы для проверки:
- `engine/Atomic/Codes/Code.php`
- `packages/skeleton/app/Codes/Code.php`

Инварианты:
1. `Code` содержит HTTP status codes + бизнес-коды.
2. Skeleton `Code` расширяет/переопределяет фреймворк-коды.

### 2.19 VIEW

Файлы для проверки:
- `engine/Atomic/View/ViewRenderer.php`

Инварианты:
1. `ViewRenderer` рендерит шаблоны с передачей переменных.
2. Поддерживает layout / partials.
3. Корректно обрабатывает путь к шаблонам.

### 2.20 TOOLS

Файлы для проверки:
- `engine/Atomic/Tools/Nonce.php`
- `engine/Atomic/Tools/Transient.php`
- `engine/Atomic/Tools/AIConnector.php`
- `engine/Atomic/Tools/Telegram.php`

Инварианты:
1. `Nonce`: 32 hex chars, одноразовый, привязан к IP + UA.
2. `Transient`: `set_transient()`, `get_transient()`,
   `delete_transient()` — WordPress-совместимые.
3. `AIConnector`: подключение к OpenAI / Groq / OpenRouter
   через конфиг `AI_*_API_KEY`.
4. `Telegram`: отправка сообщений через Telegram Bot API.

---

## ФАЗА 3 — КОНСИСТЕНТНОСТЬ ИМЁН И ПУТЕЙ

### 3.1 КЛАССЫ

Инварианты:
1. Каждое имя класса в `docs/*.md` СУЩЕСТВУЕТ в коде.
   Проверь grep-ом все doc-ссылки на классы.
2. Каждый `use`-путь в `packages/skeleton/` указывает на
   существующий класс.
3. Каждый `use`-путь в `engine/Atomic/` указывает на
   существующий класс.
4. Нет классов в namespace `V2`, которые должны заменить
   оригиналы, но не делают этого.
5. `V2/Config.php` и `V2/ConfigLoader.php` — это новые
   реализации, сосуществующие со старыми `ConfigLoader.php`.
   Проверь, что оба рабочих и не конфликтуют.

### 3.2 РОУТЫ → КОНТРОЛЛЕРЫ

Инварианты:
1. Каждый route в `routes/*.php` ссылается на существующий
   контроллер с существующим методом.
   Проверь grep-ом паттерны `ControllerName->method` и
   `ControllerName::method` во всех route-файлах.
2. Namespace контроллеров корректен.
3. Проверь frameworks routes: `FRAMEWORK_ROUTES` → controller mapping.

### 3.3 MIDDLEWARE ALIASES

Инварианты:
1. Каждый alias в `config/middleware.php` указывает на
   существующий класс, реализующий `MiddlewareInterface`.
2. Встроенные алиасы: `access`, `role`, `csrf`, `ratelimit`,
   `security` — все указывают на валидные классы.
3. **Ни один** alias не дублируется между встроенными
   (App::register_middleware) и `config/middleware.php`.

### 3.4 КОНФИГУРАЦИОННЫЕ КЛЮЧИ

Инварианты:
1. Каждый ключ в `.env.example` имеет definition в
   `ConfigSchema` (в `bootstrap/app.php`). Исключение:
   явно определённые как опциональные через ConfigLoader
   без Schema.
2. Каждый ключ в `config/*.php` имеет соответствующий
   ключ в `.env.example` или явно документирован как
   php-only.
3. Значения по умолчанию совпадают во всех трёх
   источниках: ConfigSchema, .env.example, config/*.php.

### 3.5 ФАЙЛОВЫЕ ГВАРДЫ

Инварианты:
1. **Каждый** файл в `engine/Atomic/` начинается с:
   `declare(strict_types=1);`
   `if (!defined('ATOMIC_START')) exit;`
   Проверь grep-ом **все 175+ PHP-файлов**.
2. **Каждый** файл в `packages/skeleton/`, который подключается
   напрямую, начинается с `declare(strict_types=1);` и
   `if (!defined('ATOMIC_START')) exit;`.
3. Исключение: entry-point файлы (`public/index.php`,
   `bootstrap/const.php`) не имеют гварда, так как
   они определяют `ATOMIC_START`.

---

## ФАЗА 4 — ГЛОБАЛЬНОЕ ПРОСТРАНСТВО И WP-LIKE МЕТОДЫ

### 4.1 ГЛОБАЛЬНЫЕ ФУНКЦИИ

Файлы для проверки:
- `engine/Atomic/Support/helpers.php`
- `engine/Atomic/Plugins/Monopay/global_functions.php`
- Любые другие файлы, определяющие функции в глобальном namespace

Инварианты:
1. Составь **ПОЛНЫЙ** список всех функций в глобальном пространстве имён.
2. Каждая функция должна иметь docblock.
3. Не должно быть конфликтов имён с PHP built-in.
4. Не должно быть конфликтов имён с WordPress.
5. Все deprecated-функции помечены `@deprecated`.

### 4.2 WP-LIKE ХУКИ

Инварианты:
1. `Hook::add_action()` → совместим с WordPress по сигнатуре.
2. `Hook::add_filter()` → совместим с WordPress по сигнатуре.
3. `Hook::do_action()` → совместим с WordPress по сигнатуре.
4. `Hook::apply_filters()` → совместим с WordPress по сигнатуре.
5. `Shortcode::add()` / `Shortcode::do()` → работают.

### 4.3 WP-LIKE ФУНКЦИИ

Инварианты:
1. `Transient`: `set_transient()`, `get_transient()`,
   `delete_transient()` → WordPress-совместимые.
2. `Nonce`: `create_nonce()`, `verify_nonce()` →
   WordPress-совместимые.
3. Theme-функции: `get_head()`, `get_footer()`,
   `get_sidebar()`, `get_section()` → работают.

---

## ФАЗА 5 — ТЕСТЫ (ПРОВЕРИТЬ КАЖДЫЙ)

### 5.1 ПОКРЫТИЕ

Инварианты:
1. Каждый класс в `engine/Atomic/` имеет тест в `tests/Engine/`.
   Проверь mapping:
   - `engine/Atomic/Core/Foo.php` → `tests/Engine/Core/FooTest.php`
   - Составь список классов **без тестов**.
2. Каждый публичный метод имеет хотя бы один тест.
   Составь таблицу покрытия методов.
3. `tests/Integration/` покрывает cross-package сценарии.

### 5.2 КОНВЕНЦИИ

Инварианты:
1. Все test-методы используют `snake_case`
   (`test_<what>_<expected_behavior>`).
2. `assertSame` предпочтительнее `assertEquals`
   (строгая проверка типов).
3. Платформенно-зависимые тесты (pcntl, Redis, Memcached) используют
   `markTestSkipped()` или `#[RequiresPhpExtension]` — никогда не ERROR.
4. Каждый тестовый класс `extends TestCase` (без кастомного
   базового класса).

### 5.3 КАЧЕСТВО ТЕСТОВ

Инварианты:
1. Каждый тест имеет как минимум один `assert` (нет тестов без проверок).
2. Тесты не зависят от порядка выполнения.
3. Тесты чистят за собой (`tearDown()` сбрасывает синглтоны).
4. Нет `sleep()` в тестах (использовать моки или real-time).
5. Внешние сервисы замоканы (нет реальных HTTP-запросов).
6. База данных: `atomic_test`, изолированные данные.

---

## ФАЗА 6 — БЕЗОПАСНОСТЬ

### 6.1 ИНЪЕКЦИИ

Инварианты:
1. **SQL**: только PDO prepared statements. Проверь grep-ом:
   - `->query\(` и `->exec\(` без плейсхолдеров
   - Строковая интерполяция в SQL (`"SELECT * FROM $table"`)
   - Конкатенация SQL (`'SELECT * FROM ' . $table`)
2. **XSS**: `htmlspecialchars()` при выводе пользовательских данных.
   Проверь все View / echo / print.
3. **Shell**: `ShellCommand` экранирует аргументы через
   `escapeshellarg()`.
4. **Path traversal**: `Theme::include()`, `Upload`, `Filesystem` —
   проверка на `..` и абсолютные пути.

### 6.2 КРИПТОГРАФИЯ

Инварианты:
1. `Crypto`: `sodium_crypto_secretbox` (не mcrypt, не openssl вручную).
2. `Nonce`: 32 hex chars, одноразовый, привязан к IP + UA.
3. `Hash`: `bcrypt` / `argon2id` через `password_hash`
   (НЕ свой велосипед).
4. `hash_equals()` для всех timing-sensitive сравнений.
5. `APP_KEY`, `APP_ENCRYPTION_KEY` — обязательные, с валидацией.

### 6.3 СЕССИИ

Инварианты:
1. Session cookie: `HttpOnly`, `Secure` (в production),
   `SameSite=Lax`.
2. Session ID регенерируется при `login`.
3. Session ID регенерируется при `impersonation`.
4. Session привязана к IP + User-Agent (опционально,
   `SESSION_KILL_ON_SUSPECT`).

### 6.4 CSRF

Инварианты:
1. Токен генерируется для каждой сессии.
2. Проверка через `hash_equals()`.
3. Токен в заголовке `X-CSRF-Token` или в теле запроса.
4. GET / HEAD / OPTIONS — safe methods (пропускаются без проверки).

### 6.5 RATE LIMITING

Инварианты:
1. Двойной лимит: IP-based + credential-based.
2. Fail-open конфигурация (не блокирует при отказе хранилища).
3. Заголовки: `X-RateLimit-Limit`, `X-RateLimit-Remaining`,
   `Retry-After`.

### 6.6 SENSITIVE DATA

Инварианты:
1. Ни один пароль / токен / ключ не пишется в лог без
   маскирования.
2. `Redactor::redact()` вызывается на всех логах.
3. В `.env.example` нет реальных секретов.
4. В тестах нет хардкоженных продакшен-секретов.
5. `phpunit.xml.dist` не содержит реальных секретов.

---

## ФАЗА 7 — УТЕЧКИ И МЁРТВЫЙ КОД

### 7.1 МЁРТВЫЙ КОД

Инварианты:
1. Все классы используются (нет неподключённых файлов).
2. Все методы вызываются (нет неиспользуемых public методов
   кроме `__call` / `__get` / `__toString`).
3. Нет закомментированных блоков кода (TODO допустимы).
4. Нет дублирующихся классов (два класса с одинаковым
   именем в разных неймспейсах, делающих одно и то же).

### 7.2 УТЕЧКИ АБСТРАКЦИЙ

Инварианты:
1. Ни один класс вне `engine/Atomic/` не ссылается на
   внутренние классы `engine/Atomic/` напрямую (только
   через публичное API).
2. Ни один класс пакета skeleton не обращается к
   `$_ENV` / `$_SERVER` напрямую (только через Config / Request).
3. Ни один внешний код не вызывает `\Base` напрямую без
   `F3Bridge`.

### 7.3 ЦИКЛИЧЕСКИЕ ЗАВИСИМОСТИ

Инварианты:
1. Нет циклических `use`-путей.
2. Нет циклических dependency injection.
3. `Application::resolveOrder()` разрешает topological sort
   через `requires()` — нет циклических зависимостей между
   ServiceProvider'ами.

---

## ФАЗА 8 — ДОКУМЕНТАЦИЯ

### 8.1 ДОКУМЕНТАЦИЯ АКТУАЛЬНА

Файлы для проверки:
- `docs/*.md` (50+ файлов)
- `docs/plugins/*.md`
- `docs/specs/*.md`
- `docs/audit/*.md`
- `README.md`
- `AGENTS.md`
- `docs/testing_guide.md`

Инварианты:
1. Каждый `docs/*.md` соответствует актуальному коду.
2. `README.md`: все примеры кода работают.
3. `AGENTS.md`: все утверждения верны (проверь каждое).
4. `testing_guide.md`: команды запуска актуальны
   (`composer test`, `composer test-fw`, `composer test-integration`).

### 8.2 КОД САМОДОКУМЕНТИРУЕМЫЙ

Инварианты:
1. Все публичные методы имеют docblock с `@param` и `@return`.
2. Все исключения задокументированы (`@throws`).
3. Все интерфейсы имеют описание контракта.
4. Все трейты имеют описание назначения.

---

## ФАЗА 9 — ФИНАЛЬНАЯ ВЕРИФИКАЦИЯ

### 9.1 Запуск тестов

```bash
composer test
```

Инварианты:
- `0 FAIL`, `0 ERROR`
- Все skipped — только платформенно-зависимые
- Время прохождения < 120 секунд
- Нет `E_WARNING` / `E_NOTICE` / `E_DEPRECATED` в выводе
- Пиковое потребление памяти < 128MB

### 9.2 Composer audit

```bash
composer validate
composer audit
```

Инварианты:
- `composer.json` валиден
- Нет известных уязвимостей в зависимостях

### 9.3 Структурная целостность

Проверь bash-командами:
1. Все `*.php` файлы синтаксически корректны:
   ```bash
   find . -name "*.php" -exec php -l {} \;
   ```
2. Все `require` / `include` пути разрешимы (realpath check).
3. Нет битых симлинков.
4. Нет `.DS_Store` / `Thumbs.db` мусора в репозитории.

### 9.4 CHECKPOINT.md

Создай `CHECKPOINT.md` в корне репо со **СТРУКТУРИРОВАННЫМ**
отчётом:

```markdown
# Pre-Release Audit — v0.2.0

## Summary
- Total invariants checked: NNN
- PASS: NNN
- FAIL: NNN
- SKIP: NNN (platform-dependent)
- DEFERRED: NNN (needs design discussion)

## Phase 0: Inventory
...

## Phase 1: Architectural Invariants
### 1.1 Bootstrap Chain
- [PASS] Provider chain is the only bootstrap
- [PASS] Container::setGlobal before App::instance
- [FAIL] App::instance does not use Singleton trait (expected)
- ...

### ...

## Issues Found
1. [CRITICAL] ...
2. [HIGH] ...
3. [MEDIUM] ...
4. [LOW] ...

## Recommendations for v0.2.0
...

## Recommendations for v0.3.0
...
```

### 9.5 Финальный вердикт

Вынеси вердикт: **READY / NOT-READY** для тега v0.2.0.
Если NOT-READY — перечисли блокирующие проблемы.
Если READY — перечисли рекомендации на будущее.

---

## СПЕЦИФИЧНЫЕ ПРОВЕРКИ ДЛЯ КАЖДОГО ФАЙЛА

### Файлы с гвардами
- `engine/Atomic/index.php`
- `engine/Atomic/*/index.php` (все подпапки)
- `packages/skeleton/bootstrap/index.php`
- `packages/skeleton/routes/index.php`
- `packages/skeleton/config/index.php`
- `packages/skeleton/database/index.php`
- `packages/skeleton/database/migrations/index.php`
- `packages/skeleton/database/seeds/index.php`
- `packages/skeleton/storage/framework/index.php`

Проверь: каждый `index.php` содержит только `if (!defined('ATOMIC_START')) exit;`
(или `declare` + guard).

### Lua-скрипты (25 штук)
- `engine/Atomic/Cache/Drivers/lua/*.lua`
- `engine/Atomic/Queue/Drivers/lua/*.lua`
- `engine/Atomic/RateLimit/Drivers/lua/*.lua`

Проверь: каждый скрипт корректен синтаксически, не содержит
хардкоженных ключей, idempotent.

### .htaccess файлы
- `packages/skeleton/public/.htaccess`
- `engine/Atomic/Queue/Drivers/lua/.htaccess`
- `packages/skeleton/storage/**/.gitkeep`

Проверь: корректные Apache-директивы, deny для Lua-директории.

### .gitignore
- `engine/Atomic/Plugins/WebSockets/.gitignore`

Проверь: исключены ли артефакты сборки.

---

## ИТОГОВЫЙ ОТЧЁТ

По завершении всех фаз выведи единый файл `CHECKPOINT.md`.
Формат: таблицы для каждой фазы, статус каждого инварианта,
список проблем по severity.

Затем выведи краткое резюме на экран: количество нарушений
по severity, вердикт готовности к релизу.

Не пиши код. Только проверяй. Каждое нарушение — в отчёт в файл в корень репозитория.
