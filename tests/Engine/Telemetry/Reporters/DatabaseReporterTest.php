<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Telemetry\Reporters\DatabaseReporter;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class DatabaseReporterTest extends StatsDbTestCase
{
    private string $parent = '';
    private string $child = '';

    protected function setUp(): void
    {
        parent::setUp();
        $prefix = (string)App::instance()->get('DB_CONFIG.prefix');
        $this->parent = $prefix . 'dbv_users';
        $this->child = $prefix . 'dbv_orders';
        $db = ConnectionManager::instance()->get_db();
        $db->exec('CREATE TABLE `' . $this->parent . '` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL, UNIQUE KEY uq_email (email)) ENGINE=InnoDB');
        $db->exec('CREATE TABLE `' . $this->child . '` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, note VARCHAR(20) DEFAULT \'new\', CONSTRAINT fk_dbv_user FOREIGN KEY (user_id) REFERENCES `' . $this->parent . '` (id) ON DELETE CASCADE) ENGINE=InnoDB');
        $db->exec('INSERT INTO `' . $this->parent . '` (email) VALUES (?)', ['secret-row@example.com']);
    }

    protected function tearDown(): void
    {
        $db = ConnectionManager::instance()->get_db();
        $db->exec('DROP TABLE IF EXISTS `' . $this->child . '`');
        $db->exec('DROP TABLE IF EXISTS `' . $this->parent . '`');
        parent::tearDown();
    }

    private function table(array $report, string $name): array
    {
        foreach ($report['tables'] as $table) {
            if ($table['name'] === $name) {
                return $table;
            }
        }
        self::fail("table {$name} missing from the report");
    }

    public function test_reports_tables_with_column_types_and_indexes(): void
    {
        $report = (new DatabaseReporter())->report();

        self::assertTrue($report['available']);
        self::assertSame((string)App::instance()->get('DB_CONFIG.prefix'), $report['prefix']);
        $users = $this->table($report, $this->parent);
        self::assertSame('InnoDB', $users['engine']);
        self::assertSame(['id', 'email'], array_column($users['columns'], 'name'));
        self::assertSame('varchar(190)', $users['columns'][1]['type']);
        self::assertFalse($users['columns'][1]['nullable']);
        self::assertSame('auto_increment', $users['columns'][0]['extra']);
        $indexes = array_column($users['indexes'], null, 'name');
        self::assertTrue($indexes['PRIMARY']['unique']);
        self::assertSame(['email'], $indexes['uq_email']['columns']);

        $orders = $this->table($report, $this->child);
        self::assertTrue($orders['columns'][1]['nullable']);
        self::assertNull($orders['columns'][1]['default']);
        self::assertStringContainsString('new', (string)$orders['columns'][2]['default']);
    }

    public function test_reports_foreign_keys_on_both_tables(): void
    {
        $report = (new DatabaseReporter())->report();

        $fk = $this->table($report, $this->child)['references'][0];
        self::assertSame('fk_dbv_user', $fk['name']);
        self::assertSame(['user_id'], $fk['columns']);
        self::assertSame($this->parent, $fk['ref_table']);
        self::assertSame(['id'], $fk['ref_columns']);
        self::assertSame('CASCADE', $fk['on_delete']);
        self::assertSame($this->child, $this->table($report, $this->parent)['referenced_by'][0]['table']);
    }

    public function test_never_includes_row_data(): void
    {
        $json = json_encode((new DatabaseReporter())->report());

        self::assertStringNotContainsString('secret-row@example.com', (string)$json);
    }

    public function test_reports_unavailable_for_non_mysql_drivers(): void
    {
        App::instance()->set('DB_CONFIG.driver', 'pgsql');

        $report = (new DatabaseReporter())->report();

        self::assertFalse($report['available']);
        self::assertSame([], $report['tables']);
        self::assertNotNull($report['reason']);
    }
}
