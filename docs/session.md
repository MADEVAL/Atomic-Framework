## Session Management ##

Atomic exposes the general session API through `Engine\Atomic\Session\Session`.
Storage inspection helpers remain available through
`Engine\Atomic\Session\SessionManager`.

### Core session facade

```php
use Engine\Atomic\Session\Session;

Session::init();
Session::start();
Session::is_started();
Session::destroy();
```

`Session::init()` sets the configured session cookie name and starts the backend
only when the current HTTP request already has that session cookie. CLI requests
do not auto-start sessions.

`Session::start()` takes no auth UUID. It starts the configured session driver
and fires session hooks:

- `SESSION_BEFORE_START` before the driver starts.
- `SESSION_STARTED` after the backend is active.

The core session service never reads or validates `SESSION.user_uuid`. Session
data used by hidden pages, flash messages, CSRF, telemetry, or other framework
features can exist without enabling auth.

### Auth session behavior

Auth session state is handled by `Engine\Atomic\Auth\Services\AuthSessionService`.
It builds on the core session backend and owns only auth-specific keys:

- `SESSION.user_uuid`
- `SESSION.created_at`
- `SESSION.admin_uuid`

`AuthService::login_by_id()` starts an auth session with
`AuthSessionService::start_for_user($auth_id)`. Logout and invalid auth sessions
clear the auth keys without clearing unrelated `SESSION` data.

Auth validation is registered as an optional hook consumer. `Hook\System` does
not register auth validation globally. `App::register_user_provider()` registers
the `SESSION_STARTED` auth validation listener only after a configured provider
class exists and implements `UserProviderInterface`. Registration is guarded so
calling `register_user_provider()` more than once does not duplicate the
listener.

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

For Redis-backed sessions, `get_session_data()` returns the decoded JSON payload
stored under the configured Redis session prefix.

### Drivers

`SessionManager` reads the driver from `SESSION_CONFIG.driver`.

Current storage implementations:

- SQL-backed session storage via `SqlSessionTrait`
- Redis-backed session storage via `RedisSessionTrait`

When Redis is used, keys are prefixed with `REDIS.prefix`.
