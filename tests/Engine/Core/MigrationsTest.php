<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations;
use PHPUnit\Framework\TestCase;

// ── Test plugins ──────────────────────────────────────────────

class MigrationsTestPlugin extends Plugin
{
    private string $plugin_name;
    private array $deps;
    private ?string $migrations_path;

    public function __construct(string $name, array $deps = [], ?string $migrations_path = null)
    {
        $this->plugin_name = $name;
        $this->deps = $deps;
        $this->migrations_path = $migrations_path;
        parent::__construct();
    }

    protected function get_name(): string { return $this->plugin_name; }
    public function get_dependencies(): array { return $this->deps; }
    public function get_migrations_path(): ?string { return $this->migrations_path; }
}

class MigrationsTestDependencyPlugin extends MigrationsTestPlugin {}
class MigrationsTestMainPlugin extends MigrationsTestPlugin {}
class MigrationsTestCycleAPlugin extends MigrationsTestPlugin {}
class MigrationsTestCycleBPlugin extends MigrationsTestPlugin {}
class MigrationsTestDisabledDependencyPlugin extends MigrationsTestPlugin {}
class MigrationsTestMissingDependencyPlugin extends MigrationsTestPlugin {}
class MigrationsTestInvalidDependencyPlugin extends MigrationsTestPlugin {}
class MigrationsTestPfpDependencyPlugin extends MigrationsTestPlugin {}
class MigrationsTestPfpMainPlugin extends MigrationsTestPlugin {}

// ── MigrationsTest ────────────────────────────────────────────

class MigrationsTest extends TestCase
{
    private string $tmp_dir;
    private string $migrations_dir;
    private Output $output;
    private Migrations $migrations;
    private ?\PDO $pdo = null;
    private ?string $db_prefix = null;
    private mixed $original_db_config = null;
    private mixed $original_db = null;

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_migrations_test_' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($this->tmp_dir, 0755, true);

        $this->migrations_dir = $this->tmp_dir . 'migrations' . DIRECTORY_SEPARATOR;
        mkdir($this->migrations_dir, 0755, true);

