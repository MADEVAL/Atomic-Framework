## Testing Guide

Atomic uses PHPUnit 10.x.

### Running the suite

From the project root:

```bash
vendor/bin/phpunit -c tests/phpunit.xml
```

In environments where result-cache writes are undesirable:

```bash
vendor/bin/phpunit -c tests/phpunit.xml --do-not-cache-result
```

Run one file:

```bash
vendor/bin/phpunit -c tests/phpunit.xml tests/Engine/Core/CryptoTest.php
```

Run one test method:

```bash
vendor/bin/phpunit -c tests/phpunit.xml --filter test_encrypt_decrypt_roundtrip
```

Optional text coverage when Xdebug or PCOV is available:

```bash
vendor/bin/phpunit -c tests/phpunit.xml --coverage-text
```

### Local service setup

The full suite expects local MySQL, Redis, and Memcached services to be reachable with the defaults from `tests/phpunit.xml`.

Create the dedicated MySQL test user and database:

```bash
mysql -u root -p
```

Then run:

```sql
CREATE USER IF NOT EXISTS 'atomic_test_user'@'localhost' IDENTIFIED BY 'atomic_test_pass';
CREATE USER IF NOT EXISTS 'atomic_test_user'@'127.0.0.1' IDENTIFIED BY 'atomic_test_pass';
CREATE DATABASE IF NOT EXISTS atomic_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON atomic_test.* TO 'atomic_test_user'@'localhost';
GRANT ALL PRIVILEGES ON atomic_test.* TO 'atomic_test_user'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Quick connectivity checks:

```bash
mysql -u atomic_test_user -patomic_test_pass -D atomic_test -e "SELECT CURRENT_USER(), DATABASE();"
redis-cli ping
echo stats | nc 127.0.0.1 11211
```

### PHPUnit configuration

`tests/phpunit.xml` currently sets:

- `bootstrap="bootstrap.php"`
- `cacheDirectory=".phpunit.cache"`
- `colors="true"`
- `failOnRisky="true"`
- `failOnIncomplete="true"`
- `failOnWarning="true"`
- `displayDetailsOnTestsThatTriggerWarnings="true"`

The configured test suite is:

- `Engine` -> `tests/Engine`

The configured source include path is:

- `engine/Atomic`

### Bootstrap behavior

`tests/bootstrap.php` does more than autoloading. It:

- resolves the framework root from `tests/..`
- defines the core `ATOMIC_*` constants used by the framework during tests
- loads Composer autoloading and framework helpers
- creates temporary `LOGS` and `TEMP` directories under the system temp directory
- seeds `REDIS` and `MEMCACHED` hive config with test defaults
- seeds `DB_CONFIG` from PHPUnit environment defaults
- connects the shared `DB` handle used by DB-backed tests
- ensures the DB-backed cache tables exist in `atomic_test`
- enables debug mode with:
  - `DEBUG_MODE=true`
  - `DEBUG_LEVEL=debug`
  - `DEBUG=3`
- sets:
  - `HALT=false`
  - `QUIET=true`

### Environment values from PHPUnit

`tests/phpunit.xml` provides default DB environment values:

- `DB_HOST=127.0.0.1`
- `DB_PORT=3306`
- `DB_DB=atomic_test`
- `DB_USERNAME=atomic_test_user`
- `DB_PASSWORD=atomic_test_pass`

Override them in CI or your shell when your database test environment differs.

### Custom extension

PHPUnit is bootstrapped with the custom extension:

- `Tests\Support\PassFailPrinter`

That changes console output formatting only; it does not replace PHPUnit's core execution flow.

### What the current test suite covers

Examples present in this repository:

- core utilities such as `Crypto`, `Guard`, `Prefly`, `Request`, `Response`, `RouteLoader`, and `Sanitizer`
- plugin manager behavior
- queue telemetry manager filtering
- theme assets and schema helpers
- nonce behavior

Notes:

- `CryptoTest` skips itself if the Sodium extension is unavailable.
- Several tests use reflection to reset singletons between cases.
- Many subsystem tests are unit-level and avoid external services by mocking or by seeding hive config only.

### Practical local guidance

1. Run with `-c tests/phpunit.xml` so bootstrap and suite selection stay aligned with the repository.
2. Expect temp logs and temp files to be created under the OS temp directory during tests.
3. Use the dedicated MySQL test account from `tests/phpunit.xml`, or override `DB_HOST`, `DB_PORT`, `DB_DB`, `DB_USERNAME`, and `DB_PASSWORD` in your shell or CI.
4. If you are working in a read-only or ephemeral environment, use `--do-not-cache-result`.
