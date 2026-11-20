<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Stats;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Telemetry\Stats\FlushJob;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsQuery;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

/**
 * Requests that never touch the database must not pay for a MySQL connection
 * just to write statistics: the shutdown flush spools to a local file instead,
 * and the next flush that has a connection drains it.
 */
final class StatsSpoolTest extends StatsDbTestCase
{
    private string $temp_backup = '';
    private string $spool_dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_backup = (string)App::instance()->get('TEMP');
        $this->spool_dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_spool_' . \bin2hex(\random_bytes(4)) . DIRECTORY_SEPARATOR;
        \mkdir($this->spool_dir, 0777, true);
        App::instance()->set('TEMP', $this->spool_dir);
    }

    protected function tearDown(): void
    {
        // setUp may bail before spool_dir is set; an empty prefix would glob the CWD.
        if ($this->spool_dir !== '') {
            foreach (\glob($this->spool_dir . '*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->spool_dir);
            App::instance()->set('TEMP', $this->temp_backup);
        }
        parent::tearDown();
    }

    public function test_shutdown_flush_without_open_connection_spools_instead_of_connecting(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('spool.metric', 2);
        ConnectionManager::instance()->close_sql();

        $recorder->flush_on_shutdown();

        self::assertFalse(ConnectionManager::instance()->has_db());
        self::assertNotEmpty(\glob($this->spool_dir . 'telemetry-stats*.spool') ?: []);
    }

    public function test_shutdown_flush_ends_the_response_before_writing(): void
    {
        // Like Laravel's terminate step: under FPM the client gets its
        // response first, then the stats are written.
        $finished = 0;
        StatsRecorder::finish_response_using(static function () use (&$finished): void {
            $finished++;
        });
        try {
            $recorder = StatsRecorder::instance();
            $recorder->record('after.response', 3);

            $recorder->flush_on_shutdown();
            self::assertSame(0, $finished, 'first pass only defers');
            self::assertTrue($recorder->has_pending());

            $recorder->flush_after_response();
            self::assertSame(1, $finished);
            self::assertFalse($recorder->has_pending());
            self::assertSame(3, StatsQuery::lifetime('after.response'));
        } finally {
            StatsRecorder::finish_response_using(null);
        }
    }

    public function test_shutdown_with_nothing_buffered_never_ends_the_response(): void
    {
        $finished = 0;
        StatsRecorder::finish_response_using(static function () use (&$finished): void {
            $finished++;
        });
        try {
            StatsRecorder::instance()->flush_on_shutdown();
            self::assertSame(0, $finished);
        } finally {
            StatsRecorder::finish_response_using(null);
        }
    }

    public function test_flush_if_pending_writes_only_when_something_is_buffered(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->flush_if_pending();
        self::assertSame(0, StatsQuery::lifetime('worker.job'));

        $recorder->record('worker.job', 2);
        $recorder->flush_if_pending();

        self::assertFalse($recorder->has_pending());
        self::assertSame(2, StatsQuery::lifetime('worker.job'));
    }

    public function test_next_flush_with_connection_drains_the_spool(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('spool.metric', 2, ['scope' => 'a']);
        $recorder->observe_quota_scope('user:9', 'free');
        ConnectionManager::instance()->close_sql();
        $recorder->flush_on_shutdown();

        ConnectionManager::instance()->get_db();
        $recorder->record('spool.metric', 3, ['scope' => 'a']);
        $recorder->flush_on_shutdown();

        self::assertSame(5, StatsQuery::lifetime('spool.metric', ['scope' => 'a']));
        self::assertSame(5, array_sum(StatsQuery::series('spool.metric', [], '24h')));
        $scopes = (array)StatsConnection::db()->exec(
            'SELECT scope FROM `' . StatsConnection::table(StatsConnection::TABLE_QUOTA_SCOPES) . '`',
        );
        self::assertSame('user:9', (string)$scopes[0]['scope']);

        // Drained exactly once.
        $recorder->flush();
        self::assertSame(5, StatsQuery::lifetime('spool.metric', ['scope' => 'a']));
    }

    public function test_drain_without_new_records_still_empties_the_spool(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('spool.metric', 4);
        ConnectionManager::instance()->close_sql();
        $recorder->flush_on_shutdown();

        $recorder->drain();

        self::assertSame(4, StatsQuery::lifetime('spool.metric'));
    }

    public function test_flush_job_persists_the_spool(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('spool.metric', 6);
        ConnectionManager::instance()->close_sql();
        $recorder->flush_on_shutdown();

        (new FlushJob())->handle();

        self::assertSame(6, StatsQuery::lifetime('spool.metric'));
        self::assertSame(0, (int)@\filesize($this->spool_dir . 'telemetry-stats.spool'));
    }

    public function test_framework_schedule_flushes_stats_every_minute(): void
    {
        $schedule = (string)\file_get_contents(\dirname(__DIR__, 4) . '/engine/Atomic/Core/Routes/schedule.php');

        self::assertMatchesRegularExpression('/->job\(FlushJob::class\)\s*->every_minute\(\)/', $schedule);
    }
}
