# Atomic Framework — Test Suite

## Quick Start

```bash
# 1. Create test database (one time)
# Using MySQL CLI:
mysql -u root -proot -h 127.0.0.1 -P 3306 -e "
  CREATE DATABASE IF NOT EXISTS atomic_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
  CREATE USER IF NOT EXISTS 'atomic_test_user'@'%' IDENTIFIED BY 'atomic_test_pass';
  GRANT ALL PRIVILEGES ON atomic_test.* TO 'atomic_test_user'@'%';
  FLUSH PRIVILEGES;
"

# 2. Run all tests
composer test
# Or:
php vendor/bin/phpunit --configuration tests/phpunit.xml

# 3. Run specific group
php vendor/bin/phpunit --filter "Auth" --configuration tests/phpunit.xml

# 4. Run single test file
php vendor/bin/phpunit tests/Engine/Core/CryptoTest.php --configuration tests/phpunit.xml
```

## Requirements

| Dependency | Required | Notes |
|-----------|----------|-------|
| PHP | >= 8.1 | Tested on 8.5.4 |
| `pdo_mysql` | **Yes** | Required for DB-backed tests |
| `mbstring` | **Yes** | Framework requirement |
| `curl` | **Yes** | Framework requirement |
| `fileinfo` | **Yes** | Framework requirement |
| `sodium` | **Yes** | Used by Crypto |
| MySQL 8.0+ | **Yes** | Running on `127.0.0.1:3306` |
| `redis` | No | Redis tests skip without it |
| `pcntl` | No | Worker/process tests skip on Windows |
| `posix` | No | Process tests skip on Windows |
| `memcached` | No | Memcached cache tests skip without it |
| `zip` | No | Filesystem zip tests skip without it |

## Database Setup

Test database: `atomic_test`  
Test user: `atomic_test_user` / `atomic_test_pass`  
Host: `127.0.0.1:3306`  
Charset: `utf8mb4` / `utf8mb4_general_ci`

Credentials are configured in three places (fallback chain):

| Priority | Source | Location |
|----------|--------|----------|
| 1 (highest) | Environment variables | `DB_HOST`, `DB_PORT`, `DB_DB`, `DB_USERNAME`, `DB_PASSWORD` |
| 2 | phpunit.xml `<php><env>` | `tests/phpunit.xml:26-31` |
| 3 | `.env` fixture | `tests/fixtures/.env:21-28` |
| 4 (lowest) | TestConfig defaults | `tests/Support/TestConfig.php:54-66` |

To override credentials, set environment variables before running:

```bash
# Windows PowerShell
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_DB = "atomic_test"
$env:DB_USERNAME = "atomic_test_user"
$env:DB_PASSWORD = "atomic_test_pass"
php vendor/bin/phpunit --configuration tests/phpunit.xml
```

**Root credentials** (`DB_ROOT_USERNAME` / `DB_ROOT_PASSWORD`) are also set in `tests/phpunit.xml` for tests that create databases — default `root` / `root`.

## Test Structure

```
tests/
├── phpunit.xml              # PHPUnit configuration
├── bootstrap.php            # Test bootstrap (creates base tables, sets up F3)
├── README.md                # This file
├── fixtures/
│   └── .env                 # Test environment fixture (204 keys)
├── Engine/                  # Test classes (mirrors engine/Atomic/)
│   ├── Core/                # Core layer tests (23 files)
│   ├── Auth/                # Auth tests (7 files)
│   ├── Queue/               # Queue tests (16 files)
│   ├── Cache/               # Cache tests (8 files)
│   ├── Session/             # Session tests (6 files)
│   ├── App/                 # App layer tests (4 files)
│   ├── CLI/                 # CLI tests (4 files)
│   ├── Validator/           # Validator tests (3 files)
│   ├── RateLimit/           # RateLimit tests (3 files)
│   ├── Enums/               # Enum tests (4 files)
│   ├── Hook/                # Hook tests (2 files)
│   ├── Theme/               # Theme tests (2 files)
│   ├── Scheduler/           # Scheduler tests (2 files)
│   ├── Lang/                # I18n tests (1 file)
│   ├── Mutex/               # Mutex tests (1 file)
│   ├── Files/               # Files tests (1 file)
│   ├── Tools/               # Tools tests (1 file)
│   ├── Telemetry/           # Telemetry tests (1 file)
│   ├── Codes/               # Codes tests (1 file)
│   ├── Event/               # Event tests (1 file)
│   ├── Exceptions/          # Exception tests (1 file)
│   └── Support/             # Helper tests (1 file)
└── Support/                 # Test infrastructure
    ├── TestConfig.php       # Centralized test configuration
    ├── ReflectionHelper.php # Access private/protected members
    ├── PassFailPrinter.php  # Custom PHPUnit output printer
    ├── Environment.php      # Env var manipulation for tests
    ├── TempPath.php         # Temporary directory helper
    ├── OutputCapture.php    # Output buffer capture
    ├── Wait.php             # Poll-until helper
    ├── CapturingSystem.php  # CLI output capture
    └── StreamCapture.php    # Stream capture helper
```

