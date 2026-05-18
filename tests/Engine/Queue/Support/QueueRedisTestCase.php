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
        $this->backupQueueState();
        [$this->redis, $host] = $this->connectRedisOrSkip();
        $this->prefix = $this->newRedisPrefix();
        $this->queue = $this->newQueueName();
        $this->configureRedisForQueue($this->redis, $host, $this->prefix, $this->queue);
    }

    protected function tearDown(): void
    {
        if ($this->redis instanceof \Redis) {
            $this->cleanupRedisPrefix($this->redis, $this->prefix);
            $this->redis->close();
        }

        ConnectionManager::instance()->close_redis();
        $this->restoreQueueState();
        parent::tearDown();
    }

    protected function newRedisPrefix(): string
    {
        return 'atomic_queue_test_' . \bin2hex(\random_bytes(4)) . ':';
    }
}
