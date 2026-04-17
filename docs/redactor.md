# Redactor

## What it is

`Redactor` is a static utility class that scrubs sensitive data before it is written to logs, stored in the database, or surfaced in the telemetry UI. It is the single, authoritative point of data sanitization in the framework - nothing should reach persistent storage or a UI without passing through it first.

## When it is used

Redactor is called at **write time**, not read time. It has two consumers: the Log system (primary) and the queue drivers (secondary).

### Log system - primary usage

`Log::init()` calls `Redactor::init_from_hive()` at boot, then Redactor is invoked on every single write through two paths:

| Log.php call site | Method | What it sanitizes |
|---|---|---|
| `write_to_channel()` | `redact_string($message)` | Every log line before it hits the file |
| `dump_to_json()` | `redact($payload)` | Full hive dumps and `Log::dump()` JSON files |

This is why Log is the primary consumer: **100% of log output passes through Redactor**. A log message that contains a password, token, or absolute path will never reach the log file in its original form. Hive dumps - which capture the entire F3 hive including environment variables - are especially sensitive, and `redact()` traverses the full nested structure before the JSON is written to disk.

The reason this is done at write time rather than output time is that log files are the most likely artifact to be copied out of a server (shared with support, committed by mistake, included in a bug report). Sanitizing at the source means there is no safe/unsafe version of the file to worry about - there is only one file and it is always clean.

### Queue drivers - secondary usage

| Location | Trigger |
|---|---|
| Queue DB driver | Before a failed job's exception payload is written to the database |
| Queue Redis driver | Before a failed job's exception payload is passed to the Lua `mark_finished` script |

Because sanitization happens before storage, telemetry adapters and the dashboard UI receive already-clean data and need no further processing.

## How it works

Redactor applies three independent layers of sanitization:

### 1. Sensitive-key masking (arrays and objects)

When `redact()` traverses an array or object, any key that matches one of the built-in sensitive-key patterns has its scalar value replaced with `[MASKED]`. Nested arrays/objects under a sensitive key are still traversed so that non-sensitive child fields remain readable.

Covered key categories: passwords, secrets, tokens, API keys, auth headers, session/cookie identifiers, database DSNs, encryption keys, HMAC/nonce/signature fields, IP address fields, and OAuth identifiers.

### 2. Sensitive-value pattern replacement (strings)

`redact_string()` applies a set of regex substitutions to any string value. It catches credential patterns that appear inline in serialized data or log lines:

- `Authorization: Bearer <token>` → `Authorization: Bearer [MASKED]`
- `Basic <base64>` → `Basic [MASKED]`
- URL credentials `scheme://user:pass@host` → `scheme://user:[MASKED]@host`
- Inline `key=value` and JSON `"key":"value"` pairs for known sensitive param names

### 3. Home-path scrubbing

Absolute file paths that contain the server's home directory (e.g. `/home/deploy/app/...`) are replaced with `[HOME]/...`. This prevents server layout information from leaking into stored exception traces.

## Public API

```php
// Initialize home-path scrubbing from the F3 hive (call once at boot).
Redactor::init_from_hive(\Base $atomic): void

// Or set the home path directly.
Redactor::set_home_path(string $path): void

// Check whether a home path has been configured.
Redactor::is_configured(): bool

// Redact a mixed value (array, object, string, scalar).
// Returns the same structure with sensitive data replaced.
Redactor::redact(mixed $data, int $depth = 0, int $max_depth = 6, int $max_items = 1000): mixed

// Redact a single string value.
Redactor::redact_string(string $value): string

// Check whether a key name is considered sensitive.
Redactor::is_sensitive_key(string|int $key): bool
```

`Redactor::MASKED` (`'[MASKED]'`) is the public constant used as the replacement value everywhere.

## What you should do

**Use `Log::*` methods for all application logging.** Because every `Log::write_to_channel()` call passes through `Redactor::redact_string()` automatically, you get sanitization for free. Do not bypass Log and write to a file directly - you lose redaction.

**Use `Log::dump()` for structured debug data.** It calls `Redactor::redact()` on the full payload before writing JSON to disk. Never write raw `json_encode($data)` to a log file yourself.

**If you add a new data-writing path outside of Log** (a custom queue driver, an audit trail, a webhook sink), wrap the payload with `Redactor::redact($data)` before storage. The queue drivers do this for job failure payloads - follow the same pattern.

**Call `init_from_hive()` once at bootstrap** (or `set_home_path()` if you are outside the F3 context). The framework calls this inside `Log::init()`, so if Log is initialized you are already covered.

**Do not call `redact()` at read time.** Applying it to data already in the database or in a UI payload is redundant and can corrupt legitimate display values. Sanitize at the source.

## What you should not do

- Do not add Redactor calls inside telemetry adapter `fetch_*` methods. Sanitization is upstream of storage; the adapters only read already-clean data.
- Do not extend the sensitive-key list in application code unless the framework's built-in list genuinely misses a domain-specific field. Prefer a PR to `SENSITIVE_KEYS` in the framework itself so coverage is consistent everywhere.
- Do not rely on `redact_string()` alone for structured payloads. Use `redact()` so that array keys are also checked, not just string values.

## Limits

| Limit | Default | Description |
|---|---|---|
| `$max_depth` | 6 | Maximum nesting depth before truncation (`[[max_depth]]`) |
| `$max_items` | 1000 | Maximum array entries before truncation (`[[truncated]]`) |

Both can be overridden as arguments to `redact()` if a specific call site needs different bounds.