## Test Methodology

The suite uses a **split hybrid** approach:

- **Unit tests** — use mocked adapters and in-memory fakes (AuthServiceTest, RateLimiterTest, ValidatorTraitTest)
- **Integration tests** — use real MySQL/Redis connections (AuthIntegrationTest, SessionManagerDbTest, Queue tests)

Tests that require unavailable infrastructure (Redis, pcntl, Memcached) gracefully skip with a descriptive message.

## Known Platform Issues

### Windows

- **pcntl** and **posix** extensions are Linux-only. Worker, process, and monitor tests skip (21 tests).
- Queue monitor tests reference `SIGTERM`/`SIGUSR1` constants not available on Windows (6 errors).
- All skips use `--filter`-friendly markers like `requires-pcntl`.

### No Redis

- All Redis-backed tests skip (`requires-redis` marker). ~60 tests affected.
- Cache, session, queue, rate-limit, and mutex Redis driver tests all skip.

### No Memcached

- Memcached cache driver tests skip (~5 tests).

## Interpreting Results

```
PASS: 1216   # Tests that passed
FAIL: 9      # Tests that failed (bugs or environment issues)
ERROR: 21    # Tests that errored (pcntl/posix on Windows)
SKIP: 203    # Tests skipped (no Redis, no pcntl, no Memcached)
TOTAL: 1449
```

**Expected skips** on Windows without Redis/Memcached: ~200.  
**Expected errors** on Windows: 21 (all pcntl/posix-related).  
**Expected failures**: 0 (any FAIL indicates a real bug or config issue).

### Current Known Failures (June 2026)

| Test | Root Cause |
|------|-----------|
| `ConfigParityTest` (5 failures) | Boolean inconsistency between `.env` and PHP config loaders — `"false"` → `false` vs `(bool)"false"` → `true` |
| `ConfigLoaderTest::test_load_enables_f3_cache_bridge_for_folder_cache` | Cache bridge state mismatch |
| `SeederTest` (2 failures) | String mismatch in error messages |
| `PluginManagerTest::test_routes_registered_lifecycle_hook_receives_source_and_files` | Array comparison mismatch |

## Writing Tests

1. Place test files in `tests/Engine/<Module>/` mirroring `engine/Atomic/<Module>/`
2. Use namespace `Tests\Engine\<Module>`
3. Extend `PHPUnit\Framework\TestCase`
4. Use `Tests\Support\TestConfig` for configuration
5. Use `Tests\Support\ReflectionHelper` for accessing private members
6. Use `#[RunTestsInSeparateProcesses]` for tests that modify session/global state
7. Mark tests requiring specific extensions with group annotations

Example:

```php
<?php
declare(strict_types=1);

namespace Tests\Engine\MyModule;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestConfig;
use Tests\Support\ReflectionHelper;

#[Group('requires-mysql')]
class MyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension not loaded');
        }
        // ...
    }

    public function testSomething(): void
    {
        // Arrange
        // Act
        // Assert
    }
}
```

## Configuration Reference

### phpunit.xml Key Settings

```xml
<phpunit
    failOnRisky="true"           <!-- Fail on risky tests -->
    failOnIncomplete="true"      <!-- Fail on incomplete tests -->
    failOnWarning="true"         <!-- Fail on PHP warnings in tests -->
    displayDetailsOnTestsThatTriggerWarnings="true"
>
```

### Database Config Priority

```
Environment variable > phpunit.xml <env> > tests/fixtures/.env > TestConfig defaults
```

### Test Database Tables

Tables are auto-created by `tests/bootstrap.php`:
- `atomic_meta` — key-value metadata
- `atomic_options` — expirable key-value options

Additional tables (sessions, queue, auth) are created by individual test classes.

## Troubleshooting

### "Access denied for user"

Check MySQL credentials in `tests/phpunit.xml` or set environment variables. Run the setup SQL from the Quick Start section.

### "Unknown database 'atomic_test'"

Run the `CREATE DATABASE` command from the Quick Start section.

### All DB tests skip

Verify `pdo_mysql` is loaded: `php -m | grep pdo_mysql`

### All Redis tests skip

Install Redis extension or Redis server. Tests gracefully skip.

### pcntl_alarm() errors on Windows

Expected. Use `--exclude-group requires-pcntl` to skip these tests.
