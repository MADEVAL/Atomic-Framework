<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\Migrations\MigrationsFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\StreamCapture;
use Tests\Support\TempPath;
use Tests\Support\TestConfig;

final class StressFixtureMigrationPlugin extends Plugin
{
    public function __construct(private readonly string $migrations_path)
    {
        parent::__construct();
    }

    protected function get_name(): string
    {
        return 'Stress Fixture Migration Plugin';
    }

    public function get_migrations_path(): ?string
    {
        return $this->migrations_path;
    }

    public function get_migration_source(): string
    {
        return 'plugin:stress/fixture';
    }
}

/**
 * MySQL regression coverage for existing installations, using isolated tables
 * and real migration files. Assertions check schema, history and user data.
 */
final class MigrationsLegacyUpgradeStressTest extends TestCase
{
    private const LEGACY_BATCH = '11111111-1111-4111-8111-111111111111';

    private string $tmp_dir;
    private string $app_migrations_dir;
    private string $framework_migrations_dir;
    private string $plugin_migrations_dir;
    private string $fixture_dir;
    private Output $output;
    private Migrations $migrations;
    private ?\PDO $pdo = null;
    private ?string $db_prefix = null;
    private mixed $original_db_config = null;
    private mixed $original_db = null;
    private mixed $original_migrations = null;
    private mixed $original_core_migrations = null;

