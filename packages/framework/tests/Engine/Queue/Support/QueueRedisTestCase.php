<?php
declare(strict_types=1);

namespace Tests\Engine\Queue\Support;

use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

abstract class QueueRedisTestCase extends TestCase
{
    use QueueDriverTestHarness;

    protected ?\Redis $redis = null;
    protected string $prefix = '';
    protected string $queue = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup_queue_state();
        [$this->redis, $host] = $this->connect_redis_or_skip();
        $this->prefix = $this->new_redis_prefix();
        $this->queue = $this->new_queue_name();
        $this->configure_redis_for_queue($this->redis, $host, $this->prefix, $this->queue);
    }

    protected function tearDown(): void
    {
        if ($this->redis instanceof \Redis) {
            $this->cleanup_redis_prefix($this->redis, $this->prefix);
            $this->redis->close();
        }

        ConnectionManager::instance()->close_redis();
        $this->restore_queue_state();
        parent::tearDown();
    }

    protected function new_redis_prefix(): string
    {
        return 'atomic_queue_test_' . \bin2hex(\random_bytes(4)) . ':';
    }
}
