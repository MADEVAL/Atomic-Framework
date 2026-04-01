# Mutex - Distributed Locking System

The Mutex system provides overlap-safe distributed locking with atomic acquisition and token-verified release. It's designed to prevent concurrent execution of scheduled tasks and protect against race conditions.

## Quick Start

```php
use Engine\Atomic\Mutex\Mutex;

// Acquire a lock with 5 minute TTL
$token = Mutex::acquire('my-task', 300);

if ($token === null) {
    // Lock is held by another process - skip execution
    return;
}

try {
    // Do critical work...
} finally {
    Mutex::release('my-task', $token);
}
```

## API Reference

### `Mutex::acquire(string $name, int $ttl): ?string`

Attempt to acquire a lock atomically.

- **$name**: Unique lock name (e.g., 'send-newsletter', 'process-orders'). Names must match `/^[A-Za-z0-9:._-]{1,128}$/` (allowed characters: letters, digits, :, ., _, -; max length 128).
- **$ttl**: Time-to-live in seconds (REQUIRED, must be > 0)
- **Returns**: Token string on success, `null` if lock is already held

### `Mutex::release(string $name, string $token): bool`

Release a lock only if the token matches.

- **$name**: Lock name
- **$token**: Token returned by `acquire()`
- **Returns**: `true` if released, `false` if token didn't match or lock doesn't exist

### `Mutex::exists(string $name): bool`

Check if a lock is currently held.

### `Mutex::force_release(string $name): bool`

Force-remove a lock regardless of token. **Use with caution** - for administrative cleanup only. Note: method names use snake_case (e.g. `force_release`).

### `Mutex::synchronized(string $name, int $ttl, callable $callback, ?callable $onLocked = null): mixed`

Convenience method that handles acquire/release automatically.

```php
$result = Mutex::synchronized('my-task', 300, function() {
    // Do work...
    return 'done';
}, function() {
    // Called if lock was already held
    return 'skipped';
});
```

Other helper methods:

- `Mutex::get_driver_name(): ?string` - returns the selected driver name (e.g. `'redis'`, `'database'`), or `null` if none.
- `Mutex::info(): array` - returns `['driver' => string|null, 'initialized' => bool, 'available' => bool]`.
- `Mutex::reset(): void` and `Mutex::set_driver(MutexDriverInterface $driver): void` - helpers for tests and dependency injection.

## Configuration

Configure the `MUTEX` settings to choose a specific backend (recommended):

```php
// In config or bootstrap
$atomic->set('MUTEX', ['driver' => 'redis']); // options: 'redis', 'memcached', 'database', 'file'
```

If `MUTEX.driver` is not set, the system auto-selects the first available backend in this priority order. You can also set the default via environment variable `MUTEX_DRIVER`.

1. **redis**
2. **memcached**
3. **database**
4. **file**

Once a driver is selected at startup, it remains fixed for the lifetime of the request. You can inspect the current driver with `Mutex::get_driver_name()` or `Mutex::info()`.

## Backend Drivers

### Redis (Recommended)

Uses `SET key token NX EX ttl` for atomic acquisition and Lua script for safe release.

**Requirements:**
- PHP Redis extension
- Redis server configured in `REDIS` config

**Behavior:**
- Atomic acquisition: `SET NX EX`
- Atomic release: Lua script deletes only if token matches
- Best for distributed/clustered deployments

### Memcached

Uses `add()` for atomic acquisition (fails if key exists).

**Requirements:**
- PHP Memcached extension
- Memcached server configured in `MEMCACHED` config

**Behavior:**
- Atomic acquisition: `add()` fails if key exists
- Best-effort release: compare token then delete (not fully atomic)
- Good for distributed deployments

### Database

Uses an INSERT/DELETE strategy (with a `mutex_locks` table) for atomic acquisition and cleanup.

**Requirements:**
- Database connection configured
- Migration: `database/migrations/atomic/atomic_create_mutex_table.php` (migration name: `atomic_create_mutex_table`)

**Behavior:**
- Atomic acquisition: `INSERT IGNORE` on a UNIQUE name column after deleting expired rows
- Safe release: `DELETE WHERE name = ? AND token = ?`
- Automatic cleanup of expired locks during acquire
- Good for distributed deployments without Redis/Memcached

**Run migration:**
```bash
php atomic migrate:run
```

### File (Last Resort)

Uses lock **directories** under `{TEMP}/mutex/` and stores a `meta.json` file containing the `token`, `expires_at` and `created_at` fields.

**Requirements:**
- Writable directory at `{TEMP}/mutex/` (config `TEMP` or system temp)

