## Prefly ##

Prefly is Atomic's environment preflight checker.

### What `Prefly` checks directly

`Engine\Atomic\Core\Prefly` validates:

- PHP version `>= 8.1.0`
- required extensions:
  - `json`
  - `session`
  - `mbstring`
  - `fileinfo`
  - `pdo`
  - `pdo_mysql`
  - `curl`

`Prefly` itself does not perform filesystem checks.

### API

Available methods:

- `check_environment(): array`
- `all_checks_passed(): bool`
- `is_php_version_compatible(string $required): bool`
- `is_extension_loaded(string $ext): bool`
- `is_function_available(string $function): bool`
- `is_class_available(string $class): bool`

Example result structure:

```php
[
  'php_version' => [
    'required' => '8.1.0',
    'current'  => '8.3.6',
    'status'   => true,
  ],
  'extensions' => [
    'json' => ['required' => true, 'status' => true],
    'pdo_mysql' => ['required' => true, 'status' => true],
  ],
]
```

### App integration

`App::prefly()` wraps `Prefly` and adds the actual startup behavior.

When environment checks fail:

- in CLI mode:
  - prints a `System Error` block
  - lists the missing requirements
  - exits with code `1`
- in web mode:
  - returns HTTP `500`
  - prints a simple error page
  - shows the detailed missing requirements only when `DEBUG_MODE` is truthy
  - exits immediately

### Additional filesystem checks in `App::prefly()`

For non-CLI requests only, `App::prefly()` also checks:

- `storage/` exists and is writable
- `storage/logs/` exists and is writable

If either check fails:

- HTTP status `503` is returned
- a service-unavailable page is shown
- when `DEBUG_MODE` is truthy, the page includes suggested `chown` and `chmod` commands

### Notes

1. The required extension list is aligned with current Composer `ext-*` requirements.
2. `Prefly` does not cover every optional subsystem dependency. For example, `Crypto` still requires the Sodium extension separately.
3. Use `App::prefly()` early in application boot so startup fails before partial initialization.
