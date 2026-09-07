<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\Migrations\LegacyMigrationAdopter;
use Engine\Atomic\Core\Migrations\MigrationCatalog;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use Engine\Atomic\Core\Migrations\MigrationHistory;
use Engine\Atomic\Core\Migrations\MigrationLedger;
use Engine\Atomic\Core\Migrations\MigrationPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempPath;

final class PublishedFrameworkChecksumTest extends TestCase
{
    private string $directory;
    private string $core;
    private string $published;
    private array $original;
    private array $rows = [];
    private bool $record_failure = false;
    private Migrations $service;
    private Output $output;

    protected function setUp(): void
    {
        $this->directory = TempPath::make_dir('atomic_published_checksum_');
        mkdir($this->directory . 'core/initial', 0755, true);
        mkdir($this->directory . 'app');
        $this->core = $this->directory . 'core/initial/create_example.php';
        $this->published = $this->directory . 'app/20260101000000_create_example.php';
        $body = <<<'PHP'
<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;
return [
    'up' => static function () { $GLOBALS['atomic_checksum_events'][] = ['up', __FILE__]; },
    'down' => static function () { $GLOBALS['atomic_checksum_events'][] = ['down', __FILE__]; },
];
PHP;
        file_put_contents($this->core, $body);
        file_put_contents($this->published, $body);
        $GLOBALS['atomic_checksum_events'] = [];
        $app = App::instance();
        $this->original = [$app->get('MIGRATIONS'), $app->get('MIGRATIONS_CORE')];
        $app->set('MIGRATIONS', $this->directory . 'app/');
        $app->set('MIGRATIONS_CORE', $this->directory . 'core/');
        $ledger = $this->createMock(MigrationLedger::class);
        $ledger->method('ensure')->willReturn(true);
        $ledger->method('synchronized')->willReturnCallback(static fn(callable $work) => $work());
        $ledger->method('rows')->willReturnCallback(fn() => $this->rows);
        $ledger->method('record')->willReturnCallback(function (array $migration, string $batch): void {
            if ($this->record_failure) {
                throw new \RuntimeException('Unable to record migration history.');
            }
            $this->rows[] = (object)[
                'id' => count($this->rows) + 1,
                'source' => $migration['source'],
                'migration' => $migration['migration'],
                'checksum' => $migration['checksum'],
                'batch_uuid' => $batch,
            ];
        });
        $ledger->method('erase')->willReturnCallback(function (int $id): void {
            $this->rows = array_values(array_filter($this->rows, static fn(object $row) => $row->id !== $id));
        });
        $plugins = $this->createMock(PluginManager::class);
        $plugins->method('all')->willReturn([]);
        $history = new MigrationHistory();
        $this->output = new Output(fopen('php://memory', 'w+b'), fopen('php://memory', 'w+b'));
        $this->service = new Migrations(
            $this->output,
            new MigrationCatalog($app, $plugins, $history, $ledger),
            $ledger,
            $history,
            new LegacyMigrationAdopter($ledger, $history, $this->output),
            new MigrationExecutor(),
            $this->createMock(MigrationPublisher::class),
        );
    }

    protected function tearDown(): void
    {
        App::instance()->set('MIGRATIONS', $this->original[0]);
        App::instance()->set('MIGRATIONS_CORE', $this->original[1]);
        unset($GLOBALS['atomic_checksum_events']);
        TempPath::remove($this->directory);
    }

    public function test_matching_copy_executes_the_published_file_and_records_its_checksum(): void
    {
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        self::assertSame([['up', realpath($this->published)]], $GLOBALS['atomic_checksum_events']);
        self::assertCount(1, $this->rows);
        self::assertSame('framework', $this->rows[0]->source);
        self::assertSame($this->checksum(), $this->rows[0]->checksum);
    }

    public function test_record_failure_after_up_leaves_the_migration_unrecorded(): void
    {
        $this->record_failure = true;

        $this->service->migrate();

        self::assertFalse($this->service->was_successful());
        self::assertSame([['up', realpath($this->published)]], $GLOBALS['atomic_checksum_events']);
        self::assertSame([], $this->rows);
    }

    public function test_pending_modified_copy_aborts_before_any_migration_runs(): void
    {
        file_put_contents($this->published, "\n// modified", FILE_APPEND);
        copy($this->core, $this->directory . 'app/20250101000000_unrelated.php');
        $this->service->migrate();
        self::assertFalse($this->service->was_successful());
        self::assertSame([], $GLOBALS['atomic_checksum_events']);
        self::assertSame([], $this->rows);
        rewind($this->output->stderr());
        self::assertStringContainsString('differs from its source', stream_get_contents($this->output->stderr()));
    }

