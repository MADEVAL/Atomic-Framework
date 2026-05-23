<?php
declare(strict_types=1);

namespace Tests\Engine\Session;

use Engine\Atomic\Session\SessionManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Session\Support\SessionDriverTestHarness;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionManagerDbTest extends TestCase
{
    use SessionDriverTestHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup_session_state();
        [, $host] = $this->connect_pdo_or_skip();
        $this->configure_db_for_session($host, $this->new_db_prefix());
        $this->migrate_session_table_up();
    }

    protected function tearDown(): void
    {
        $this->migrate_session_table_down();
        $this->restore_session_state();
        parent::tearDown();
    }

    public function test_session_exists_reflects_db_rows(): void
    {
        $this->insert_db_session('manager-db-one', 'payload');
        $manager = new SessionManager('db');

        $this->assertTrue($manager->session_exists('manager-db-one'));
        $this->assertFalse($manager->session_exists('manager-db-missing'));
    }

    public function test_get_session_data_returns_expected_shape(): void
    {
        $this->insert_db_session('manager-db-two', 'payload', '127.0.0.2', 'Manager Agent', 1_700_000_000);
        $manager = new SessionManager('db');

        $this->assertSame([
            'session_id' => 'manager-db-two',
            'data' => 'payload',
            'ip' => '127.0.0.2',
            'agent' => 'Manager Agent',
            'stamp' => 1_700_000_000,
        ], $manager->get_session_data('manager-db-two'));
        $this->assertNull($manager->get_session_data('manager-db-missing'));
    }

    public function test_delete_session_removes_existing_row_and_returns_false_for_missing(): void
    {
        $this->insert_db_session('manager-db-delete', 'payload');
        $manager = new SessionManager('db');

        $this->assertTrue($manager->delete_session('manager-db-delete'));
        $this->assertNull($this->db_session_row('manager-db-delete'));
        $this->assertIsArray($this->db_session_row(':revoked:manager-db-delete'));
        $this->assertFalse($manager->delete_session('manager-db-missing'));
        $this->assertIsArray($this->db_session_row(':revoked:manager-db-missing'));
    }

    public function test_delete_sessions_counts_successfully_deleted_rows(): void
    {
        $this->insert_db_session('manager-db-bulk-one', 'payload');
        $this->insert_db_session('manager-db-bulk-two', 'payload');
        $manager = new SessionManager('db');

        $this->assertSame(2, $manager->delete_sessions([
            'manager-db-bulk-one',
            'manager-db-bulk-missing',
            'manager-db-bulk-two',
        ]));
        $this->assertFalse($manager->session_exists('manager-db-bulk-one'));
        $this->assertFalse($manager->session_exists('manager-db-bulk-two'));
    }
}
