<?php
declare(strict_types=1);

namespace Tests\Engine\Queue\Support;

use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

abstract class QueueDbTestCase extends TestCase
{
    use QueueDriverTestHarness;

    protected bool $queue_tables_migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup_queue_state();
        [, $host] = $this->connect_pdo_or_skip();
        $this->configure_db_for_queue($host, $this->new_db_prefix(), $this->new_queue_name());
        $this->migrate_queue_tables_up();
        $this->queue_tables_migrated = true;
    }

    protected function tearDown(): void
    {
        if ($this->queue_tables_migrated) {
            $this->migrate_queue_tables_down();
            $this->queue_tables_migrated = false;
        }

        ConnectionManager::instance()->close_sql();
        $this->restore_queue_state();
        parent::tearDown();
    }

    protected function new_db_prefix(): string
    {
        return 'atomic_queue_test_' . \bin2hex(\random_bytes(4)) . '_';
    }
}
