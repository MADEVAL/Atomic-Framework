<?php
declare(strict_types=1);

namespace Tests\Engine\Session;

use Engine\Atomic\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Session\Support\SessionDriverTestHarness;

final class SessionManagerRedisTest extends TestCase
{
    use SessionDriverTestHarness;

    private ?\Redis $redis = null;
    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup_session_state();
        [$this->redis, $host] = $this->connect_redis_or_skip();
        $this->prefix = $this->new_redis_prefix();
        $this->configure_redis_for_session($this->redis, $host, $this->prefix, 60);
    }

    protected function tearDown(): void
    {
        if ($this->redis instanceof \Redis && $this->prefix !== '') {
            $this->cleanup_redis_prefix($this->redis, $this->prefix);
            $this->redis->close();
        }

        $this->restore_session_state();
        parent::tearDown();
    }

    public function test_session_exists_reflects_redis_keys(): void
    {
        $this->write_session('manager-redis-one', 'payload');
        $manager = new SessionManager('redis');

        $this->assertTrue($manager->session_exists('manager-redis-one'));
        $this->assertFalse($manager->session_exists('manager-redis-missing'));
    }

    public function test_get_session_data_returns_expected_shape(): void
    {
        $this->write_session('manager-redis-two', 'payload', '127.0.0.2', 'Manager Agent', 1_700_000_000);
        $manager = new SessionManager('redis');

        $this->assertSame([
            'session_id' => 'manager-redis-two',
            'data' => 'payload',
            'ip' => '127.0.0.2',
            'agent' => 'Manager Agent',
            'stamp' => 1_700_000_000,
        ], $manager->get_session_data('manager-redis-two'));
        $this->assertNull($manager->get_session_data('manager-redis-missing'));
    }

    public function test_delete_session_deletes_key_and_writes_revoked_marker(): void
    {
        $this->write_session('manager-redis-delete', 'payload');
        $manager = new SessionManager('redis');

        $this->assertTrue($manager->delete_session('manager-redis-delete'));
        $this->assertFalse($this->redis()->exists($this->prefix . 'manager-redis-delete') > 0);
        $this->assertTrue($this->redis()->exists($this->prefix . ':revoked:manager-redis-delete') > 0);
        $this->assertFalse($manager->delete_session('manager-redis-missing'));
    }

    public function test_delete_sessions_counts_successfully_deleted_keys(): void
    {
        $this->write_session('manager-redis-bulk-one', 'payload');
        $this->write_session('manager-redis-bulk-two', 'payload');
        $manager = new SessionManager('redis');

        $this->assertSame(2, $manager->delete_sessions([
            'manager-redis-bulk-one',
            'manager-redis-bulk-missing',
            'manager-redis-bulk-two',
        ]));
        $this->assertFalse($manager->session_exists('manager-redis-bulk-one'));
        $this->assertFalse($manager->session_exists('manager-redis-bulk-two'));
    }

    public function test_get_session_data_returns_null_for_invalid_json(): void
    {
        $this->redis()->setex($this->prefix . 'manager-redis-bad-json', 60, 'not-json');
        $manager = new SessionManager('redis');

        $this->assertNull($manager->get_session_data('manager-redis-bad-json'));
    }

    private function write_session(
        string $session_id,
        string $data,
        string $ip = '127.0.0.1',
        string $agent = 'Atomic Test Agent',
        ?int $stamp = null,
    ): void {
        $this->redis()->setex($this->prefix . $session_id, 60, \json_encode([
            'session_id' => $session_id,
            'data' => $data,
            'ip' => $ip,
            'agent' => $agent,
            'stamp' => $stamp ?? \time(),
        ], JSON_THROW_ON_ERROR));
    }

    private function redis(): \Redis
    {
        $this->assertInstanceOf(\Redis::class, $this->redis);
        return $this->redis;
    }
}
