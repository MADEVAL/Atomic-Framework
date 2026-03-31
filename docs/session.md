## Session Management ##

Atomic exposes session lifecycle helpers through `Engine\Atomic\Auth\Session` and storage inspection helpers through `Engine\Atomic\Session\SessionManager`.

### Auth session facade

```php
use Engine\Atomic\Auth\Session;

Session::init();
Session::start();
Session::start('550e8400-e29b-41d4-a716-446655440000');

if (Session::is_started()) {
    // active
}

if (Session::is_expired()) {
    // expired
}

Session::destroy();
```

The facade delegates to `Engine\Atomic\Auth\Services\SessionService`.

### Working with session data

Atomic stores userland session values in the F3 `SESSION` hive:

```php
$f3 = \Base::instance();

$f3->set('SESSION.user_uuid', '550e8400-e29b-41d4-a716-446655440000');
$uuid = $f3->get('SESSION.user_uuid');
$exists = $f3->exists('SESSION.user_uuid');
$f3->clear('SESSION.user_uuid');
```

### Session manager

```php
use Engine\Atomic\Session\SessionManager;

$manager = new SessionManager();          // driver from SESSION_CONFIG.driver
$redis = new SessionManager('redis');     // force redis
$sql = new SessionManager('database');    // force SQL-style driver
```

Available methods:

- `session_exists(string $session_id): bool`
- `get_session_data(string $session_id): ?array`
- `delete_session(string $session_id): bool`
- `delete_sessions(array $session_ids): int`
- `get_driver(): string`

Example:

```php
$data = $manager->get_session_data($session_id);

if ($data !== null) {
    echo $data['session_id'];
}
```

For SQL-backed sessions, the returned shape is:

```php
[
    'session_id' => 'abc123',
    'data' => '...',
    'ip' => '127.0.0.1',
    'agent' => 'Mozilla/5.0',
    'stamp' => 1710000000,
]
```

For Redis-backed sessions, `get_session_data()` returns the decoded JSON payload stored under the configured Redis session prefix.

### Drivers

`SessionManager` reads the driver from `SESSION_CONFIG.driver`.

Current storage implementations:

- SQL-backed session storage via `SqlSessionTrait`
- Redis-backed session storage via `RedisSessionTrait`

When Redis is used, keys are prefixed with `REDIS.ATOMIC_REDIS_SESSION_PREFIX`.
