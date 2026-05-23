<?php
declare(strict_types=1);

namespace Tests\Engine\Session;

use Engine\Atomic\Session\Drivers\DB as DbSession;
use Engine\Atomic\Session\Drivers\Redis as RedisSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Session\Support\SessionDriverTestHarness;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionDriverContractTest extends TestCase
{
    use SessionDriverTestHarness;

    private ?\Redis $redis = null;
    private string $redis_prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup_session_state();

        [, $db_host] = $this->connect_pdo_or_skip();
        $this->configure_db_for_session($db_host, $this->new_db_prefix());
        $this->migrate_session_table_up();

        [$this->redis, $redis_host] = $this->connect_redis_or_skip();
        $this->redis_prefix = $this->new_redis_prefix();
        $this->configure_redis_for_session($this->redis, $redis_host, $this->redis_prefix, 60);

        $this->configure_request_context();
    }

    protected function tearDown(): void
    {
        $this->migrate_session_table_down();

        if ($this->redis instanceof \Redis && $this->redis_prefix !== '') {
            $this->cleanup_redis_prefix($this->redis, $this->redis_prefix);
            $this->redis->close();
        }

        $this->restore_session_state();
        parent::tearDown();
    }

    public function test_all_session_drivers_share_basic_handler_contract(): void
    {
        foreach ($this->drivers() as $name => $new_driver) {
            $id = 'contract-basic-' . $name;
            $driver = $new_driver();

            $this->assertTrue($driver->open('', 'Atomic_Test_Session'), $name);
            $this->assertSame('', $driver->read($id), $name);
            $this->assertSame($id, $driver->sid(), $name);
            $this->assertTrue($driver->write($id, 'payload=' . $name), $name);
            $this->assertSame('payload=' . $name, $driver->read($id), $name);
            $this->assertTrue($driver->close(), $name);
            $this->assertNull($driver->sid(), $name);
            $this->assertTrue($driver->destroy($id), $name);
            $this->assertSame('', $driver->read($id), $name);
        }
    }

    public function test_all_session_drivers_cap_persisted_user_agent_to_512_bytes(): void
    {
        foreach ($this->drivers() as $name => $new_driver) {
            $this->configure_request_context('127.0.0.1', \str_repeat('A', 600));
            $id = 'contract-agent-' . $name;

            $this->assertTrue($new_driver()->write($id, 'payload'), $name);

            $agent = $this->stored_agent($name, $id);
            $this->assertSame(512, \strlen($agent), $name);
            $this->assertSame(\str_repeat('A', 512), $agent, $name);
        }
    }

    /**
     * @return array<string, callable(): object{open(string, string): bool, read(string): string|false, write(string, string): bool, close(): bool, destroy(string): bool, sid(): ?string}>
     */
    private function drivers(): array
    {
        return [
            'db' => static fn(): DbSession => new DbSession(),
            'redis' => static fn(): RedisSession => new RedisSession(),
        ];
    }

    private function stored_agent(string $driver, string $session_id): string
    {
        if ($driver === 'db') {
            return (string)($this->db_session_row($session_id)['agent'] ?? '');
        }

        $raw = $this->redis()->get($this->redis_prefix . $session_id);
        $this->assertIsString($raw);
        $decoded = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return (string)($decoded['agent'] ?? '');
    }

    private function redis(): \Redis
    {
        $this->assertInstanceOf(\Redis::class, $this->redis);
        return $this->redis;
    }
}
