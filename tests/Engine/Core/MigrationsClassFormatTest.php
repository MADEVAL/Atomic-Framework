<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations\Migration;
use Engine\Atomic\Core\Migrations\MigrationContext;
use Engine\Atomic\Core\Migrations\MigrationDirection;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use PHPUnit\Framework\TestCase;
use Tests\Support\StreamCapture;
use Tests\Support\TempPath;

final class MigrationsClassFormatTest extends TestCase
{
    private string $tmp_dir;
    private Output $output;
    private MigrationExecutor $executor;

    protected function setUp(): void
    {
        $this->tmp_dir = TempPath::make_dir('atomic_migration_class_format_');
        $this->output = new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b'));
        $this->executor = new MigrationExecutor(
            new MigrationContext(App::instance(), ConnectionManager::instance(), $this->output)
        );
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->tmp_dir);
    }

    private function captured_stdout(): string
    {
        return StreamCapture::read($this->output->stdout(), true);
    }

    /** @return array{source: string, migration: string, path: string, checksum: string} */
    private function descriptor(string $migration, string $path): array
    {
        return [
            'source' => 'app',
            'migration' => $migration,
            'path' => $path,
            'checksum' => str_repeat('a', 64),
        ];
    }

    private function write_class_migration(string $file, string $up_body, string $down_body): string
    {
        $path = $this->tmp_dir . $file;
        file_put_contents($path, str_replace(
            ['{{UP}}', '{{DOWN}}'],
            [$up_body, $down_body],
            <<<'PHP'
            <?php
            declare(strict_types=1);

            if (!defined('ATOMIC_START')) exit;

            use Engine\Atomic\Core\Migrations\Migration;

            return new class extends Migration {
                public function up(): void
                {
                    {{UP}}
                }

                public function down(): void
                {
                    {{DOWN}}
                }
            };
            PHP,
        ));

        return $path;
    }

    private function require_mysql(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('ext-pdo_mysql not loaded - cannot verify typed database objects.');
        }
        $config = (array)App::instance()->get('DB_CONFIG');
        if (($config['driver'] ?? 'mysql') !== 'mysql') {
            self::markTestSkipped('MySQL is required to verify typed database objects.');
        }
        try {
            new \PDO(
                'mysql:host=' . ($config['host'] ?? '127.0.0.1') . ';port=' . ($config['port'] ?? '3306')
                    . ';dbname=' . ($config['db'] ?? 'atomic_test'),
                (string)($config['username'] ?? 'atomic_test_user'),
                (string)($config['password'] ?? 'atomic_test_pass'),
                [\PDO::ATTR_TIMEOUT => 3, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL is unavailable: ' . $e->getMessage());
        }
        ConnectionManager::instance()->close_sql();
    }

    public function test_executor_runs_class_format_migration_up_and_down(): void
    {
        $path = $this->write_class_migration(
            '20260101000000_class_migration.php',
            "\$this->output()->writeln('class up ran');",
            "\$this->output()->writeln('class down ran');",
        );

        $migration = $this->descriptor('20260101000000_class_migration', $path);
        $this->executor->up($migration);
        $this->executor->down($migration);

        $stdout = $this->captured_stdout();
        self::assertStringContainsString('class up ran', $stdout);
        self::assertStringContainsString('class down ran', $stdout);
    }

    public function test_executor_runs_legacy_array_migrations_unchanged(): void
    {
        $path = $this->tmp_dir . '20260101000001_legacy_migration.php';
        file_put_contents($path, <<<'PHP'
        <?php
        return [
            'up' => static function (): void {
                $GLOBALS['atomic_migration_legacy_events'][] = 'legacy up';
            },
            'down' => static function (): void {
                $GLOBALS['atomic_migration_legacy_events'][] = 'legacy down';
            },
        ];
        PHP);
        $migration = $this->descriptor('20260101000001_legacy_migration', $path);

        try {
            $this->executor->up($migration);
            $this->executor->down($migration);
            self::assertSame(['legacy up', 'legacy down'], $GLOBALS['atomic_migration_legacy_events'] ?? []);
        } finally {
            unset($GLOBALS['atomic_migration_legacy_events']);
        }
    }

    public function test_executor_reports_legacy_false_return_as_failure(): void
    {
        $path = $this->tmp_dir . '20260101000002_legacy_false.php';
        file_put_contents($path, '<?php return [\'up\' => static fn (): bool => false];');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Migration 'app:20260101000002_legacy_false' returned failure.");
        $this->executor->up($this->descriptor('20260101000002_legacy_false', $path));
    }

    public function test_executor_rejects_unrecognized_definitions(): void
    {
        $path = $this->tmp_dir . '20260101000003_unrecognized.php';
        file_put_contents($path, '<?php return new stdClass();');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid migration structure in {$path}.");
        $this->executor->up($this->descriptor('20260101000003_unrecognized', $path));
    }

    public function test_class_migration_failures_are_wrapped_with_migration_identity(): void
    {
        $path = $this->write_class_migration(
            '20260101000004_failing_migration.php',
            "throw new \\DomainException('boom');",
            '',
        );

        try {
            $this->executor->up($this->descriptor('20260101000004_failing_migration', $path));
            self::fail('The executor was expected to report the migration failure.');
        } catch (\RuntimeException $e) {
            self::assertSame("Migration 'app:20260101000004_failing_migration' failed: boom", $e->getMessage());
            self::assertInstanceOf(\DomainException::class, $e->getPrevious());
        }
    }

    public function test_class_migration_cannot_run_without_a_bound_context(): void
    {
        $path = $this->write_class_migration(
            '20260101000005_unbound_migration.php',
            "\$this->output()->writeln('never reached');",
            '',
        );

        $migration = require $path;
        self::assertInstanceOf(Migration::class, $migration);

        $this->expectException(\LogicException::class);
        $migration->up();
    }

    public function test_class_migration_run_dispatches_the_requested_direction(): void
    {
        $path = $this->write_class_migration(
            '20260101000006_direction_migration.php',
            "\$this->output()->writeln('up direction');",
            "\$this->output()->writeln('down direction');",
        );

        $migration = require $path;
        self::assertInstanceOf(Migration::class, $migration);
        $context = new MigrationContext(App::instance(), ConnectionManager::instance(), $this->output);

        $migration->run(MigrationDirection::DOWN, $context);

        self::assertStringContainsString('down direction', $this->captured_stdout());
    }

    public function test_class_migration_context_exposes_typed_database_objects(): void
    {
        $this->require_mysql();

        $path = $this->write_class_migration(
            '20260101000007_context_types.php',
            "\$GLOBALS['atomic_migration_context_types'] = ["
            . "get_class(\$this->db()), "
            . "get_class(\$this->schema()), "
            . "\$this->app() === \\Engine\\Atomic\\Core\\App::instance()"
            . "];",
            '',
        );

        try {
            $this->executor->up($this->descriptor('20260101000007_context_types', $path));
            self::assertSame([
                \DB\SQL::class,
                Schema::class,
                true,
            ], $GLOBALS['atomic_migration_context_types'] ?? null);
        } finally {
            unset($GLOBALS['atomic_migration_context_types']);
        }
    }

    public function test_class_migration_table_prepends_the_configured_prefix(): void
    {
        $app = App::instance();
        $original = $app->get('DB_CONFIG.prefix');
        $app->set('DB_CONFIG.prefix', 'tst_');

        $path = $this->write_class_migration(
            '20260101000008_table_prefix.php',
            "\$this->output()->writeln(\$this->table('users'));",
            '',
        );

        try {
            $this->executor->up($this->descriptor('20260101000008_table_prefix', $path));
            self::assertSame('tst_users' . PHP_EOL, $this->captured_stdout());
        } finally {
            $app->set('DB_CONFIG.prefix', $original);
        }
    }

    public function test_class_migration_has_table_reports_existing_tables(): void
    {
        $this->require_mysql();

        $table = (string)App::instance()->get('DB_CONFIG.prefix') . 'migration_has_table_probe';
        $path = $this->write_class_migration(
            '20260101000009_has_table.php',
            "\$this->db()->exec('CREATE TABLE IF NOT EXISTS `{$table}` (id INT)');"
            . "\$GLOBALS['atomic_migration_has_table'] = ["
            . "\$this->has_table(\$this->table('migration_has_table_probe')), "
            . "\$this->has_table(\$this->table('migration_has_table_missing'))"
            . "];",
            "\$this->db()->exec('DROP TABLE IF EXISTS `{$table}`');",
        );

        try {
            $this->executor->up($this->descriptor('20260101000009_has_table', $path));
            self::assertSame([true, false], $GLOBALS['atomic_migration_has_table'] ?? null);
        } finally {
            $this->executor->down($this->descriptor('20260101000009_has_table', $path));
            unset($GLOBALS['atomic_migration_has_table']);
        }
    }
}
