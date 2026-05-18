<?php
declare(strict_types=1);

namespace Tests\Engine\Queue\Support;

use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

abstract class QueueDbTestCase extends TestCase
{
    use QueueDriverTestHarness;

    protected bool $queueTablesMigrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupQueueState();
        [, $host] = $this->connectPdoOrSkip();
        $this->configureDbForQueue($host, $this->newDbPrefix(), $this->newQueueName());
        $this->migrateQueueTablesUp();
        $this->queueTablesMigrated = true;
    }

    protected function tearDown(): void
    {
        if ($this->queueTablesMigrated) {
            $this->migrateQueueTablesDown();
            $this->queueTablesMigrated = false;
        }

        ConnectionManager::instance()->close_sql();
        $this->restoreQueueState();
        parent::tearDown();
    }

    protected function newDbPrefix(): string
    {
        return 'atomic_queue_test_' . \bin2hex(\random_bytes(4)) . '_';
    }
}
