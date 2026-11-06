## Migrations

Atomic migrations use one source-aware ledger and one migration/rollback chain. Obtain the service through `MigrationsFactory`; migration commands and subsystem setup commands share that service.

### Sources and ordering

Application migrations live in the configured `MIGRATIONS` directory and have timestamped filenames. Framework migrations live under `MIGRATIONS_CORE`:

```text
engine/Atomic/Core/Database/Migrations/
  initial/    # published explicitly by subsystem setup commands
  updates/    # versioned files, e.g. 0001_atomic_add_status.php
```

Initial framework migrations execute from published application copies. Use `db/storage`, `db/sessions`, `db/mutex`, or `db/queue` to publish the corresponding initial migration.

Framework updates execute in numeric version order, including when only some updates are published or copies were published in a different order. Publishing substitutes the executable file; it does not change framework update order. Files outside `initial/` and `updates/` are ignored.

The chain contains application migrations and published initial framework migrations, then framework updates, then enabled plugin migrations in dependency order. Plugin originals execute directly unless an already-applied original is missing and a published copy can be resolved. Unmodified published plugin copies do not execute as additional application migrations.

### Checksums and missing files

The ledger stores a source, logical migration name, SHA-256 checksum, batch UUID and application time. Checksums normalize line endings.

Checksum differences produce a warning and require explicit interactive confirmation. Declining or running without interactive input aborts the operation. Confirmation does not repair files or rewrite already-recorded checksums; the user is responsible for approving the current file.

For pending published framework migrations, the framework original provides the comparison checksum. After application, the recorded checksum is authoritative and the published file remains executable. Changing or removing its framework original does not invalidate an applied published copy.

If an applied plugin original disappears, the surviving published copy retains the recorded plugin identity and applied status. It is not rerun. Rollback uses that copy after checking it against the recorded checksum, with confirmation required if it differs. Ambiguous copies require restoring the original.

If an applied migration cannot be resolved, migrate stops before running pending work. Restore its original file or published application copy and retry. Rollback also preflights its selected files before executing any `down()`. History is preserved when files are unavailable.

### CLI

```bash
php atomic migrations/init
php atomic migrations/create create_users_table
php atomic migrations/migrate
php atomic migrations/migrate 1
php atomic migrations/rollback
php atomic migrations/rollback 3
php atomic migrations/rollback batch
php atomic migrations/status
php atomic migrations/upgrade --dry-run
php atomic migrations/upgrade
php atomic migrations/publish framework
php atomic migrations/publish <plugin-name>
```

Rollback accepts a positive integer or `batch`; omitting the argument rolls back one migration. Zero, negative counts and other values are incorrect usage. Migration names contain letters, numbers and underscores.

### Existing installations

Normal migration operations prepare missing nullable source/checksum columns and the identity index without rerunning recognized legacy rows. `migrations/upgrade` adopts legacy identities and establishes checksum baselines, then finalizes the metadata columns as `NOT NULL`. Adoption updates are transactional; duplicate identities, unavailable files or database failures prevent partial row adoption.

`migrations/upgrade --dry-run` is read-only: it does not create the ledger, add columns/indexes, update rows or finalize the schema. It displays proposed schema work and adoptions. Checksum conflicts are shown in the preview; the real upgrade requires confirmation.

For legacy application migrations without recorded checksums, adoption establishes a baseline from the available application file. For recognized framework/plugin copies, adoption uses the owner identity and checksum. If the copy differs, upgrade warns before adoption and requires confirmation. Plugin rollback subsequently uses the original when available; framework rollback continues to use the published copy, checked against the adopted checksum. Upgrade itself executes no migrations.

Migration, rollback and upgrade use a database advisory lock. Execution stops on the first failure. A failed `up()` is not recorded, and a failed `down()` retains its history row. MySQL schema changes may already have committed when a migration or history write fails; inspect and resolve that state before retrying.

Migration authors must make `up()` and `down()` safe to retry (idempotent), including after partial execution. Check existing tables, columns and data before changing them. Migration effects and the subsequent history write are not one atomic operation: if the effects succeed but recording history fails, the next attempt can execute the callback again. The framework cannot infer how to repair application-specific partial changes; that responsibility belongs to the migration author.

### Writing a migration

`migrations/create` generates a file under the configured `MIGRATIONS` directory. The file returns an anonymous class that extends `Migration`:

```php
<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $users = $this->table('users');

        if ($this->has_table($users)) {
            $this->output()->check(CheckStatus::SKIP, $users, 'table already exists');
            return;
        }

        $table = $this->schema()->createTable($users);
        $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
        $table->build();
        $this->output()->check(CheckStatus::OK, $users, 'table created');
    }

    public function down(): void
    {
        $this->schema()->dropTable($this->table('users'));
    }
};
```

Helpers available inside `up()` and `down()`:

| Method | Returns |
| --- | --- |
| `$this->app()` | `Engine\Atomic\Core\App` |
| `$this->db(string $connection = 'default')` | `DB\SQL` |
| `$this->schema(string $connection = 'default')` | `DB\Cortex\Schema\Schema` |
| `$this->output()` | `Engine\Atomic\CLI\Console\Output` |
| `$this->table(string $name)` | `string`: `$name` with `DB_CONFIG.prefix` prepended |
| `$this->has_table(string $table, string $connection = 'default')` | `bool`: exact name match, so pass a `table()` result |

`output()` is the runner's console. Use it to report what the migration did, e.g. `check(CheckStatus::OK|WARN|FAIL|SKIP, $name, $message)`, `warning($message)` or `writeln($message)`. `check()` prints lines such as `[SKIP] app_users - table already exists`.

To fail a migration, throw an exception. The runner rethrows it with the migration identity, e.g. `Migration 'app:20260101000000_create_users' failed: <message>`, and does not record the migration.

Return an instance (`new class`), not a named class: a file can be included more than once per process, and a named class would fatal on redeclare.

### Legacy array format

Files that return an array of `up`/`down` callables keep working unchanged. Both formats can be mixed in one directory and one batch:

```php
<?php
use Engine\Atomic\Core\ConnectionManager;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $schema = new Schema(ConnectionManager::instance()->get_db());
        // ...
    },
    'down' => function () {
        // ...
    },
];
```

In this format a callback fails by returning `false`; exceptions propagate unchanged. Use the class format for new migrations.

### Programmatic usage

```php
use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\Migrations\MigrationsFactory;

$migrations = Container::global()->get(MigrationsFactory::class)->create();

$migrations->create('create_users_table');
$migrations->migrate();
$migrations->rollback('batch');
$migrations->status();
```

Packaged migrations can be published into the application migration directory:

```php
$migrations->publish_framework('atomic_create_queue_tables');
```