**Behavior:**
- Atomic acquisition: attempts to create a lock directory; creation fails if it already exists
- Token and expiry are stored in `meta.json`
- If an existing lock is expired, the driver performs an atomic takeover by renaming the stale directory and creating a fresh lock
- Expired locks are cleaned up as part of takeover or when `force_release`/`release` remove the directory

> ⚠️ **WARNING**: File driver is LOCAL-ONLY. It will NOT work in distributed/clustered environments where multiple servers need to coordinate. Use `redis` or `db` drivers for multi-server deployments.

> ⚠️ **WARNING**: File driver is LOCAL-ONLY. It will NOT work in distributed/clustered environments where multiple servers need to coordinate. Use Redis or Database drivers for multi-server deployments.

## Scheduler Integration

The Scheduler uses Mutex for `without_overlapping()` to prevent concurrent task execution:

```php
// In routes/schedule.php
$scheduler->call(function() {
    // This task won't run if previous instance is still running
    processLargeDataset();
})
->hourly()
->without_overlapping(60)  // TTL in minutes
->description('Process large dataset');
```

**Behavior:**
- Before execution: Attempts to acquire mutex lock
- If lock held: Task is SKIPPED (not queued)
- After execution: Lock is released in `finally` block
- TTL prevents deadlock if process crashes

**Mutex name derivation:**
- Based on SHA1 hash of cron expression + callback description
- Or use custom name via `->name('custom-task-name')`

## Best Practices

### 1. Always Set Appropriate TTL

TTL should be longer than the maximum expected task duration:

```php
// Task takes up to 5 minutes? Set TTL to 10 minutes
$token = Mutex::acquire('my-task', 600);
```

### 2. Always Release in Finally Block

```php
$token = Mutex::acquire('my-task', 300);
if ($token) {
    try {
        doWork();
    } finally {
        Mutex::release('my-task', $token); // Always releases
    }
}
```

### 3. Use Descriptive Lock Names

```php
// Good: descriptive and unique
Mutex::acquire('newsletter-send-weekly', 3600);
Mutex::acquire("order-process-{$orderId}", 300);

// Bad: too generic
Mutex::acquire('lock', 300);
```

### 4. Consider Distributed Environments

For multi-server deployments, ensure you're using `redis` or `db` driver:

```php
$atomic->set('MUTEX', ['driver' => 'redis']);
```

### 5. Monitor Lock State

```php
// Check current driver
$info = Mutex::info();
// Returns: ['driver' => 'redis', 'initialized' => true, 'available' => true]

// Or just get driver name
$driver = Mutex::get_driver_name(); // e.g. 'redis'

// Check if specific lock exists
if (Mutex::exists('my-task')) {
    echo "Task is currently running";
}
```

## Troubleshooting

### Lock not being acquired

1. Check driver availability: `Mutex::info()`
2. Verify configuration (REDIS, MEMCACHED, DB settings or `MUTEX.driver`)
3. Ensure database migration ran (for `db` driver)
4. Check file permissions (for `file` driver)
5. Check lock name validity - names must match `/^[A-Za-z0-9:._-]{1,128}$/`. Invalid names cause warnings and operations will fail.

### Lock not being released

1. Ensure `release()` is called with correct token
2. Check if lock expired (TTL too short)
3. Verify no exceptions preventing `finally` block

### Tasks still overlapping

1. Verify `without_overlapping()` is called in schedule definition
2. Check if different cron expressions generate different mutex names
3. Ensure all servers use same driver (avoid `file` driver in cluster)


## Testing

```php
// Reset driver for testing
Mutex::reset();

// Inject mock driver
$mockDriver = new MockMutexDriver();
Mutex::set_driver($mockDriver);

// Inspect current token (driver must implement get_token)
$currentToken = Mutex::get_driver()?->get_token('my-task');
```

## Internal Architecture

```
Engine\Atomic\Mutex\Mutex              - Main API (static methods)
    ├── MutexDriverInterface           - Driver contract
    ├── RedisMutexDriver               - Redis implementation
    ├── MemcachedMutexDriver           - Memcached implementation
    ├── DatabaseMutexDriver            - Database implementation
    └── FileMutexDriver                - File implementation
```

All drivers implement `MutexDriverInterface`:
- `acquire(string $name, string $token, int $ttl): bool`
- `release(string $name, string $token): bool`
- `exists(string $name): bool`
- `get_token(string $name): ?string` (inspect current token/owner)
- `force_release(string $name): bool`
- `get_name(): string` (driver identifier: `'redis'`, `'memcached'`, `'db'`, `'file'`)
- `is_available(): bool`
