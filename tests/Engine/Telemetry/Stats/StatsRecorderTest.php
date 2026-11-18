<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Stats;

use Engine\Atomic\Core\App;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsQuery;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Engine\Atomic\Telemetry\Stats\TagHash;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class StatsRecorderTest extends StatsDbTestCase
{
    public function test_record_flush_writes_counter_and_lifetime_totals(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('test.metric', 2, ['scope' => 'user:1']);
        $recorder->record('test.metric', 3, ['scope' => 'user:1']);
        $recorder->flush();

        self::assertSame(5, StatsQuery::lifetime('test.metric', ['scope' => 'user:1']));
        self::assertSame(5, StatsQuery::lifetime('test.metric'));
        self::assertSame(0, StatsQuery::lifetime('test.metric', ['scope' => 'user:other']));
    }

    public function test_flush_accumulates_existing_counters_across_flushes(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('test.accum', 4);
        $recorder->flush();
        $recorder->record('test.accum', 6);
        $recorder->flush();

        self::assertSame(10, StatsQuery::lifetime('test.accum'));
    }

    public function test_record_event_is_persisted_and_readable(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record_event('test.events', ['scope' => 's1'], ['reason' => 'pacing']);
        $recorder->flush();

        $events = StatsQuery::recent_events('test.events', 10);
        self::assertCount(1, $events);
        self::assertSame('pacing', $events[0]['data']['reason']);
        self::assertSame('s1', $events[0]['tags']['scope']);
    }

    public function test_record_is_noop_when_stats_disabled(): void
    {
        App::instance()->set('TELEMETRY_STATS_ENABLED', false);
        try {
            $recorder = StatsRecorder::instance();
            $recorder->record('test.disabled', 1);
            $recorder->record_event('test.disabled_event', [], ['x' => 1]);
            $recorder->flush();

            self::assertSame(0, StatsQuery::lifetime('test.disabled'));
            self::assertSame([], StatsQuery::recent_events('test.disabled_event', 10));
        } finally {
            App::instance()->set('TELEMETRY_STATS_ENABLED', true);
        }
    }

    public function test_observe_rate_key_and_quota_scope_are_upserted(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->observe_rate_key('api.items.1.2.3', 'fixed', 100, 60);
        $recorder->observe_rate_key('api.items.1.2.3', 'fixed', 120, 60);
        $recorder->observe_quota_scope('user:7', 'free');
        $recorder->flush();

        $store = new \Engine\Atomic\Telemetry\Stats\Adapters\DB();
        $keys = $store->recent_rate_keys(null, 10);
        self::assertCount(1, $keys);
        self::assertSame('api.items.1.2.3', $keys[0]['key_name']);
        self::assertSame(120, $keys[0]['rate_limit']);

        $scopes = $store->quota_scopes_list(null, 10);
        self::assertCount(1, $scopes);
        self::assertSame('user:7', $scopes[0]['scope']);
        self::assertSame('free', $scopes[0]['tier']);
    }

    public function test_observe_lockout_upserts_identifier_state(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->observe_lockout('127.0.0.1|bad@mail.test', 5, time() - 60, time() + 300);
        $recorder->flush();

        $db = StatsConnection::db();
        self::assertNotNull($db);
        $rows = (array)$db->exec('SELECT * FROM `' . StatsConnection::table(StatsConnection::TABLE_LOCKOUTS) . '`');
        self::assertCount(1, $rows);
        self::assertSame(5, (int)$rows[0]['attempts']);
        self::assertGreaterThan(time(), (int)$rows[0]['locked_until']);
    }

    public function test_tag_hash_is_stable_and_order_independent(): void
    {
        self::assertSame(TagHash::of(['a' => '1', 'b' => '2']), TagHash::of(['b' => '2', 'a' => '1']));
        self::assertNotSame(TagHash::of(['a' => '1']), TagHash::of(['a' => '2']));
        self::assertSame('', TagHash::of([]));
    }

    public function test_series_respects_range_bucket_counts(): void
    {
        $recorder = StatsRecorder::instance();
        $recorder->record('test.series', 7);
        $recorder->flush();

        self::assertCount(24, StatsQuery::series('test.series', [], '24h'));
        self::assertCount(28, StatsQuery::series('test.series', [], '7d'));
        self::assertCount(30, StatsQuery::series('test.series', [], '30d'));

        $series = StatsQuery::series('test.series', [], '24h');
        self::assertSame(7, array_sum($series));
        self::assertSame(7, $series[23]);
    }
    public function test_repeated_exception_group_in_one_request_counts_every_occurrence(): void
    {
        $recorder = StatsRecorder::instance();
        $row = [
            'class' => 'RuntimeException', 'message' => 'boom', 'file' => 'x.php', 'line' => 1,
            'code' => 0, 'level' => 'error', 'path' => '/', 'first_seen' => time(), 'last_seen' => time(),
        ];
        $recorder->record_exception_group('fp-1', $row);
        $recorder->record_exception_group('fp-1', $row);
        $recorder->record_exception_group('fp-1', $row);
        $recorder->flush();
        $recorder->record_exception_group('fp-1', $row);
        $recorder->flush();

        $db = StatsConnection::db();
        $rows = (array)$db->exec(
            'SELECT total FROM `' . StatsConnection::table(StatsConnection::TABLE_EXCEPTION_GROUPS) . '` WHERE fingerprint = ?',
            ['fp-1'],
        );
        self::assertSame(4, (int)$rows[0]['total']);
    }

    public function test_flush_persists_large_batches(): void
    {
        $recorder = StatsRecorder::instance();
        for ($i = 0; $i < 450; $i++) {
            $recorder->record('batch.metric', 1, ['n' => (string)$i]);
        }
        $recorder->flush();

        self::assertSame(450, StatsQuery::lifetime('batch.metric'));
        self::assertSame(450, array_sum(StatsQuery::series('batch.metric', [], '24h')));
    }

    public function test_oversized_tag_values_do_not_drop_the_flush(): void
    {
        // Tag values can be attacker-controlled (login identifiers); one
        // oversized value must not fail the insert and lose the batch.
        $recorder = StatsRecorder::instance();
        $recorder->record('auth.failed', 1, ['id' => '1.2.3.4|' . str_repeat('a', 2000) . '@x.test']);
        $recorder->observe_quota_scope('user:1', 'free');
        $recorder->flush();

        self::assertSame(1, array_sum(StatsQuery::series('auth.failed', [], '24h')));
        $db = StatsConnection::db();
        self::assertCount(1, (array)$db->exec('SELECT scope FROM `' . StatsConnection::table(StatsConnection::TABLE_QUOTA_SCOPES) . '`'));
    }

    public function test_high_cardinality_tags_collapse_in_lifetime_totals(): void
    {
        // Per-identifier rows in the never-trimmed totals table would grow
        // without bound under credential stuffing; only the aggregate is kept.
        $recorder = StatsRecorder::instance();
        foreach (['a', 'b', 'c'] as $who) {
            $recorder->record('auth.failed', 1, ['id' => "1.2.3.4|{$who}@x.test"]);
        }
        $recorder->flush();

        $db = StatsConnection::db();
        $rows = (array)$db->exec(
            'SELECT tag_hash, value FROM `' . StatsConnection::table(StatsConnection::TABLE_TOTALS) . '` WHERE metric = ?',
            ['auth.failed'],
        );
        self::assertCount(1, $rows);
        self::assertSame('', (string)$rows[0]['tag_hash']);
        self::assertSame(3, StatsQuery::lifetime('auth.failed'));
        // The trimmed hourly counters keep per-identifier detail for "top offenders".
        self::assertCount(3, StatsQuery::top_tags('auth.failed', '24h', 10));
    }

    public function test_long_running_process_flushes_when_buffer_fills(): void
    {
        // Queue workers / scheduler daemons never reach shutdown between jobs.
        $recorder = StatsRecorder::instance();
        for ($i = 0; $i < 600; $i++) {
            $recorder->record('worker.metric', 1, ['n' => (string)$i]);
        }

        self::assertGreaterThan(0, StatsQuery::lifetime('worker.metric'));
    }
}
