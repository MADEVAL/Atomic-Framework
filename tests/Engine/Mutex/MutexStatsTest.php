<?php
declare(strict_types=1);

namespace Tests\Engine\Mutex;

use Engine\Atomic\Mutex\FileMutexDriver;
use Engine\Atomic\Mutex\Mutex;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use PHPUnit\Framework\TestCase;

/** Locking is routine traffic: it must never buffer telemetry statistics. */
final class MutexStatsTest extends TestCase
{
    protected function tearDown(): void
    {
        StatsRecorder::instance()->reset();
        Mutex::reset();
    }

    public function test_acquire_contend_and_release_record_no_statistics(): void
    {
        $driver = new FileMutexDriver();
        if (!$driver->is_available()) {
            self::markTestSkipped('File mutex driver unavailable.');
        }
        \Base::instance()->set('TELEMETRY_STATS_ENABLED', true);
        Mutex::set_driver($driver);
        StatsRecorder::instance()->reset();
        $name = 'stats-test-' . bin2hex(random_bytes(4));

        $token = Mutex::acquire($name, 30);
        self::assertNotNull($token);
        self::assertNull(Mutex::acquire($name, 30));
        self::assertTrue(Mutex::release($name, $token));

        self::assertFalse(StatsRecorder::instance()->has_pending());
    }
}
