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

### Migration file shape

Generated files are stored under the configured `MIGRATIONS` directory and use this structure:

```php
<?php
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
    },

    'down' => function () {
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
    }
];
```

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
