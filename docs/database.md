## Database ##

Atomic database access is centered around `Engine\Atomic\Core\ConnectionManager` and the hive key `DB`.

### Register the DB connection

```php
use Engine\Atomic\Core\App;

$app = App::instance();
$app->setDB();

$db = $app->get('DB');
```

`setDB()` stores a `DB\SQL` connection in the hive using the current `DB_CONFIG` values.

### Connection manager

```php
use Engine\Atomic\Core\ConnectionManager;

$connections = new ConnectionManager();

$db = $connections->get_db();
$redis = $connections->get_redis(false);
$memcached = $connections->get_memcached(false);
```

Behavior:

- MySQL, Redis, and Memcached connections are opened lazily
- existing connections are health-checked and reconnected when needed
- failed required connections throw runtime exceptions

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
$app->resetDB();
$app->closeDB();
```

Use `resetDB()` to reopen the SQL connection and `closeDB()` to release it explicitly.
