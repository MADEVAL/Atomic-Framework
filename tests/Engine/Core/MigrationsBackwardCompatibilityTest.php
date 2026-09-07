<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\Migrations\MigrationsFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\StreamCapture;
use Tests\Support\TempPath;
use Tests\Support\ReflectionHelper;

final class FixtureMigrationPlugin extends Plugin
{
    public function __construct(private readonly string $migrations_path)
    {
        parent::__construct();
    }

    protected function get_name(): string
    {
        return 'Fixture Migration Plugin';
    }

    public function get_migrations_path(): ?string
    {
        return $this->migrations_path;
    }

    public function get_migration_source(): string
    {
        return 'plugin:test/fixture';
    }
}

final class MigrationPromptInput extends Input
{
    public function __construct(
        private readonly bool $interactive,
        private readonly string $answer,
    ) {
        $stream = fopen('php://memory', 'r');
        parent::__construct(
            new Output(fopen('php://memory', 'w+b'), fopen('php://memory', 'w+b')),
            $stream,
        );
    }

    public function is_interactive(): bool
    {
        return $this->interactive;
    }

    public function read_line(): string
    {
        return $this->answer;
    }
}

final class MigrationsBackwardCompatibilityTest extends TestCase
{
    private string $tmp_dir;
    private string $app_migrations_dir;
    private string $framework_migrations_dir;
    private string $fixture_dir;
    private Output $output;
    private Migrations $migrations;
    private ?\PDO $pdo = null;
    private ?string $db_prefix = null;
    private mixed $original_db_config = null;
    private mixed $original_db = null;
    private mixed $original_migrations = null;
    private mixed $original_core_migrations = null;
    private mixed $original_session_driver = null;
    private mixed $original_session_config = null;
    private mixed $original_queue_driver = null;
    private mixed $original_mutex = null;
    private mixed $original_mutex_driver = null;

    protected function setUp(): void
    {
        $this->tmp_dir = TempPath::make_dir('atomic_legacy_migrations_test_');
        $this->app_migrations_dir = $this->tmp_dir . 'app' . DIRECTORY_SEPARATOR;
        $this->framework_migrations_dir = $this->tmp_dir . 'framework' . DIRECTORY_SEPARATOR;
        mkdir($this->app_migrations_dir, 0755, true);
        mkdir($this->framework_migrations_dir, 0755, true);
        mkdir($this->framework_migrations_dir . 'initial' . DIRECTORY_SEPARATOR, 0755, true);
        mkdir($this->framework_migrations_dir . 'updates' . DIRECTORY_SEPARATOR, 0755, true);

        $this->fixture_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;

        $atomic = App::instance();
        $this->original_db_config = $atomic->get('DB_CONFIG');
        $this->original_db = $atomic->get('DB');
        $this->original_migrations = $atomic->get('MIGRATIONS');
        $this->original_core_migrations = $atomic->get('MIGRATIONS_CORE');
        $this->original_session_driver = $atomic->get('SESSION_DRIVER');
        $this->original_session_config = $atomic->get('SESSION_CONFIG');
        $this->original_queue_driver = $atomic->get('QUEUE_DRIVER');
        $this->original_mutex = $atomic->get('MUTEX');
        $this->original_mutex_driver = $atomic->get('MUTEX_DRIVER');
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
                'telemetry', 'jobs_completed', 'jobs_failed', 'jobs', 'mutex_locks',
                'sessions', 'options', 'meta', 'migrations',
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
        $atomic->set('SESSION_DRIVER', $this->original_session_driver);
        $atomic->set('SESSION_CONFIG', $this->original_session_config);
        $atomic->set('QUEUE_DRIVER', $this->original_queue_driver);
        $atomic->set('MUTEX', $this->original_mutex);
        $atomic->set('MUTEX_DRIVER', $this->original_mutex_driver);
        ReflectionHelper::set(PluginManager::class, 'instance', null);
        TempPath::remove($this->tmp_dir);
    }