    protected function setUp(): void
    {
        $this->tmp_dir = TempPath::make_dir('atomic_mig_stress_');
        $this->app_migrations_dir = $this->tmp_dir . 'app' . DIRECTORY_SEPARATOR;
        $this->framework_migrations_dir = $this->tmp_dir . 'framework' . DIRECTORY_SEPARATOR;
        $this->plugin_migrations_dir = $this->tmp_dir . 'plugin' . DIRECTORY_SEPARATOR;
        mkdir($this->app_migrations_dir, 0755, true);
        mkdir($this->framework_migrations_dir, 0755, true);
        mkdir($this->framework_migrations_dir . 'initial' . DIRECTORY_SEPARATOR, 0755, true);
        mkdir($this->framework_migrations_dir . 'updates' . DIRECTORY_SEPARATOR, 0755, true);
        mkdir($this->plugin_migrations_dir, 0755, true);

        $this->fixture_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;

        $atomic = App::instance();
        $this->original_db_config = $atomic->get('DB_CONFIG');
        $this->original_db = $atomic->get('DB');
        $this->original_migrations = $atomic->get('MIGRATIONS');
        $this->original_core_migrations = $atomic->get('MIGRATIONS_CORE');
        $atomic->set('MIGRATIONS', $this->app_migrations_dir);
        $atomic->set('MIGRATIONS_CORE', $this->framework_migrations_dir);
        ReflectionHelper::set(PluginManager::class, 'instance', null);

        $this->output = new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b'));
        $this->boot_mysql_migrations();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->db_prefix !== null) {
            foreach ([
                'legacy_posts', 'telemetry', 'jobs_completed', 'jobs_failed', 'jobs',
                'mutex_locks', 'sessions', 'options', 'meta', 'migrations',
            ] as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quote_identifier($this->db_prefix . $table));
            }
        }

        ConnectionManager::instance()->close_sql();
        $atomic = App::instance();
        $atomic->set('DB_CONFIG', $this->original_db_config);
        $atomic->set('DB', $this->original_db);
        $atomic->set('MIGRATIONS', $this->original_migrations);
        $atomic->set('MIGRATIONS_CORE', $this->original_core_migrations);
        ReflectionHelper::set(PluginManager::class, 'instance', null);
        TempPath::remove($this->tmp_dir);
    }

    // ── 1 ─────────────────────────────────────────────────────────────────

    public function test_legacy_install_upgrade_preserves_user_data_and_marks_history_verified(): void
    {
        $path = $this->write_migration($this->app_migrations_dir, '20240101000000_legacy_posts', $this->legacy_posts_migration_body());
        $this->create_user_table(['first', 'second', 'third']);
        $this->create_legacy_ledger(['20240101000000_legacy_posts']);

        $this->migrations->migrate();
        $this->assertSame(3, $this->user_row_count(), 'Legacy-applied migration ran again during migrate().');
        $this->assertSame(1, count($this->ledger_rows()));

        $this->assertTrue($this->migrations->upgrade());
        $this->assertTrue($this->migrations->was_successful());

        $rows = $this->ledger_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('app', $rows[0]['source']);
        $this->assertSame('20240101000000_legacy_posts', $rows[0]['migration']);
        $this->assertSame($this->checksum($path), $rows[0]['checksum']);
        $columns = $this->ledger_columns();
        $this->assertSame('NO', $columns['source']['Null']);
        $this->assertSame('NO', $columns['checksum']['Null']);

        $this->migrations->status();
        $this->assertStringContainsString('verified', $this->stdout());

        $this->migrations->migrate();
        $this->assertStringContainsString('No new migrations to apply.', $this->stdout());
        $this->assertSame(['first', 'second', 'third'], $this->user_titles(), 'User data changed by upgrade flow.');
        $this->assertCount(1, $this->ledger_rows(), 'Ledger row duplicated by re-migrate.');
    }

    // ── 2 ─────────────────────────────────────────────────────────────────

    public function test_checksum_uses_lf_normalization_for_legacy_and_new_records(): void
    {
        $applied = $this->write_migration(
            $this->app_migrations_dir,
            '20240101000000_crlf_applied',
            $this->crlf($this->noop_migration_body()),
        );
        $this->create_legacy_ledger(['20240101000000_crlf_applied']);

        $this->assertTrue($this->migrations->upgrade());
        $rows = $this->ledger_rows();
        $this->assertSame($this->checksum($applied), $rows[0]['checksum'], 'Adopted checksum is not the LF-normalized hash.');
        $this->assertNotSame($this->raw_checksum($applied), $rows[0]['checksum'], 'Adopted checksum used raw CRLF bytes.');

        $pending = $this->write_migration(
            $this->app_migrations_dir,
            '20240101000001_crlf_pending',
            $this->crlf($this->noop_migration_body()),
        );
        $this->migrations->migrate();

        $rows = $this->ledger_rows();
        $this->assertCount(2, $rows);
        $this->assertSame($this->checksum($pending), $rows[1]['checksum'], 'Recorded checksum is not the LF-normalized hash.');
        $this->assertSame($rows[0]['checksum'], $rows[1]['checksum'], 'Same CRLF content produced different checksums per path.');
    }

    // ── 3 ─────────────────────────────────────────────────────────────────

    public function test_upgrade_on_mixed_ledger_adopts_only_legacy_rows_and_rollback_spares_adopted_history(): void
    {
        $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->copy_fixture('20240101000001_shared_source.php', $this->app_migrations_dir);
        $this->create_legacy_ledger(['20240101000000_legacy_applied']);

        $this->migrations->migrate();
        $rows_before = $this->ledger_rows();
        $this->assertCount(2, $rows_before, 'Legacy install did not accept a new checksummed migration.');

        $this->assertTrue($this->migrations->upgrade());
        $rows_after = $this->ledger_rows();
        $this->assertSame($rows_before[1]['checksum'], $rows_after[1]['checksum'], 'Upgrade touched the new-style row.');
        $this->assertSame($rows_before[1]['batch_uuid'], $rows_after[1]['batch_uuid']);
        $this->assertSame('app', $rows_after[0]['source']);
        $this->assertNotNull($rows_after[0]['checksum']);

        $this->migrations->rollback(1);

        $rows = $this->ledger_rows();
        $this->assertCount(1, $rows, 'Rollback removed more than the newest row.');
        $this->assertSame((int) $rows_after[0]['id'], (int) $rows[0]['id'], 'Rollback popped the adopted legacy row.');
        $this->assertSame('app', $rows[0]['source'], 'Adopted legacy row lost its source.');
        $this->assertSame($rows_after[0]['checksum'], $rows[0]['checksum'], 'Adopted legacy row lost its checksum.');
        $this->assertSame(self::LEGACY_BATCH, $rows[0]['batch_uuid'], 'Adopted legacy row lost its original batch.');
        $this->assertTrue($this->migrations->was_successful(), 'Rollback of the newest row failed.');
        $this->assertStringNotContainsString('must not run again', $this->stderr());

        $this->migrations->migrate();
        $rows = $this->ledger_rows();
        $this->assertCount(2, $rows, 'Post-rollback migrate did not re-apply exactly the popped row.');
        $this->assertSame((int) $rows_after[0]['id'], (int) $rows[0]['id'], 'Re-migrate touched the adopted legacy row.');
        $this->assertSame('app', $rows[0]['source']);
        $this->assertSame($rows_after[0]['checksum'], $rows[0]['checksum']);
        $this->assertStringNotContainsString('must not run again', $this->stderr());
    }

    // ── 4 ─────────────────────────────────────────────────────────────────

    public function test_upgrade_refuses_duplicate_legacy_rows_without_corrupting_ledger(): void
    {
        $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger(['20240101000000_legacy_applied', '20240101000000_legacy_applied']);

        $this->assertFalse($this->migrations->upgrade(), 'Upgrade accepted duplicate legacy identities.');
        $this->assertStringContainsString('duplicate', strtolower($this->stderr()));

        $rows = $this->ledger_rows();
        $this->assertCount(2, $rows, 'Refused upgrade removed ledger rows.');
        foreach ($rows as $row) {
            $this->assertNull($row['source'], 'Refused upgrade partially adopted a row.');
            $this->assertNull($row['checksum'], 'Refused upgrade partially adopted a row.');
        }

        $this->migrations->migrate();
        $this->assertCount(2, $this->ledger_rows(), 'Migrate duplicated rows after refused upgrade.');
        $this->assertStringNotContainsString('must not run again', $this->stderr());
    }

    // ── 5 ─────────────────────────────────────────────────────────────────

    public function test_dry_run_then_upgrade_twice_is_idempotent_and_multi_source(): void
    {
        $app_path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $canonical = $this->copy_fixture('atomic_fixture_framework.php', $this->framework_migrations_dir . 'initial' . DIRECTORY_SEPARATOR);
        copy($canonical, $this->app_migrations_dir . '20240101000002_atomic_fixture_framework.php');
        $plugin_path = $this->write_migration($this->plugin_migrations_dir, 'atomic_plugin_thing', $this->throwing_migration_body('Plugin migration ran again.'));
        PluginManager::instance()->register(new StressFixtureMigrationPlugin($this->plugin_migrations_dir));

        $this->create_legacy_ledger([
            '20240101000000_legacy_applied',
            '20240101000002_atomic_fixture_framework',
            '20240101000004_atomic_plugin_thing',
        ]);

        $this->assertTrue($this->migrations->upgrade(true));
        $this->assertSame(3, substr_count($this->stdout(), 'Would adopt'), 'Dry run did not report every adoption.');
        self::assertArrayNotHasKey('source', $this->ledger_columns());
        self::assertArrayNotHasKey('checksum', $this->ledger_columns());

        $this->assertTrue($this->migrations->upgrade());
        $rows = $this->ledger_rows();
        $this->assertSame(
            ['app', 'framework', 'plugin:stress/fixture'],
            array_column($rows, 'source'),
            'Adoption picked the wrong owner for a legacy row.'
        );
        $this->assertSame($this->checksum($app_path), $rows[0]['checksum']);
        $this->assertSame($this->checksum($canonical), $rows[1]['checksum']);
        $this->assertSame($this->checksum($plugin_path), $rows[2]['checksum']);
        $this->assertSame('NO', $this->ledger_columns()['source']['Null']);

        $rows_after_first = $this->ledger_rows();
        $this->assertTrue($this->migrations->upgrade());
        $this->assertStringContainsString('already upgraded', $this->stdout());
        $this->assertSame($rows_after_first, $this->ledger_rows(), 'Second upgrade mutated stable history.');

        $this->migrations->migrate();
        $this->assertStringContainsString('No new migrations to apply.', $this->stdout());
        $this->assertSame($rows_after_first, $this->ledger_rows(), 'Post-adoption migrate duplicated pending work.');
        $this->assertStringNotContainsString('must not run again', $this->stderr());
    }

    // ── 6 ─────────────────────────────────────────────────────────────────

    public function test_upgrade_failure_is_atomic_leaving_ledger_and_user_data_untouched(): void
    {
        $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_user_table(['precious']);
        $this->create_legacy_ledger(['20240101000000_legacy_applied', '20240101000009_missing_fixture']);

        $this->assertFalse($this->migrations->upgrade(), 'Upgrade adopted history despite an unavailable file.');
        $this->assertStringContainsString('unavailable', strtolower($this->stderr()));
        $rows = $this->ledger_rows();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['source']);
        $this->assertNull($rows[1]['source']);
        $this->assertSame(['precious'], $this->user_titles());

    }

    // ── 7 ─────────────────────────────────────────────────────────────────

    public function test_legacy_file_tampering_is_locked_in_by_adoption_and_caught_afterwards(): void
    {
        $path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        file_put_contents($path, (string) file_get_contents($path) . PHP_EOL . '// tampered v1');
        $this->create_user_table(['keep-me']);
        $this->create_legacy_ledger(['20240101000000_legacy_applied']);

        $this->migrations->migrate();
        $this->assertCount(1, $this->ledger_rows(), 'Legacy migrate re-applied a modified applied migration.');
        $this->assertStringNotContainsString('must not run again', $this->stderr());

        $this->assertTrue($this->migrations->upgrade(), 'Adoption refused a legacy row whose file changed pre-install.');
        $rows = $this->ledger_rows();
        $this->assertSame($this->checksum($path), $rows[0]['checksum'], 'Adoption did not lock the current file state as baseline.');

        file_put_contents($path, (string) file_get_contents($path) . PHP_EOL . '// tampered v2');
        $this->copy_fixture('20240101000001_shared_source.php', $this->app_migrations_dir);

        $this->migrations->migrate();
        $this->assertFalse($this->migrations->was_successful(), 'Tampered history did not abort migrate.');
        $this->assertStringContainsString('checksum mismatch', strtolower($this->stderr()));
        $this->assertCount(1, $this->ledger_rows(), 'Tampered history ran or recorded pending work.');
        $this->assertSame(['keep-me'], $this->user_titles(), 'User data lost during tamper abort.');
    }

    // ── 8 ─────────────────────────────────────────────────────────────────

    public function test_modified_published_copy_of_legacy_framework_migration_is_aliased_after_upgrade(): void
    {
        $canonical = $this->copy_fixture('atomic_fixture_framework.php', $this->framework_migrations_dir . 'initial' . DIRECTORY_SEPARATOR);
        $published = $this->app_migrations_dir . '20240101000002_atomic_fixture_framework.php';
        copy($canonical, $published);
        file_put_contents($published, (string) file_get_contents($published) . PHP_EOL . '// locally modified');
        $this->create_legacy_ledger(['20240101000002_atomic_fixture_framework']);

        $this->migrations->migrate();
        $this->assertCount(1, $this->ledger_rows(), 'Legacy migrate executed either the canonical or the modified copy.');
        $this->assertStringNotContainsString('must not run again', $this->stderr());

        $this->assertFalse($this->migrations->upgrade());
        $this->assertTrue($this->with_answer('yes')->upgrade());
        $rows = $this->ledger_rows();
        $this->assertSame('framework', $rows[0]['source']);
        $this->assertSame('atomic_fixture_framework', $rows[0]['migration']);
        $this->assertSame($this->checksum($canonical), $rows[0]['checksum'], 'Adoption recorded the modified copy checksum instead of the owner checksum.');

        $this->migrations->migrate();
        $this->assertCount(1, $this->ledger_rows(), 'Post-upgrade migrate re-ran or duplicated the modified copy.');
        $this->assertStringNotContainsString('must not run again', $this->stderr());

        $this->migrations->status();
        $this->assertStringContainsString('modified', $this->stdout());
    }

    public function test_dry_run_preserves_legacy_schema_and_rows(): void
    {
        $this->write_migration($this->app_migrations_dir, '20240101000000_posts', $this->noop_migration_body());
        $this->create_legacy_ledger(['20240101000000_posts']);
        $before = $this->ledger_snapshot();
        self::assertTrue($this->migrations->upgrade(true));
        self::assertSame($before, $this->ledger_snapshot());
    }

    public function test_dry_run_does_not_create_an_absent_ledger(): void
    {
        self::assertTrue($this->migrations->upgrade(true), $this->stderr());
        self::assertNotContains($this->db_prefix . 'migrations', $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertStringContainsString('Create migration ledger', $this->stdout());
    }

    public function test_zero_and_negative_rollback_are_errors_without_data_changes(): void
    {
        $this->write_migration($this->app_migrations_dir, '20240101000000_posts', $this->legacy_posts_migration_body());
        $this->migrations->migrate();
        self::assertTrue($this->migrations->was_successful(), $this->stderr());
        $before = $this->ledger_rows();
        foreach ([0, -1] as $steps) {
            $this->migrations->rollback($steps);
            self::assertFalse($this->migrations->was_successful());
            self::assertSame($before, $this->ledger_rows());
            self::assertSame(['seed-canary'], $this->user_titles());
        }
    }

    public function test_partially_published_updates_keep_numeric_order(): void
    {
        $this->write_migration($this->framework_migrations_dir . 'updates/', '0001_create_posts', $this->legacy_posts_migration_body());
        $this->write_migration($this->framework_migrations_dir . 'updates/', '0002_add_title', $this->sql_migration_body("INSERT INTO %slegacy_posts (title) VALUES ('update-two')"));
        $this->migrations->publish_framework('add_title');
        $this->migrations->migrate();
        self::assertTrue($this->migrations->was_successful(), $this->stderr());
        self::assertSame(['create_posts', 'add_title'], array_column($this->ledger_rows(), 'migration'));
        self::assertSame(['seed-canary', 'update-two'], $this->user_titles());
    }

    public function test_missing_plugin_original_uses_applied_copy_without_rerunning(): void
    {
        $path = $this->publish_and_apply_plugin();
        unlink($path);
        $before = $this->ledger_rows();
        $this->migrations->migrate();
        self::assertTrue($this->migrations->was_successful(), $this->stderr());
        self::assertSame(['seed-canary'], $this->user_titles());
        self::assertSame($before, $this->ledger_rows());
        $this->migrations->rollback(1);
        self::assertTrue($this->migrations->was_successful(), $this->stderr());
        self::assertSame([], $this->ledger_rows());
        self::assertNotContains($this->db_prefix . 'legacy_posts', $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_modified_fallback_copy_requires_confirmation_and_can_be_used(): void
    {
        unlink($this->publish_and_apply_plugin());
        $copy = glob($this->app_migrations_dir . '*.php')[0];
        file_put_contents($copy, "\n// edited copy", FILE_APPEND);
        $this->migrations->rollback(1);
        self::assertFalse($this->migrations->was_successful());
        self::assertSame(['seed-canary'], $this->user_titles());
        self::assertStringContainsString('checksum mismatch', strtolower($this->stderr()));
        $this->with_answer('yes')->rollback(1);
        self::assertSame([], $this->ledger_rows());
    }

    public function test_missing_plugin_files_stop_pending_work_until_restored(): void
    {
        $path = $this->publish_and_apply_plugin();
        $body = file_get_contents($path);
        unlink($path);
        unlink(glob($this->app_migrations_dir . '*.php')[0]);
        $this->write_migration($this->app_migrations_dir, '20240101000001_pending', $this->sql_migration_body("INSERT INTO %slegacy_posts (title) VALUES ('pending')"));
        $before = $this->ledger_rows();
        $this->migrations->migrate();
        self::assertFalse($this->migrations->was_successful());
        self::assertSame($before, $this->ledger_rows());
        self::assertSame(['seed-canary'], $this->user_titles());
        self::assertStringContainsString('Restore', $this->stderr());
        file_put_contents($path, $body);
        $this->migrations->migrate();
        self::assertTrue($this->migrations->was_successful(), $this->stderr());
        self::assertSame(['seed-canary', 'pending'], $this->user_titles());
    }

    public function test_legacy_modified_plugin_adoption_requires_confirmation(): void
    {
        $this->write_migration($this->plugin_migrations_dir, 'posts', $this->legacy_posts_migration_body());
        $this->write_migration($this->app_migrations_dir, '20240101000000_posts', $this->noop_migration_body());
        PluginManager::instance()->register(new StressFixtureMigrationPlugin($this->plugin_migrations_dir));
        $this->create_user_table(['keep-me']);
        $this->create_legacy_ledger(['20240101000000_posts']);
        $before = $this->ledger_snapshot();
        self::assertTrue($this->migrations->upgrade(true));
        self::assertSame($before, $this->ledger_snapshot());
        self::assertFalse($this->migrations->upgrade());
        self::assertNull($this->ledger_rows()[0]['source']);
        self::assertSame(['keep-me'], $this->user_titles());
        self::assertFalse($this->with_answer('n')->upgrade());
        self::assertTrue($this->with_answer('yes')->upgrade());
        self::assertSame('plugin:stress/fixture', $this->ledger_rows()[0]['source']);
        self::assertSame(['keep-me'], $this->user_titles());
        self::assertStringContainsString('contents differ', $this->stderr());
    }

    public function test_legacy_modified_framework_adoption_requires_confirmation(): void
    {
        $this->write_migration($this->framework_migrations_dir . 'initial/', 'posts', $this->legacy_posts_migration_body());
        $this->write_migration($this->app_migrations_dir, '20240101000000_posts', $this->noop_migration_body());
        $this->create_legacy_ledger(['20240101000000_posts']);
        self::assertFalse($this->migrations->upgrade());
        self::assertNull($this->ledger_rows()[0]['source']);
        self::assertTrue($this->with_answer('yes')->upgrade());
    }

    public function test_database_failure_rolls_back_all_adoptions_and_retry_succeeds(): void
    {
        foreach (['20240101000000_first', '20240101000001_second'] as $name) {
            $this->write_migration($this->app_migrations_dir, $name, $this->noop_migration_body());
        }
        $this->create_legacy_ledger(['20240101000000_first', '20240101000001_second']);
        $this->create_user_table(['keep-me']);
        self::assertTrue($this->migrations->db());
        $before = $this->ledger_rows();
        $trigger = $this->quote_identifier($this->db_prefix . 'fail_adoption');
        $table = $this->quote_identifier($this->db_prefix . 'migrations');
        $this->pdo->exec("CREATE TRIGGER {$trigger} BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF OLD.id = 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'adoption failure'; END IF; END");
        self::assertFalse($this->migrations->upgrade());
        self::assertSame($before, $this->ledger_rows());
        self::assertSame(['keep-me'], $this->user_titles());
        $this->pdo->exec('DROP TRIGGER ' . $trigger);
        self::assertTrue($this->migrations->upgrade());
        self::assertSame(['app', 'app'], array_column($this->ledger_rows(), 'source'));
    }

    public function test_shared_lock_is_released_after_callback_failure(): void
    {
        $ledger = new \Engine\Atomic\Core\Migrations\MigrationLedger(App::instance(), ConnectionManager::instance());
        try {
            $ledger->synchronized(static function (): void { throw new \RuntimeException('callback failure'); });
            self::fail('Expected callback failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('callback failure', $e->getMessage());
        }
        $lock = 'atomic_migrations_' . substr(hash('sha256', App::instance()->get('DB_CONFIG.db') . "\0" . $this->db_prefix . 'migrations'), 0, 40);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        try {
            self::assertSame(1, (int)$statement->fetchColumn());
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    private function publish_and_apply_plugin(): string
    {
        $path = $this->write_migration($this->plugin_migrations_dir, 'posts', $this->legacy_posts_migration_body());
        PluginManager::instance()->register(new StressFixtureMigrationPlugin($this->plugin_migrations_dir));
        $this->migrations->publish_from_plugin('Stress Fixture Migration Plugin');
        $this->migrations->migrate();
        self::assertTrue($this->migrations->was_successful(), $this->stderr());
        return $path;
    }

    private function with_answer(string $answer): Migrations
    {
        $input = new class($this->output, $answer) extends \Engine\Atomic\CLI\Console\Input {
            public function __construct(Output $output, private string $answer) { parent::__construct($output); }
            public function is_interactive(): bool { return true; }
            public function read_line(): string { return $this->answer; }
        };
        return (new MigrationsFactory(App::instance(), ConnectionManager::instance(), PluginManager::instance(), Filesystem::instance()))->create($this->output, $input);
    }

    private function ledger_snapshot(): array
    {
        $table = $this->quote_identifier($this->db_prefix . 'migrations');
        return [$this->pdo->query('SHOW CREATE TABLE ' . $table)->fetchAll(), $this->pdo->query('SELECT * FROM ' . $table)->fetchAll()];
    }

    private function sql_migration_body(string $sql): string
    {
        return "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\nreturn ['up' => static function () { \\Engine\\Atomic\\Core\\ConnectionManager::instance()->get_db()->exec("
            . var_export(sprintf($sql, $this->db_prefix), true) . "); }, 'down' => static fn () => true];";
    }

    // ── Harness ────────────────────────────────────────────────────────────

    private function boot_mysql_migrations(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('ext-pdo_mysql not loaded.');
        }

        // Earlier tests may leave a non-MySQL hive configuration. Use the
        // configured test database, then restore the original hive in tearDown.
        $config = TestConfig::db();
        if (($config['driver'] ?? 'mysql') !== 'mysql') {
            self::markTestSkipped('Legacy migration stress tests require MySQL.');
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $database = (string) ($config['db'] ?? 'atomic_test');
        $username = (string) ($config['username'] ?? 'atomic_test_user');
        $password = (string) ($config['password'] ?? 'atomic_test_pass');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $this->pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL is unavailable: ' . $e->getMessage());
        }

        $this->db_prefix = 'atomic_mig_stress_' . bin2hex(random_bytes(4)) . '_';
        $config['prefix'] = $this->db_prefix;
        App::instance()->set('DB_CONFIG', $config);
        ConnectionManager::instance()->close_sql();
        $this->migrations = (new MigrationsFactory(
            App::instance(),
            ConnectionManager::instance(),
            PluginManager::instance(),
            Filesystem::instance(),
        ))->create($this->output);
    }

    private function create_legacy_ledger(array $migrations): void
    {
        $table = $this->quote_identifier($this->db_prefix . 'migrations');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        $this->pdo->exec(
            "CREATE TABLE {$table} ("
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'migration VARCHAR(255) NOT NULL,'
            . 'batch_uuid VARCHAR(36) NOT NULL,'
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB'
        );
        $statement = $this->pdo->prepare(
            "INSERT INTO {$table} (migration, batch_uuid, applied_at) VALUES (?, ?, ?)"
        );
        foreach ($migrations as $migration) {
            $statement->execute([$migration, self::LEGACY_BATCH, '2024-01-01 00:00:00']);
        }
    }

    private function create_user_table(array $titles): void
    {
        $table = $this->quote_identifier($this->db_prefix . 'legacy_posts');
        $this->pdo->exec(
            "CREATE TABLE {$table} ("
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'title VARCHAR(191) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $statement = $this->pdo->prepare("INSERT INTO {$table} (title) VALUES (?)");
        foreach ($titles as $title) {
            $statement->execute([$title]);
        }
    }

    private function user_row_count(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->quote_identifier($this->db_prefix . 'legacy_posts'));
        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function user_titles(): array
    {
        $statement = $this->pdo->query('SELECT title FROM ' . $this->quote_identifier($this->db_prefix . 'legacy_posts') . ' ORDER BY id ASC');
        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function write_migration(string $directory, string $name, string $body): string
    {
        $path = $directory . $name . '.php';
        file_put_contents($path, $body);
        return $path;
    }

    private function copy_fixture(string $name, string $destination): string
    {
        $target = $destination . $name;
        copy($this->fixture_dir . $name, $target);
        return $target;
    }

    private function noop_migration_body(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

return [
    'up' => static fn (): bool => true,
    'down' => static fn (): bool => true,
];
PHP;
    }

    private function throwing_migration_body(string $message): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

return [
    'up' => static function (): void {
        throw new RuntimeException('{$message}');
    },
    'down' => static fn (): bool => true,
];
PHP;
    }

    private function legacy_posts_migration_body(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

return [
    'up' => static function (): bool {
        $db = \Engine\Atomic\Core\ConnectionManager::instance()->get_db();
        $prefix = (string) \Engine\Atomic\Core\App::instance()->get('DB_CONFIG.prefix');
        $table = '`' . str_replace('`', '``', $prefix . 'legacy_posts') . '`';
        $db->exec("CREATE TABLE IF NOT EXISTS {$table} (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("INSERT INTO {$table} (title) VALUES ('seed-canary')");
        return true;
    },

    'down' => static function (): bool {
        $db = \Engine\Atomic\Core\ConnectionManager::instance()->get_db();
        $prefix = (string) \Engine\Atomic\Core\App::instance()->get('DB_CONFIG.prefix');
        $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $prefix . 'legacy_posts') . '`');
        return true;
    },
];
PHP;
    }

    private function crlf(string $content): string
    {
        return str_replace("\n", "\r\n", $content);
    }

    private function checksum(string $path): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
        return hash('sha256', $contents);
    }

    private function raw_checksum(string $path): string
    {
        return hash('sha256', (string) file_get_contents($path));
    }

    private function ledger_rows(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, source, migration, checksum, batch_uuid, applied_at FROM '
            . $this->quote_identifier($this->db_prefix . 'migrations') . ' ORDER BY id ASC'
        );
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function ledger_columns(): array
    {
        $statement = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quote_identifier($this->db_prefix . 'migrations'));
        $columns = $statement->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($columns, null, 'Field');
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function stdout(): string
    {
        return StreamCapture::read($this->output->stdout(), true);
    }

    private function stderr(): string
    {
        return StreamCapture::read($this->output->stderr(), true);
    }
}
