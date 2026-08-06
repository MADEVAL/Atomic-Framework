## Database ##

Atomic database access is centered around `Engine\Atomic\Core\ConnectionManager`.

### Connection manager

```php
use Engine\Atomic\Core\ConnectionManager;

$connections = ConnectionManager::instance();

$db = $connections->get_db();
$redis = $connections->get_redis(false);
$memcached = $connections->get_memcached(false);
```

Behavior:

- MySQL, Redis, and Memcached connections are opened lazily
- existing connections are health-checked and reconnected when needed
- failed required connections throw runtime exceptions
- `get_db(true, true)` can also return reconnection info as `[$db, $reconnected]`

### Relevant config

Database settings are read from `DB_CONFIG`, for example:

```php
$f3->get('DB_CONFIG.driver');
$f3->get('DB_CONFIG.host');
$f3->get('DB_CONFIG.port');
$f3->get('DB_CONFIG.database');
$f3->get('DB_CONFIG.username');
```

### CLI helpers

Atomic CLI exposes a few DB-related commands:

```bash
php atomic db/tables
php atomic db/truncate <table_name>
php atomic db/truncate/queue
php atomic db/sessions
php atomic db/storage
php atomic db/mutex
```

There are also project/bundled migration publishers such as:

```bash
php atomic db/users
php atomic db/stores
php atomic db/pages
php atomic db/orders
php atomic db/payments
```

### Lifecycle helpers

```php
$connections->open_all();

$connections->close_sql();
$connections->close_redis();
$connections->close_memcached();

$connections->close();
```

Use `open_all()` to proactively open available configured connections, and `close*()` methods to release them explicitly.