        App::instance()->set('MIGRATIONS', $this->migrations_dir);
        $this->original_db_config = App::instance()->get('DB_CONFIG');
        $this->original_db = App::instance()->get('DB');

        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');
        $this->output = new Output($stdout, $stderr);
        $this->migrations = $this->create_migrations_mock(false);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->db_prefix !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quote_identifier($this->db_prefix . 'migration_lifecycle_log'));
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quote_identifier($this->db_prefix . 'migration_failure_log'));
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quote_identifier($this->db_prefix . 'migrations'));
        }

        ConnectionManager::instance()->close_sql();
        App::instance()->set('DB_CONFIG', $this->original_db_config);
        App::instance()->set('DB', $this->original_db);
        $this->pdo = null;
        $this->db_prefix = null;

        $this->rmdir_recursive($this->tmp_dir);
    }

    private function rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . $item;
            is_dir($path) ? $this->rmdir_recursive($path . DIRECTORY_SEPARATOR) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function stdout(): string
    {
        rewind($this->output->stdout());
        return stream_get_contents($this->output->stdout());
    }

    private function stderr(): string
    {
        rewind($this->output->stderr());
        return stream_get_contents($this->output->stderr());
    }

    private function resetPluginManager(): PluginManager
    {
        $ref = new \ReflectionClass(PluginManager::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
        return PluginManager::instance();
    }

    private function boot_mysql_migrations(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('ext-pdo_mysql not loaded - cannot run migration lifecycle test.');
        }

        $config = App::instance()->get('DB_CONFIG') ?? [];
        $driver = (string)($config['driver'] ?? 'mysql');
        if ($driver !== 'mysql') {
            self::markTestSkipped('Migration lifecycle test requires MySQL DB_CONFIG.driver.');
        }

        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (string)($config['port'] ?? '3306');
        $db = (string)($config['db'] ?? 'atomic_test');
        $username = (string)($config['username'] ?? 'atomic_test_user');
        $password = (string)($config['password'] ?? 'atomic_test_pass');
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $collation = (string)($config['collation'] ?? 'utf8mb4_general_ci');

        [$pdo, $effective_host, $error] = $this->connect_pdo_with_fallback($host, $port, $db, $username, $password, $charset);
        if ($pdo === null) {
            self::markTestSkipped('MySQL connection unavailable for migration lifecycle test: ' . $error);
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $effective_host, $port, $db, $charset);
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db_prefix = 'atomic_migration_test_' . bin2hex(random_bytes(4)) . '_';

        $config['driver'] = 'mysql';
        $config['host'] = $effective_host;
        $config['port'] = $port;
        $config['db'] = $db;
        $config['username'] = $username;
        $config['password'] = $password;
        $config['charset'] = $charset;
        $config['collation'] = $collation;
        $config['prefix'] = $this->db_prefix;

        App::instance()->set('DB_CONFIG', $config);
        App::instance()->set('DB', new \DB\SQL($dsn, $username, $password));
        ConnectionManager::instance()->close_sql();
        $this->migrations = new Migrations($this->output);
    }

    private function connect_pdo_with_fallback(
        string $db_host,
        string $db_port,
        string $db_name,
        string $db_user,
        string $db_password,
        string $db_charset,
    ): array {
        $hosts = [$db_host];
        if ($this->is_loopback_host($db_host)) {
            $hosts[] = 'mysql';
        }
        $hosts = array_values(array_unique($hosts));

        $last_error = 'unknown database connection error';
        foreach ($hosts as $host) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $db_port, $db_name, $db_charset);

            try {
                return [new \PDO($dsn, $db_user, $db_password), $host, null];
            } catch (\Throwable $e) {
                $last_error = $e->getMessage();
            }
        }

        return [null, $db_host, $last_error];
    }

    private function is_loopback_host(string $host): bool
    {
        return in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function table_exists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private function migration_rows(): array
    {
        $stmt = $this->pdo->query('SELECT migration, batch_uuid FROM ' . $this->quote_identifier($this->db_prefix . 'migrations') . ' ORDER BY id ASC');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function log_events(): array
    {
        $stmt = $this->pdo->query('SELECT event FROM ' . $this->quote_identifier($this->db_prefix . 'migration_lifecycle_log') . ' ORDER BY id ASC');
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function write_lifecycle_migration(string $file, array $up_sql, array $down_sql): void
    {
        $up_statements = '';
        foreach ($up_sql as $sql) {
            $up_statements .= "\n            \$db->exec(" . var_export($sql, true) . ");";
        }

        $down_statements = '';
        foreach ($down_sql as $sql) {
            $down_statements .= "\n            \$db->exec(" . var_export($sql, true) . ");";
        }

        $content = <<<PHP
        <?php
        return [
            'up' => function () {
                \$db = \\Engine\\Atomic\\Core\\ConnectionManager::instance()->get_db();
                {$up_statements}
            },
            'down' => function () {
                \$db = \\Engine\\Atomic\\Core\\ConnectionManager::instance()->get_db();
                {$down_statements}
            },
        ];
        PHP;

        file_put_contents($this->migrations_dir . $file, $content);
    }

    /** Invoke a private method via reflection (supports by-ref args) */
    private function invoke_method(object $object, string $name, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $name);
        $ref_args = [];
        foreach ($args as $k => &$v) {
            $ref_args[$k] = &$v;
        }
        return $ref->invokeArgs($object, $ref_args);
    }

    /**
     * Create a Migrations instance, optionally with db() mocked to return true.
     * When $fake_db is true, the mock also overrides create() to do a simple
     * file write without hitting the database, while still calling publish()'s
     * dedup logic normally.
     */
    private function create_migrations_mock(bool $fake_db): Migrations
    {
        if (!$fake_db) {
            return new Migrations($this->output);
        }

        $mock = $this->getMockBuilder(Migrations::class)
            ->setConstructorArgs([$this->output])
            ->onlyMethods(['db', 'create'])
            ->getMock();

        $mock->method('db')->willReturn(true);
        $mock->method('create')->willReturnCallback(
            function (string $name, string $template = '') {
                $atomic = App::instance();
                $dir = $atomic->get('MIGRATIONS');
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $ts = $this->invoke_method(new Migrations($this->output), 'next_migration_timestamp', [$dir]);
                $file = $dir . $ts . '_' . $name . '.php';
                file_put_contents($file, $template ?: '<?php // stub');
            }
        );

        return $mock;
    }

    // ── find_plugin ────────────────────────────────────────────

    public function test_find_plugin_exact_match(): void
    {
        $manager = $this->resetPluginManager();
        $plugin = new MigrationsTestPlugin('my-plugin');
        $manager->register($plugin);

        $result = $this->invoke_method($this->migrations, 'find_plugin', [$manager, 'my-plugin']);
        $this->assertSame($plugin, $result);
    }

    public function test_find_plugin_case_insensitive(): void
    {
        $manager = $this->resetPluginManager();
        $plugin = new MigrationsTestPlugin('My-Plugin');
        $manager->register($plugin);

        $result = $this->invoke_method($this->migrations, 'find_plugin', [$manager, 'my-plugin']);
        $this->assertSame($plugin, $result);
    }

    public function test_find_plugin_not_found(): void
    {
        $manager = $this->resetPluginManager();
        $result = $this->invoke_method($this->migrations, 'find_plugin', [$manager, 'nonexistent']);
        $this->assertNull($result);
    }

    // ── next_migration_timestamp ───────────────────────────────

    public function test_next_migration_timestamp_no_existing_files(): void
    {
        $ts = $this->invoke_method($this->migrations, 'next_migration_timestamp', [$this->migrations_dir]);
        $this->assertMatchesRegularExpression('/^\d{14}$/', $ts);
        $this->assertSame(date('YmdHis'), $ts);
    }

    public function test_next_migration_timestamp_increments_on_collision(): void
    {
        $now = date('YmdHis');
        touch($this->migrations_dir . $now . '_test.php');

        $ts = $this->invoke_method($this->migrations, 'next_migration_timestamp', [$this->migrations_dir]);
        $this->assertMatchesRegularExpression('/^\d{14}$/', $ts);

        $expected = \DateTimeImmutable::createFromFormat('YmdHis', $now)->modify('+1 second')->format('YmdHis');
        $this->assertSame($expected, $ts);
    }

    public function test_next_migration_timestamp_multiple_collisions(): void
    {
        $now = date('YmdHis');
        $plus_one = \DateTimeImmutable::createFromFormat('YmdHis', $now)->modify('+1 second')->format('YmdHis');

        touch($this->migrations_dir . $now . '_a.php');
        touch($this->migrations_dir . $plus_one . '_b.php');

        $ts = $this->invoke_method($this->migrations, 'next_migration_timestamp', [$this->migrations_dir]);
        $this->assertSame(
            \DateTimeImmutable::createFromFormat('YmdHis', $now)->modify('+2 seconds')->format('YmdHis'),
            $ts
        );
    }

    public function test_next_migration_timestamp_skips_older_files(): void
    {
        $past = \DateTimeImmutable::createFromFormat('YmdHis', date('YmdHis'))->modify('-10 seconds')->format('YmdHis');
        touch($this->migrations_dir . $past . '_old.php');

        $ts = $this->invoke_method($this->migrations, 'next_migration_timestamp', [$this->migrations_dir]);
        $this->assertSame(date('YmdHis'), $ts);
    }

    // ── publish_plugin_migrations (private) ────────────────────

    /** @return Migrations  A mock that can actually publish files without DB */
    private function publishable_migrations(): Migrations
    {
        return $this->create_migrations_mock(true);
    }

    public function test_publish_plugin_migrations_no_dependencies(): void
    {
        $manager = $this->resetPluginManager();
        $migrations = $this->publishable_migrations();

        $plugin_migrations_dir = $this->tmp_dir . 'plugin_migrations' . DIRECTORY_SEPARATOR;
        mkdir($plugin_migrations_dir, 0755, true);
        file_put_contents($plugin_migrations_dir . '20250101000000_init.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $plugin = new MigrationsTestPlugin('standalone', [], $plugin_migrations_dir);
        $manager->register($plugin);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($migrations, 'publish_plugin_migrations', [$manager, $plugin, &$processed, [], &$published]);

        $this->assertTrue($result);
        $this->assertSame(1, $published);

        $published_files = glob($this->migrations_dir . '*.php');
        $this->assertCount(1, $published_files);
        $this->assertStringEndsWith('_init.php', basename($published_files[0]));
    }

    public function test_publish_plugin_migrations_with_dependencies(): void
    {
        $manager = $this->resetPluginManager();
        $migrations = $this->publishable_migrations();

        $dep_migrations_dir = $this->tmp_dir . 'dep_migrations' . DIRECTORY_SEPARATOR;
        mkdir($dep_migrations_dir, 0755, true);
        file_put_contents($dep_migrations_dir . '20250101000000_dep_init.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $dep_plugin = new MigrationsTestDependencyPlugin('dependency', [], $dep_migrations_dir);
        $manager->register($dep_plugin);

        $main_migrations_dir = $this->tmp_dir . 'main_migrations' . DIRECTORY_SEPARATOR;
        mkdir($main_migrations_dir, 0755, true);
        file_put_contents($main_migrations_dir . '20250102000000_main_init.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $main_plugin = new MigrationsTestMainPlugin('main', [MigrationsTestDependencyPlugin::class], $main_migrations_dir);
        $manager->register($main_plugin);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($migrations, 'publish_plugin_migrations', [$manager, $main_plugin, &$processed, [], &$published]);

        $this->assertTrue($result);
        $this->assertSame(2, $published);

        $published_files = glob($this->migrations_dir . '*.php');
        $this->assertCount(2, $published_files);
    }

    public function test_publish_plugin_migrations_dependency_cycle_detected(): void
    {
        $manager = $this->resetPluginManager();

        $dir_a = $this->tmp_dir . 'cycle_a' . DIRECTORY_SEPARATOR;
        mkdir($dir_a, 0755, true);
        file_put_contents($dir_a . '20250101000000_a_init.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $dir_b = $this->tmp_dir . 'cycle_b' . DIRECTORY_SEPARATOR;
        mkdir($dir_b, 0755, true);
        file_put_contents($dir_b . '20250101000000_b_init.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $plugin_a = new MigrationsTestCycleAPlugin('plugin-a', [MigrationsTestCycleBPlugin::class], $dir_a);
        $plugin_b = new MigrationsTestCycleBPlugin('plugin-b', [MigrationsTestCycleAPlugin::class], $dir_b);
        $manager->register($plugin_a);
        $manager->register($plugin_b);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($this->migrations, 'publish_plugin_migrations', [$manager, $plugin_a, &$processed, [], &$published]);

        $this->assertFalse($result);
        $this->assertStringContainsString('cycle', $this->stderr());
    }

    public function test_publish_plugin_migrations_disabled_dependency(): void
    {
        $manager = $this->resetPluginManager();

        $main_migrations_dir = $this->tmp_dir . 'main_migrations2' . DIRECTORY_SEPARATOR;
        mkdir($main_migrations_dir, 0755, true);
        file_put_contents($main_migrations_dir . '20250101000000_main.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $dep_plugin = new MigrationsTestDisabledDependencyPlugin('disabled-dep', [], null);
        $dep_plugin->set_enabled(false);
        $manager->register($dep_plugin);

        $main_plugin = new MigrationsTestMainPlugin('main2', [MigrationsTestDisabledDependencyPlugin::class], $main_migrations_dir);
        $manager->register($main_plugin);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($this->migrations, 'publish_plugin_migrations', [$manager, $main_plugin, &$processed, [], &$published]);

        $this->assertFalse($result);
        $this->assertStringContainsString('disabled', $this->stderr());
    }

    public function test_publish_plugin_migrations_missing_dependency(): void
    {
        $manager = $this->resetPluginManager();

        $main_migrations_dir = $this->tmp_dir . 'main_migrations3' . DIRECTORY_SEPARATOR;
        mkdir($main_migrations_dir, 0755, true);
        file_put_contents($main_migrations_dir . '20250101000000_main.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $main_plugin = new MigrationsTestMainPlugin('main3', [MigrationsTestMissingDependencyPlugin::class], $main_migrations_dir);
        $manager->register($main_plugin);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($this->migrations, 'publish_plugin_migrations', [$manager, $main_plugin, &$processed, [], &$published]);

        $this->assertFalse($result);
        $this->assertStringContainsString('not registered', $this->stderr());
    }

    public function test_publish_plugin_migrations_missing_dependency_class(): void
    {
        $manager = $this->resetPluginManager();
        $main_plugin = new MigrationsTestMainPlugin('main4', ['Tests\\Engine\\Core\\NoSuchPlugin'], null);
        $manager->register($main_plugin);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($this->migrations, 'publish_plugin_migrations', [$manager, $main_plugin, &$processed, [], &$published]);

        $this->assertFalse($result);
        $this->assertStringContainsString('requires missing plugin class', $this->stderr());
    }

    public function test_publish_plugin_migrations_invalid_dependency_class(): void
    {
        $manager = $this->resetPluginManager();
        $main_plugin = new MigrationsTestMainPlugin('main5', [\stdClass::class], null);
        $manager->register($main_plugin);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($this->migrations, 'publish_plugin_migrations', [$manager, $main_plugin, &$processed, [], &$published]);

        $this->assertFalse($result);
        $this->assertStringContainsString('must extend', $this->stderr());
    }

    public function test_publish_plugin_migrations_no_migrations_path(): void
    {
        $manager = $this->resetPluginManager();
        $plugin = new MigrationsTestPlugin('no-migrations', [], null);
        $manager->register($plugin);

        $published = 0;
        $processed = [];
        $result = $this->invoke_method($this->migrations, 'publish_plugin_migrations', [$manager, $plugin, &$processed, [], &$published]);

        $this->assertTrue($result);
        $this->assertSame(0, $published);
    }

    public function test_publish_plugin_migrations_already_processed_skips(): void
    {
        $manager = $this->resetPluginManager();
        $migrations = $this->publishable_migrations();

        $plugin_migrations_dir = $this->tmp_dir . 'skip_migrations' . DIRECTORY_SEPARATOR;
        mkdir($plugin_migrations_dir, 0755, true);
        file_put_contents($plugin_migrations_dir . '20250101000000_skip.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $plugin = new MigrationsTestPlugin('skip-plugin', [], $plugin_migrations_dir);
        $manager->register($plugin);

        $published = 0;
        $processed = ['skip-plugin' => true];
        $result = $this->invoke_method($migrations, 'publish_plugin_migrations', [$manager, $plugin, &$processed, [], &$published]);

        $this->assertTrue($result);
        $this->assertSame(0, $published);
    }

    // ── publish_from_plugin (public) ───────────────────────────

    public function test_publish_from_plugin_success(): void
    {
        $manager = $this->resetPluginManager();

        // Re-create $this->migrations as a publishable mock for this test
        $this->migrations = $this->publishable_migrations();

        $plugin_migrations_dir = $this->tmp_dir . 'pfp_migrations' . DIRECTORY_SEPARATOR;
        mkdir($plugin_migrations_dir, 0755, true);
        file_put_contents($plugin_migrations_dir . '20250101000000_pfp.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $plugin = new MigrationsTestPlugin('pfp-plugin', [], $plugin_migrations_dir);
        $manager->register($plugin);

        $this->migrations->publish_from_plugin('pfp-plugin');

        $this->assertStringContainsString('1 migration(s) processed', $this->stdout());
    }

    public function test_publish_from_plugin_not_found(): void
    {
        $manager = $this->resetPluginManager();

        $this->migrations->publish_from_plugin('nonexistent');

        $this->assertStringContainsString('not found', $this->stderr());
    }

    public function test_publish_from_plugin_with_dependencies(): void
    {
        $manager = $this->resetPluginManager();

        $this->migrations = $this->publishable_migrations();

        $dep_dir = $this->tmp_dir . 'pfp_dep' . DIRECTORY_SEPARATOR;
        mkdir($dep_dir, 0755, true);
        file_put_contents($dep_dir . '20250101000000_dep.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $main_dir = $this->tmp_dir . 'pfp_main' . DIRECTORY_SEPARATOR;
        mkdir($main_dir, 0755, true);
        file_put_contents($main_dir . '20250102000000_main.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $dep_plugin = new MigrationsTestPfpDependencyPlugin('pfp-dep', [], $dep_dir);
        $main_plugin = new MigrationsTestPfpMainPlugin('pfp-main', [MigrationsTestPfpDependencyPlugin::class], $main_dir);
        $manager->register($dep_plugin);
        $manager->register($main_plugin);

        $this->migrations->publish_from_plugin('pfp-main');

        $this->assertStringContainsString('2 migration(s) processed', $this->stdout());
        $this->assertStringContainsString('and dependencies', $this->stdout());
    }

    // ── create ──────────────────────────────────────────────────

    public function test_create_uses_next_available_timestamp(): void
    {
        $migrations = $this->publishable_migrations();
        $now = date('YmdHis');
        touch($this->migrations_dir . $now . '_existing.php');

        $migrations->create('new_migration', '<?php // created');

        $expected = \DateTimeImmutable::createFromFormat('YmdHis', $now)->modify('+1 second')->format('YmdHis');
        $this->assertFileExists($this->migrations_dir . $expected . '_new_migration.php');
    }

    /**
     * Covers the real migration system against MySQL: schema bootstrap, ordered
     * step-limited migrate, status output, batch rollback, and full rollback.
     */
    public function test_migration_lifecycle_against_mysql(): void
    {
        $this->boot_mysql_migrations();

        $log_table = $this->quote_identifier($this->db_prefix . 'migration_lifecycle_log');
        $this->write_lifecycle_migration(
            '20250101000000_create_lifecycle_log.php',
            [
                "CREATE TABLE {$log_table} (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, event VARCHAR(32) NOT NULL) ENGINE=InnoDB",
                "INSERT INTO {$log_table} (event) VALUES ('up_one')",
            ],
            ["DROP TABLE IF EXISTS {$log_table}"]
        );
        $this->write_lifecycle_migration(
            '20250101000001_insert_lifecycle_log.php',
            ["INSERT INTO {$log_table} (event) VALUES ('up_two')"],
            ["INSERT INTO {$log_table} (event) VALUES ('down_two')"]
        );

        $this->assertTrue($this->migrations->db());
        $this->assertTrue($this->table_exists($this->db_prefix . 'migrations'));

        $this->migrations->migrate(1);
        $this->assertSame(['up_one'], $this->log_events());
        $this->assertSame(['20250101000000_create_lifecycle_log'], array_column($this->migration_rows(), 'migration'));

        $this->migrations->status();
        $this->assertStringContainsString('20250101000000_create_lifecycle_log', $this->stdout());
        $this->assertStringContainsString('20250101000001_insert_lifecycle_log', $this->stdout());
        $this->assertStringContainsString('pending', $this->stdout());

        $this->migrations->migrate();
        $rows = $this->migration_rows();
        $this->assertSame(
            ['20250101000000_create_lifecycle_log', '20250101000001_insert_lifecycle_log'],
            array_column($rows, 'migration')
        );
        $this->assertSame(['up_one', 'up_two'], $this->log_events());

        $this->migrations->rollback('batch');
        $this->assertSame(['20250101000000_create_lifecycle_log'], array_column($this->migration_rows(), 'migration'));
        $this->assertSame(['up_one', 'up_two', 'down_two'], $this->log_events());

        $this->migrations->rollback('batch');
        $this->assertFalse($this->table_exists($this->db_prefix . 'migration_lifecycle_log'));
        $this->assertSame([], $this->migration_rows());
    }

    public function test_migration_failures_are_reported_and_not_recorded_against_mysql(): void
    {
        $this->boot_mysql_migrations();

        $log_table = $this->quote_identifier($this->db_prefix . 'migration_failure_log');
        $this->write_lifecycle_migration(
            '20250101000000_create_failure_log.php',
            [
                "CREATE TABLE {$log_table} (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, event VARCHAR(32) NOT NULL) ENGINE=InnoDB",
                "INSERT INTO {$log_table} (event) VALUES ('created')",
            ],
            ["DROP TABLE IF EXISTS {$log_table}"]
        );

        file_put_contents($this->migrations_dir . '20250101000001_returns_false.php', <<<'PHP'
        <?php
        return [
            'up' => fn () => false,
            'down' => fn () => true,
        ];
        PHP);

        file_put_contents($this->migrations_dir . '20250101000002_invalid_structure.php', <<<'PHP'
        <?php
        return ['down' => fn () => true];
        PHP);

        $this->migrations->migrate();
        $this->assertSame(['20250101000000_create_failure_log'], array_column($this->migration_rows(), 'migration'));
        $this->assertStringContainsString('returned failure', $this->stderr());

        unlink($this->migrations_dir . '20250101000001_returns_false.php');
        $this->migrations->migrate(1);
        $this->assertSame(['20250101000000_create_failure_log'], array_column($this->migration_rows(), 'migration'));
        $this->assertStringContainsString('Invalid migration structure', $this->stderr());
    }

    public function test_rollback_numeric_pops_latest_migration_only_against_mysql(): void
    {
        $this->boot_mysql_migrations();

        $log_table = $this->quote_identifier($this->db_prefix . 'migration_lifecycle_log');
        $this->write_lifecycle_migration(
            '20250101000000_create_lifecycle_log.php',
            [
                "CREATE TABLE {$log_table} (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, event VARCHAR(32) NOT NULL) ENGINE=InnoDB",
                "INSERT INTO {$log_table} (event) VALUES ('up_one')",
            ],
            ["DROP TABLE IF EXISTS {$log_table}"]
        );
        $this->write_lifecycle_migration(
            '20250101000001_insert_lifecycle_log.php',
            ["INSERT INTO {$log_table} (event) VALUES ('up_two')"],
            ["INSERT INTO {$log_table} (event) VALUES ('down_two')"]
        );

        $this->migrations->migrate();
        $this->migrations->rollback(1);

        $this->assertSame(['20250101000000_create_lifecycle_log'], array_column($this->migration_rows(), 'migration'));
        $this->assertSame(['up_one', 'up_two', 'down_two'], $this->log_events());
    }

    // ── publish ─────────────────────────────────────────────────

    public function test_publish_copies_source_content_with_unique_timestamp(): void
    {
        $this->migrations = $this->publishable_migrations();

        $source_dir = $this->tmp_dir . 'source_copy' . DIRECTORY_SEPARATOR;
        mkdir($source_dir, 0755, true);
        $source_file = $source_dir . 'copy_me.php';
        $content = "<?php return ['up' => fn() => true, 'down' => fn() => true];";
        file_put_contents($source_file, $content);

        $this->migrations->publish(substr($source_file, 0, -4));

        $published_files = glob($this->migrations_dir . '*_copy_me.php');
        $this->assertCount(1, $published_files);
        $this->assertSame($content, file_get_contents($published_files[0]));
    }

    public function test_publish_already_exists_skips(): void
    {
        // Create a source file named just "existing" (no timestamp prefix),
        // since publish() matches the name part after the timestamp in target files.
        $source_dir = $this->tmp_dir . 'source' . DIRECTORY_SEPARATOR;
        mkdir($source_dir, 0755, true);
        $source_file = $source_dir . 'existing.php';
        file_put_contents($source_file, "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        // Pre-create a target migration file whose name-part matches
        file_put_contents($this->migrations_dir . '20250101000000_existing.php', "<?php return ['up' => fn() => true, 'down' => fn() => true];");

        $this->migrations->publish(substr($source_file, 0, -4));

        $this->assertStringContainsString('already exists', $this->stderr());
    }

    public function test_publish_source_not_found(): void
    {
        $this->migrations->publish($this->tmp_dir . 'nonexistent');

        $this->assertStringContainsString('does not exist', $this->stderr());
    }

    // ── resolve_migration_file ──────────────────────────────────

    public function test_resolve_migration_file_returns_readable_file_inside_migrations_dir(): void
    {
        $file = $this->migrations_dir . '20250101000000_safe.php';
        file_put_contents($file, '<?php return [];');

        $resolved = $this->invoke_method($this->migrations, 'resolve_migration_file', [$this->migrations_dir, '20250101000000_safe']);

        $this->assertSame(realpath($file), $resolved);
    }

    public function test_resolve_migration_file_rejects_path_traversal(): void
    {
        file_put_contents($this->tmp_dir . 'outside.php', '<?php return [];');

        $this->expectException(\RuntimeException::class);
        $this->invoke_method($this->migrations, 'resolve_migration_file', [$this->migrations_dir, '../outside']);
    }
}
