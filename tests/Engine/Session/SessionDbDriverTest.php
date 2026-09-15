<?php
declare(strict_types=1);

namespace Tests\Engine\Session;

use Engine\Atomic\Session\Drivers\DB as DbSession;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Session\Support\SessionDriverTestHarness;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionDbDriverTest extends TestCase
{
    use SessionDriverTestHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup_session_state();
        [, $host] = $this->connect_pdo_or_skip();
        $this->configure_db_for_session($host, $this->new_db_prefix());
        $this->migrate_session_table_up();
        $this->configure_request_context();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        $this->migrate_session_table_down();
        $this->restore_session_state();
        parent::tearDown();
    }

    public function test_read_missing_session_returns_empty_string_and_records_sid(): void
    {
        $driver = new DbSession();

        $this->assertSame('', $driver->read('missing-session'));
        $this->assertSame('missing-session', $driver->sid());
        $this->assertTrue($driver->dry());
    }

    public function test_constructor_resumes_matching_session_before_redirect_and_refresh(): void
    {
        ini_set('session.serialize_handler', 'php');
        $this->insert_db_session('session-resume', 'message|s:5:"hello";');
        $suspects = 0;

        for ($request = 0; $request < 2; $request++) {
            session_id('session-resume');
            new DbSession(function () use (&$suspects): bool {
                $suspects++;
                return true;
            }, 'SESSION.csrf_token');

            $this->assertSame(0, $suspects, 'Matching metadata must be available during constructor-triggered read().');
            $this->assertSame('hello', $_SESSION['message'] ?? null);
            $this->assertNotEmpty($_SESSION['csrf_token'] ?? null);
            session_write_close();
            $_SESSION = [];

            $row = $this->db_session_row('session-resume');
            $this->assertSame('127.0.0.1', $row['ip']);
            $this->assertSame('Atomic Test Agent', $row['agent']);
        }
    }

    public static function mismatched_metadata(): array
    {
        return [
            'changed IP' => ['10.0.0.1', 'Atomic Test Agent'],
            'changed agent' => ['127.0.0.1', 'Other Agent'],
            'empty IP' => ['', 'Atomic Test Agent'],
            'empty agent' => ['127.0.0.1', ''],
            'both empty' => ['', ''],
        ];
    }

    #[DataProvider('mismatched_metadata')]
    public function test_mismatched_metadata_is_rejected_and_reported(string $ip, string $agent): void
    {
        App::instance()->set('LOGGABLE', []);
        $this->insert_db_session('session-rejected', 'private-data', $ip, $agent);
        $reported = null;
        $driver = new DbSession(function ($session, $id, array $metadata = []) use (&$reported): bool {
            $reported = $metadata;
            return false;
        });

        ob_start();
        try {
            $data = $driver->read('session-rejected');
        } finally {
            ob_end_clean();
        }

        $this->assertSame('', $data);
        $this->assertSame(403, App::instance()->get('ERROR.code'));
        $this->assertNull($this->db_session_row('session-rejected'));
        $this->assertIsArray($this->db_session_row('.revoked.session-rejected'));
        $this->assertSame([
            'stored_ip' => $ip,
            'current_ip' => '127.0.0.1',
            'stored_agent' => $agent,
            'current_agent' => 'Atomic Test Agent',
        ], $reported);
    }

    public function test_constructor_connects_when_app_db_hive_value_is_missing(): void
    {
        ConnectionManager::instance()->close_sql();
        App::instance()->clear('DB');

        $driver = new DbSession();

        $this->assertTrue($driver->write('session-lazy-db', 'payload=1'));
        $this->assertSame('payload=1', $this->db_session_row('session-lazy-db')['data'] ?? null);
    }

    public function test_write_inserts_session_payload_and_metadata(): void
    {
        $driver = new DbSession();

        $this->assertTrue($driver->write('session-one', 'payload=1'));

        $row = $this->db_session_row('session-one');
        $this->assertIsArray($row);
        $this->assertSame('payload=1', $row['data']);
        $this->assertSame('127.0.0.1', $row['ip']);
        $this->assertSame('Atomic Test Agent', $row['agent']);
        $this->assertGreaterThan(0, (int)$row['stamp']);
    }

    public function test_read_returns_stored_payload(): void
    {
        $this->insert_db_session('session-two', 'stored=1');
        $driver = new DbSession();

        $this->assertSame('stored=1', $driver->read('session-two'));
        $this->assertSame('session-two', $driver->sid());
        $this->assertFalse($driver->dry());
        $this->assertSame('stored=1', $driver->get('data'));
    }

    public function test_write_updates_existing_session_without_duplicating_row(): void
    {
        $this->insert_db_session('session-three', 'old=1');
        $driver = new DbSession();

        $this->assertTrue($driver->write('session-three', 'new=1'));

        $this->assertSame(1, $this->db_session_count('session-three'));
        $this->assertSame('new=1', $this->db_session_row('session-three')['data'] ?? null);
    }

    public function test_user_agent_is_truncated_to_512_characters(): void
    {
        $agent = \str_repeat('A', 600);
        $this->configure_request_context('127.0.0.1', $agent);
        $driver = new DbSession();

        $this->assertTrue($driver->write('session-agent', 'payload'));

        $row = $this->db_session_row('session-agent');
        $this->assertIsArray($row);
        $this->assertSame(512, \strlen((string)$row['agent']));
        $this->assertSame(\str_repeat('A', 512), $row['agent']);
    }

    public function test_destroy_removes_session_row(): void
    {
        $this->insert_db_session('session-destroy', 'payload');
        $driver = new DbSession();

        $this->assertTrue($driver->destroy('session-destroy'));
        $this->assertNull($this->db_session_row('session-destroy'));
        $this->assertIsArray($this->db_session_row('.revoked.session-destroy'));
    }

    public function test_write_is_ignored_when_session_is_revoked(): void
    {
        $this->insert_db_session('.revoked.session-revoked', '', stamp: time());
        $driver = new DbSession();

        $this->assertSame('', $driver->read('session-revoked'));
        $this->assertTrue($driver->write('session-revoked', 'payload'));
        $this->assertNull($this->db_session_row('session-revoked'));
    }

    public function test_gc_removes_only_expired_sessions(): void
    {
        $now = \time();
        $this->insert_db_session('session-expired', 'old', stamp: $now - 120);
        $this->insert_db_session('session-fresh', 'new', stamp: $now);
        $driver = new DbSession();

        $this->assertSame(1, $driver->gc(60));
        $this->assertNull($this->db_session_row('session-expired'));
        $this->assertIsArray($this->db_session_row('session-fresh'));
    }

    public function test_close_resets_mapper_state_and_clears_sid(): void
    {
        $this->insert_db_session('session-close', 'payload');
        $driver = new DbSession();
        $driver->read('session-close');

        $this->assertTrue($driver->close());
        $this->assertNull($driver->sid());
        $this->assertTrue($driver->dry());
    }

    public function test_suspect_callback_returning_true_allows_mismatched_session(): void
    {
        $this->insert_db_session('session-suspect', 'payload', '10.0.0.1', 'Other Agent');
        $called = false;
        $driver = new DbSession(function (DbSession $session, string $id) use (&$called): bool {
            $called = $session->sid() === $id && $id === 'session-suspect';
            return true;
        });

        $this->assertSame('payload', $driver->read('session-suspect'));
        $this->assertTrue($called);
        $this->assertIsArray($this->db_session_row('session-suspect'));
    }
}