    public function test_all_pending_framework_checksum_mismatches_are_reported_before_execution(): void
    {
        $second_core = $this->directory . 'core/initial/create_second.php';
        $second_published = $this->directory . 'app/20260101000001_create_second.php';
        copy($this->core, $second_core);
        copy($this->core, $second_published);
        file_put_contents($this->published, "\n// modified", FILE_APPEND);
        file_put_contents($second_published, "\n// modified", FILE_APPEND);

        $this->service->migrate();

        self::assertFalse($this->service->was_successful());
        self::assertSame([], $GLOBALS['atomic_checksum_events']);
        self::assertSame([], $this->rows);
        rewind($this->output->stderr());
        $stderr = strtolower(stream_get_contents($this->output->stderr()));
        self::assertStringContainsString('create_example', $stderr);
        self::assertStringContainsString('create_second', $stderr);
    }

    public function test_all_applied_checksum_mismatches_are_reported_before_new_work(): void
    {
        $second_core = $this->directory . 'core/initial/create_second.php';
        $second_published = $this->directory . 'app/20260101000001_create_second.php';
        copy($this->core, $second_core);
        copy($this->core, $second_published);
        $first_checksum = $this->checksum();
        $second_checksum = hash(
            'sha256',
            str_replace(["\r\n", "\r"], "\n", file_get_contents($second_published)),
        );
        $this->rows = [
            (object)['id' => 1, 'source' => 'framework', 'migration' => 'create_example', 'checksum' => $first_checksum],
            (object)['id' => 2, 'source' => 'framework', 'migration' => 'create_second', 'checksum' => $second_checksum],
        ];
        file_put_contents($this->published, "\n// modified", FILE_APPEND);
        file_put_contents($second_published, "\n// modified", FILE_APPEND);

        $this->service->migrate();

        self::assertFalse($this->service->was_successful());
        self::assertSame([], $GLOBALS['atomic_checksum_events']);
        self::assertCount(2, $this->rows);
        rewind($this->output->stderr());
        $stderr = strtolower(stream_get_contents($this->output->stderr()));
        self::assertStringContainsString('create_example', $stderr);
        self::assertStringContainsString('create_second', $stderr);
    }

    public function test_applied_copy_ignores_later_core_changes_including_rollback(): void
    {
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        file_put_contents($this->core, "\n// newer framework release", FILE_APPEND);
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        self::assertCount(1, $this->rows);
        $this->service->rollback(1);
        self::assertTrue($this->service->was_successful());
        self::assertSame([
            ['up', realpath($this->published)], ['down', realpath($this->published)],
        ], $GLOBALS['atomic_checksum_events']);
        self::assertSame([], $this->rows);
    }

    public function test_applied_copy_changes_are_rejected_even_when_core_matches_them(): void
    {
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        file_put_contents($this->published, "\n// changed after execution", FILE_APPEND);
        copy($this->published, $this->core);
        $this->service->migrate();
        self::assertFalse($this->service->was_successful());
        $this->service->rollback(1);
        self::assertFalse($this->service->was_successful());
        self::assertCount(1, $GLOBALS['atomic_checksum_events']);
        self::assertCount(1, $this->rows);
    }

    public function test_applied_copy_can_be_rolled_back_without_the_core_original(): void
    {
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        unlink($this->core);
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        $this->service->rollback(1);
        self::assertTrue($this->service->was_successful());
        self::assertSame(['down', realpath($this->published)], $GLOBALS['atomic_checksum_events'][1]);
    }

    public function test_line_endings_do_not_count_as_a_modified_copy(): void
    {
        file_put_contents($this->published, str_replace("\n", "\r\n", file_get_contents($this->core)));
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        self::assertSame($this->checksum(), $this->rows[0]->checksum);
    }

    public function test_applied_copy_missing_from_application_cannot_fall_back_to_core(): void
    {
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        unlink($this->published);
        $this->service->rollback(1);
        self::assertFalse($this->service->was_successful());
        self::assertCount(1, $GLOBALS['atomic_checksum_events']);
        self::assertCount(1, $this->rows);
    }

