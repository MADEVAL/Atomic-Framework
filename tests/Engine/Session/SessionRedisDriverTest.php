<?php
declare(strict_types=1);

namespace Tests\Engine\Session;

use Engine\Atomic\Session\Drivers\Redis as RedisSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Session\Support\SessionDriverTestHarness;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionRedisDriverTest extends TestCase
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
        $this->configure_request_context();
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

    public function test_read_missing_session_returns_empty_string_and_records_sid(): void
    {
        $driver = $this->new_driver();

        $this->assertSame('', $driver->read('missing-session'));
        $this->assertSame('missing-session', $driver->sid());
    }

    public function test_write_stores_json_payload_with_ttl(): void
    {
        $driver = $this->new_driver();

        $this->assertTrue($driver->write('session-one', 'payload=1'));

        $payload = $this->decode_session('session-one');
        $this->assertSame('session-one', $payload['session_id']);
        $this->assertSame('payload=1', $payload['data']);
        $this->assertSame('127.0.0.1', $payload['ip']);
        $this->assertSame('Atomic Test Agent', $payload['agent']);
        $this->assertGreaterThan(0, (int)$payload['stamp']);
        $this->assertGreaterThan(0, $this->redis()->ttl($this->redis_session_key('session-one')));
    }

    public function test_read_returns_payload_and_hydrates_metadata(): void
    {
        $this->write_raw_session('session-two', 'stored=1');
        $driver = $this->new_driver();

        $this->assertSame('stored=1', $driver->read('session-two'));
        $this->assertSame('session-two', $driver->sid());
        $this->assertSame('Atomic Test Agent', $driver->agent());
        $this->assertSame('127.0.0.1', $driver->ip());
    }

    public function test_write_refreshes_existing_session_ttl(): void
    {
        $this->write_raw_session('session-three', 'old=1', ttl: 10);
        $driver = $this->new_driver();

        $this->assertTrue($driver->write('session-three', 'new=1'));

        $payload = $this->decode_session('session-three');
        $this->assertSame('new=1', $payload['data']);
        $this->assertGreaterThan(30, $this->redis()->ttl($this->redis_session_key('session-three')));
    }

    public function test_destroy_deletes_session_and_creates_revoked_marker(): void
    {
        $this->write_raw_session('session-destroy', 'payload');
        $driver = $this->new_driver();

        $this->assertTrue($driver->destroy('session-destroy'));

        $this->assertFalse($this->redis()->exists($this->redis_session_key('session-destroy')) > 0);
        $this->assertTrue($this->redis()->exists($this->redis_revoked_key('session-destroy')) > 0);
        $this->assertGreaterThan(0, $this->redis()->ttl($this->redis_revoked_key('session-destroy')));
    }

    public function test_write_is_ignored_when_session_is_revoked(): void
    {
        $this->redis()->setex($this->redis_revoked_key('session-revoked'), 60, '1');
        $driver = $this->new_driver();

        $this->assertTrue($driver->write('session-revoked', 'payload'));
        $this->assertFalse($this->redis()->exists($this->redis_session_key('session-revoked')) > 0);
    }

    public function test_malformed_json_is_treated_as_empty_data(): void
    {
        $this->redis()->setex($this->redis_session_key('session-bad-json'), 60, 'not-json');
        $driver = $this->new_driver();

        $this->assertSame('', $driver->read('session-bad-json'));
        $this->assertSame('session-bad-json', $driver->sid());
    }

    public function test_suspect_callback_returning_true_allows_mismatched_session(): void
    {
        $this->write_raw_session('session-suspect', 'payload', '10.0.0.1', 'Other Agent');
        $called = false;
        $driver = $this->new_driver(function (RedisSession $session, string $id) use (&$called): bool {
            $called = $session->sid() === $id && $id === 'session-suspect';
            return true;
        });

        $this->assertSame('payload', $driver->read('session-suspect'));
        $this->assertTrue($called);
        $this->assertTrue($this->redis()->exists($this->redis_session_key('session-suspect')) > 0);
    }

    private function write_raw_session(
        string $session_id,
        string $data,
        string $ip = '127.0.0.1',
        string $agent = 'Atomic Test Agent',
        ?int $stamp = null,
        int $ttl = 60,
    ): void {
        $this->redis()->setex($this->redis_session_key($session_id), $ttl, \json_encode([
            'session_id' => $session_id,
            'data' => $data,
            'ip' => $ip,
            'agent' => $agent,
            'stamp' => $stamp ?? \time(),
        ], JSON_THROW_ON_ERROR));
    }

    private function new_driver(?callable $onsuspect = null): RedisSession
    {
        return new RedisSession($onsuspect);
    }

    private function decode_session(string $session_id): array
    {
        $raw = $this->redis()->get($this->redis_session_key($session_id));
        $this->assertIsString($raw);
        $decoded = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    private function redis(): \Redis
    {
        $this->assertInstanceOf(\Redis::class, $this->redis);
        return $this->redis;
    }
}