    public function test_old_ledger_is_prepared_without_rerunning_applied_fixture(): void
    {
        $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();

        $this->assertTrue($this->migrations->db());
        $columns = $this->ledger_columns();
        $this->assertArrayHasKey('source', $columns);
        $this->assertArrayHasKey('checksum', $columns);

        $this->migrations->migrate();

        $rows = $this->ledger_rows();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['source']);
        $this->assertNull($rows[0]['checksum']);
        $this->assertStringNotContainsString('must not run again', $this->stderr());
    }

    public function test_upgrade_adopts_legacy_application_fixture_with_checksum_baseline(): void
    {
        $path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();

        $this->assertTrue($this->migrations->upgrade());

        $rows = $this->ledger_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('app', $rows[0]['source']);
        $this->assertSame($this->checksum($path), $rows[0]['checksum']);
    }

    public function test_upgrade_ignores_checksum_changes_in_already_upgraded_records(): void
    {
        $path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();

        $this->assertTrue($this->migrations->upgrade());
        $original_checksum = $this->ledger_rows()[0]['checksum'];
        file_put_contents($path, (string)file_get_contents($path) . PHP_EOL . '// changed');

        $this->assertTrue($this->migrations->upgrade());

        $this->assertSame($original_checksum, $this->ledger_rows()[0]['checksum']);
        $this->assertStringNotContainsString('checksum mismatch', strtolower($this->stderr()));
    }

    public function test_legacy_mode_displays_a_visible_upgrade_notice(): void
    {
        $this->create_legacy_ledger();

        $this->migrations->migrate();

        $stderr = $this->stderr();
        $this->assertStringContainsString('Migration history is in legacy mode', $stderr);
        $this->assertStringContainsString('php atomic migrations/upgrade --dry-run', $stderr);
        $this->assertStringContainsString('php atomic migrations/upgrade', $stderr);
        $this->assertStringContainsString('+', $stderr);
    }

    public function test_upgrade_makes_adopted_history_columns_not_nullable(): void
    {
        $path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();

        $this->assertTrue($this->migrations->upgrade());

        $columns = $this->ledger_columns();
        $this->assertSame('NO', $columns['source']['Null']);
        $this->assertSame('NO', $columns['checksum']['Null']);
        $this->assertSame($this->checksum($path), $this->ledger_rows()[0]['checksum']);
    }

    public function test_dry_run_reports_adoption_without_changing_legacy_ledger(): void
    {
        $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();

        $this->assertTrue($this->migrations->upgrade(true));

        $rows = $this->ledger_rows();
        $this->assertNull($rows[0]['source']);
        $this->assertNull($rows[0]['checksum']);
        $this->assertStringContainsString('Would adopt', $this->stdout());
        $this->assertStringContainsString('Migration upgrade preview', $this->stdout());
        $this->assertStringContainsString('Legacy records to adopt: 1', $this->stdout());
        $this->assertStringContainsString('Migrations executed by upgrade: 0', $this->stdout());
        $this->assertStringContainsString('No database changes were made', $this->stdout());
        $this->assertStringContainsString('Columns still nullable: source, checksum', $this->stdout());
        $this->assertStringContainsString('Upgrade required: YES', $this->stdout());
        $this->assertStringContainsString('Dry run complete', $this->stderr());
    }

    public function test_rollback_allows_an_applied_migration_changed_after_adoption_with_confirmation(): void
    {
        $path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();
        $this->assertTrue($this->migrations->upgrade());
        file_put_contents($path, (string)file_get_contents($path) . PHP_EOL . '// changed');
        $this->migrations = $this->migrations_with_prompt(true, 'yes');

        $this->migrations->rollback(1);

        $this->assertCount(0, $this->ledger_rows());
        $this->assertStringContainsString('checksum mismatch', strtolower($this->stderr()));
    }

    public function test_legacy_published_framework_fixture_is_adopted_and_never_rerun(): void
    {
        $canonical = $this->copy_fixture('atomic_fixture_framework.php', $this->framework_migrations_dir . 'initial' . DIRECTORY_SEPARATOR);
        copy($canonical, $this->app_migrations_dir . '20240101000002_atomic_fixture_framework.php');
        $this->create_legacy_ledger('20240101000002_atomic_fixture_framework');

        $this->migrations->migrate();
        $this->assertStringNotContainsString('must not run again', $this->stderr());

        $this->assertTrue($this->migrations->upgrade());
        $rows = $this->ledger_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('framework', $rows[0]['source']);
        $this->assertSame('atomic_fixture_framework', $rows[0]['migration']);
        $this->assertSame($this->checksum($canonical), $rows[0]['checksum']);

        $this->migrations->migrate();
        $this->assertCount(1, $this->ledger_rows());
        $this->assertStringNotContainsString('must not run again', $this->stderr());
    }

    public function test_missing_legacy_fixture_stays_unverified_when_upgrade_fails(): void
    {
        $this->create_legacy_ledger('20240101000009_missing_fixture');

        $this->assertFalse($this->migrations->upgrade());

        $rows = $this->ledger_rows();
        $this->assertNull($rows[0]['source']);
        $this->assertNull($rows[0]['checksum']);
        $this->assertStringContainsString('unavailable', $this->stderr());
    }

    public function test_migrate_allows_changed_applied_fixture_after_confirmation(): void
    {
        $path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();
        $this->assertTrue($this->migrations->upgrade());
        file_put_contents($path, (string)file_get_contents($path) . PHP_EOL . '// changed');
        $this->copy_fixture('20240101000001_shared_source.php', $this->app_migrations_dir);
        $this->migrations = $this->migrations_with_prompt(true, 'yes');

        $this->migrations->migrate();

        $this->assertCount(2, $this->ledger_rows());
        $this->assertStringContainsString('checksum mismatch', strtolower($this->stderr()));
    }

    public function test_migrate_refuses_missing_checksummed_application_fixture(): void
    {
        $path = $this->copy_fixture('20240101000000_legacy_applied.php', $this->app_migrations_dir);
        $this->create_legacy_ledger();
        $this->assertTrue($this->migrations->upgrade());
        unlink($path);
        $this->copy_fixture('20240101000001_shared_source.php', $this->app_migrations_dir);

        $this->migrations->migrate();

        $this->assertCount(1, $this->ledger_rows());
        $this->assertStringContainsString('unavailable', strtolower($this->stderr()));
    }

    public function test_enabled_plugin_migration_runs_directly_from_its_owner(): void
    {
        $plugin_dir = $this->tmp_dir . 'plugin' . DIRECTORY_SEPARATOR;
        mkdir($plugin_dir, 0755, true);
        $path = $this->copy_fixture('20240101000001_shared_source.php', $plugin_dir);
        PluginManager::instance()->register(new FixtureMigrationPlugin($plugin_dir));

        $this->migrations->migrate();

        $rows = $this->ledger_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('plugin:test/fixture', $rows[0]['source']);
        $this->assertSame('20240101000001_shared_source', $rows[0]['migration']);
        $this->assertSame($this->checksum($path), $rows[0]['checksum']);
        $this->assertFileDoesNotExist($this->app_migrations_dir . '20240101000001_shared_source.php');
    }

    public function test_unmodified_published_copy_is_not_executed_as_a_second_app_migration(): void
    {
        $canonical = $this->copy_fixture('20240101000001_shared_source.php', $this->framework_migrations_dir . 'initial' . DIRECTORY_SEPARATOR);
        copy($canonical, $this->app_migrations_dir . '20240102000000_20240101000001_shared_source.php');

        $this->migrations->migrate();

        $rows = $this->ledger_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('framework', $rows[0]['source']);
        $this->assertSame('20240101000001_shared_source', $rows[0]['migration']);
    }

    public function test_modified_published_plugin_copy_requires_confirmation(): void
    {
        $plugin_dir = $this->tmp_dir . 'plugin' . DIRECTORY_SEPARATOR;
        mkdir($plugin_dir, 0755, true);
        $canonical = $this->copy_fixture('20240101000001_shared_source.php', $plugin_dir);
        $published = $this->app_migrations_dir . '20240102000000_20240101000001_shared_source.php';
        copy($canonical, $published);
        file_put_contents($published, (string)file_get_contents($published) . PHP_EOL . '// modified');
        PluginManager::instance()->register(new FixtureMigrationPlugin($plugin_dir));
        $this->migrations = $this->migrations_with_prompt(true, 'n');

        $this->migrations->migrate();

        $this->assertCount(0, $this->ledger_rows());
        $this->assertStringContainsString('contents differ', strtolower($this->stderr()));
        $this->assertStringContainsString('aborted', strtolower($this->stderr()));
    }

    public function test_modified_published_plugin_copy_runs_after_confirmation(): void
    {
        $plugin_dir = $this->tmp_dir . 'plugin' . DIRECTORY_SEPARATOR;
        mkdir($plugin_dir, 0755, true);
        $canonical = $this->copy_fixture('20240101000001_shared_source.php', $plugin_dir);
        $published = $this->app_migrations_dir . '20240102000000_20240101000001_shared_source.php';
        copy($canonical, $published);
        file_put_contents($published, (string)file_get_contents($published) . PHP_EOL . '// modified');
        PluginManager::instance()->register(new FixtureMigrationPlugin($plugin_dir));
        $this->migrations = $this->migrations_with_prompt(true, 'yes');

        $this->migrations->migrate();

        $this->assertSame(['app', 'plugin:test/fixture'], array_column($this->ledger_rows(), 'source'));
    }

    public function test_modified_published_plugin_copy_aborts_without_interactive_input(): void
    {
        $plugin_dir = $this->tmp_dir . 'plugin' . DIRECTORY_SEPARATOR;
        mkdir($plugin_dir, 0755, true);
        $canonical = $this->copy_fixture('20240101000001_shared_source.php', $plugin_dir);
        $published = $this->app_migrations_dir . '20240102000000_20240101000001_shared_source.php';
        copy($canonical, $published);
        file_put_contents($published, (string)file_get_contents($published) . PHP_EOL . '// modified');
        PluginManager::instance()->register(new FixtureMigrationPlugin($plugin_dir));
        $this->migrations = $this->migrations_with_prompt(false, '');

        $this->migrations->migrate();

        $this->assertCount(0, $this->ledger_rows());
        $this->assertStringContainsString('interactive', strtolower($this->stderr()));
    }

    private function boot_mysql_migrations(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('ext-pdo_mysql not loaded.');
        }

        $config = App::instance()->get('DB_CONFIG') ?? [];
        if (($config['driver'] ?? 'mysql') !== 'mysql') {
            self::markTestSkipped('Backward-compatible migration tests require MySQL.');
        }

        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (string)($config['port'] ?? '3306');
        $database = (string)($config['db'] ?? 'atomic_test');
        $username = (string)($config['username'] ?? 'atomic_test_user');
        $password = (string)($config['password'] ?? 'atomic_test_pass');
        $charset = (string)($config['charset'] ?? 'utf8mb4');

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $this->pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL is unavailable: ' . $e->getMessage());
        }

        $this->db_prefix = 'atomic_legacy_test_' . bin2hex(random_bytes(4)) . '_';
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

    private function migrations_with_prompt(bool $interactive, string $answer): Migrations
    {
        return (new MigrationsFactory(
            App::instance(),
            ConnectionManager::instance(),
            PluginManager::instance(),
            Filesystem::instance(),
        ))->create($this->output, new MigrationPromptInput($interactive, $answer));
    }

    private function create_legacy_ledger(?string $migration = null): void
    {
        $fixture = json_decode(
            (string)file_get_contents($this->fixture_dir . 'legacy-ledger.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $table = $this->quote_identifier($this->db_prefix . 'migrations');
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
        $statement->execute([$migration ?? $fixture['migration'], $fixture['batch_uuid'], $fixture['applied_at']]);
    }

    private function set_subsystem_drivers(string $session, string $queue, string $mutex): void
    {
        $atomic = App::instance();
        $atomic->set('SESSION_DRIVER', $session);
        $config = is_array($this->original_session_config) ? $this->original_session_config : [];
        $config['driver'] = $session;
        $atomic->set('SESSION_CONFIG', $config);
        $atomic->set('QUEUE_DRIVER', $queue);
        $mutex_config = is_array($this->original_mutex) ? $this->original_mutex : [];
        $mutex_config['driver'] = $mutex;
        $atomic->set('MUTEX', $mutex_config);
        $atomic->set('MUTEX_DRIVER', $mutex);
    }

    private function copy_fixture(string $name, string $destination): string
    {
        $target = $destination . $name;
        copy($this->fixture_dir . $name, $target);
        return $target;
    }

    private function ledger_columns(): array
    {
        $statement = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quote_identifier($this->db_prefix . 'migrations'));
        $columns = $statement->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($columns, null, 'Field');
    }

    private function ledger_rows(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, source, migration, checksum, batch_uuid, applied_at FROM '
            . $this->quote_identifier($this->db_prefix . 'migrations') . ' ORDER BY id ASC'
        );
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function checksum(string $path): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", (string)file_get_contents($path));
        return hash('sha256', $contents);
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