    public function test_existing_canonical_history_uses_the_published_checksum_for_rollback(): void
    {
        $this->rows = [(object)[
            'id' => 7, 'source' => 'framework', 'migration' => 'create_example',
            'checksum' => $this->checksum(), 'batch_uuid' => 'old-batch',
        ]];
        file_put_contents($this->core, "\n// updated original", FILE_APPEND);
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        self::assertSame([], $GLOBALS['atomic_checksum_events']);
        $this->service->rollback(1);
        self::assertTrue($this->service->was_successful());
        self::assertSame([['down', realpath($this->published)]], $GLOBALS['atomic_checksum_events']);
    }

    public function test_legacy_applied_copy_is_not_rechecked_against_changed_core(): void
    {
        $this->rows = [(object)[
            'id' => 7, 'source' => null, 'migration' => basename($this->published, '.php'),
            'checksum' => null, 'batch_uuid' => 'old-batch',
        ]];
        file_put_contents($this->core, "\n// updated original", FILE_APPEND);
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        self::assertSame([], $GLOBALS['atomic_checksum_events']);
    }

    public function test_initial_files_require_publication_and_updates_keep_numeric_order(): void
    {
        unlink($this->published);
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        self::assertSame([], $GLOBALS['atomic_checksum_events']);
        mkdir($this->directory . 'core/updates');
        $second = $this->directory . 'core/updates/2_update_example_low.php';
        $tenth = $this->directory . 'core/updates/10_update_example_high.php';
        copy($this->core, $tenth);
        copy($this->core, $second);
        copy($this->core, $this->published);
        $this->service->migrate();
        self::assertTrue($this->service->was_successful());
        self::assertSame([
            ['up', realpath($this->published)], ['up', realpath($second)], ['up', realpath($tenth)],
        ], $GLOBALS['atomic_checksum_events']);
    }

    public function test_mysql_records_the_published_checksum_and_preserves_it_after_core_changes(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('ext-pdo_mysql is required.');
        }
        $app = App::instance();
        $original_config = $app->get('DB_CONFIG');
        $config = (array)$original_config;
        if (($config['driver'] ?? 'mysql') !== 'mysql') {
            self::markTestSkipped('This ledger integration test requires MySQL.');
        }
        try {
            $pdo = new \PDO(
                'mysql:host=' . ($config['host'] ?? '127.0.0.1') . ';port=' . ($config['port'] ?? '3306')
                    . ';dbname=' . ($config['db'] ?? 'atomic_test'),
                $config['username'] ?? 'atomic_test_user', $config['password'] ?? 'atomic_test_pass',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL is unavailable: ' . $e->getMessage());
        }
        $config['prefix'] = 'atomic_checksum_' . bin2hex(random_bytes(6)) . '_';
        $table = '`' . $config['prefix'] . 'migrations`';
        $connections = ConnectionManager::instance();
        try {
            $connections->close_sql();
            $app->set('DB_CONFIG', $config);
            $ledger = new MigrationLedger($app, $connections);
            $history = new MigrationHistory();
            $plugins = $this->createMock(PluginManager::class);
            $plugins->method('all')->willReturn([]);
            $service = new Migrations(
                $this->output, new MigrationCatalog($app, $plugins, $history, $ledger), $ledger, $history,
                new LegacyMigrationAdopter($ledger, $history, $this->output), new MigrationExecutor(),
                $this->createMock(MigrationPublisher::class),
            );
            file_put_contents($this->published, "\n// mismatch", FILE_APPEND);
            $service->migrate();
            self::assertFalse($service->was_successful());
            self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
            self::assertSame([], $GLOBALS['atomic_checksum_events']);

            copy($this->core, $this->published);
            $service->migrate();
            self::assertTrue($service->was_successful());
            $stored = $pdo->query('SELECT source, migration, checksum FROM ' . $table)->fetch(\PDO::FETCH_ASSOC);
            self::assertSame([
                'source' => 'framework', 'migration' => 'create_example', 'checksum' => $this->checksum(),
            ], $stored);
            file_put_contents($this->core, "\n// newer framework", FILE_APPEND);
            $service->migrate();
            self::assertTrue($service->was_successful());
            self::assertSame($stored, $pdo->query('SELECT source, migration, checksum FROM ' . $table)->fetch(\PDO::FETCH_ASSOC));
            $service->rollback(1);
            self::assertTrue($service->was_successful());
            self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
            self::assertSame([
                ['up', realpath($this->published)], ['down', realpath($this->published)],
            ], $GLOBALS['atomic_checksum_events']);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
            $connections->close_sql();
            $app->set('DB_CONFIG', $original_config);
        }
    }

    private function checksum(): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", file_get_contents($this->published)));
    }
}
