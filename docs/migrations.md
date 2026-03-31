## Migrations ##

Atomic migrations are implemented by `Engine\Atomic\Core\Migrations`.

The system creates and tracks a prefixed migrations table, generates timestamped migration files, applies pending migrations in order, and supports rollback by count or by last batch.

### CLI

```bash
php atomic migrations/create create_users_table
php atomic migrations/migrate
php atomic migrations/migrate 1

php atomic migrations/rollback
php atomic migrations/rollback 3
php atomic migrations/rollback batch

php atomic migrations/status
```

Notes:

- there is no separate `migrations/db` command; the migrations table is ensured automatically by `new Migrations()`
- migration names are limited to letters, numbers, and underscores

### Migration file shape

Generated files are stored under the configured `MIGRATIONS` directory and use this structure:

```php
<?php
use Engine\Atomic\Core\App;
use DB\SQL\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
    },

    'down' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
    }
];
```

### Programmatic usage

```php
use Engine\Atomic\Core\Migrations;

$migrations = new Migrations();

$migrations->create('create_users_table');
$migrations->migrate();
$migrations->rollback('batch');
$migrations->status();
```

To publish a packaged migration file into the app migrations directory:

```php
$migrations->publish($atomic->get('MIGRATIONS_CORE') . 'atomic_create_queue_tables');
```
