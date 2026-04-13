## Security ##

This guide summarizes the main security-related primitives implemented in the framework.

Related docs:

- `nonce.md`
- `request.md`
- `errorhandler.md`

### Authentication and authorization

Role checks are implemented by `Engine\Atomic\Core\Guard`.

Available methods:

- `Guard::is_authenticated()`
- `Guard::is_guest()`
- `Guard::has_role($role)`
- `Guard::has_any_role($roles)`
- `Guard::lacks_role($role)`
- `Guard::lacks_any_role($roles)`

Important behavior:

- `has_role(...)` and `has_any_role(...)` only work when the current user implements `HasRolesInterface`.
- Role values can be strings or backed enums.
- Enum roles are compared by their backed value.
- When there is no authenticated user, `has_*` checks return `false` and `lacks_*` checks return `true`.

Example:

```php
use Engine\Atomic\Core\Guard;
use Engine\Atomic\Enums\Role;

if (!Guard::has_role(Role::ADMIN)) {
    \Base::instance()->error(403, 'Forbidden');
    return;
}
```

### Nonce protection

Nonce tokens are provided by `Engine\Atomic\Tools\Nonce`.

```php
use Engine\Atomic\Tools\Nonce;

$nonce = Nonce::instance();
$token = $nonce->create_nonce('delete-post', 1800);

if (!$nonce->verify_nonce($_POST['nonce'] ?? '', 'delete-post')) {
    \Base::instance()->error(403, 'Invalid nonce');
}
```

Current behavior:

- `create_nonce(...)` returns a 32-character hex token.
- Token state is stored under an internal hive key based on action and token.
- Validation is bound to the current `IP` and `AGENT`.
- Verification is one-time. The token is cleared after a verification attempt that finds stored nonce data.
- Using a different action, IP, or user agent makes verification fail.

### Encryption

`Engine\Atomic\Core\Crypto` uses `sodium_crypto_secretbox`.

Requirements:

- the Sodium extension must be available
- `APP_ENCRYPTION_KEY` must be configured
- that key must decode to exactly `SODIUM_CRYPTO_SECRETBOX_KEYBYTES`

Example:

```php
use Engine\Atomic\Core\Crypto;

$crypto = new Crypto();
$cipher = $crypto->encrypt('sensitive payload');
$plain  = $crypto->decrypt((string)$cipher);
```

Key generation:

```php
$key = \Engine\Atomic\Core\Crypto::generate_key();
```

Current failure behavior:

- `encrypt('')` returns `false`
- `decrypt('')` returns `false`
- invalid base64 input returns `false`
- tampered ciphertext returns `false`
- missing or invalid `APP_ENCRYPTION_KEY` throws `RuntimeException` during construction

### Diagnostic sanitization

`Engine\Atomic\Core\Sanitizer` is used for diagnostic output such as telemetry, logs, and dumps.

It does the following:

- masks values under sensitive keys like password, token, cookie, session, and similar patterns
- masks sensitive token-like substrings inside plain strings
- replaces the configured home/root path with `[HOME]`
- normalizes arrays, objects, resources, and nested values into safe output structures

Important limitation:

- `Sanitizer` is not an HTML escaping library and is not a replacement for request validation or output encoding.

### Error surface

Error handling is centralized through the framework error handler.

Relevant behavior:

- API and AJAX requests return JSON error payloads
- stack traces are only included when debug is enabled
- hive dumps are only written when logger debug mode is enabled
- telemetry endpoints expose logs and dumps only if you route requests there, and access restriction depends on `TELEMETRY_ADMIN_ONLY`

See `errorhandler.md` for the exact request flow.

### Practical checklist

1. Use `Guard` before privileged actions.
2. Require a nonce for state-changing operations exposed through the browser.
3. Keep `APP_ENCRYPTION_KEY` valid and secret.
4. Do normal input validation in addition to any sanitizer usage.
5. Keep debug disabled in production unless you explicitly need it.
6. Restrict telemetry with `TELEMETRY_ADMIN_ONLY` if diagnostic endpoints should not be public.
