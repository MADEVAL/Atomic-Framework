<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Tests\Engine\Queue\Support\QueueRedisTestCase;

final class QueueRedisLuaEdgeTest extends QueueRedisTestCase
{
    protected function newRedisPrefix(): string
    {
        return 'atomic_queue_lua_test_' . \bin2hex(\random_bytes(4)) . ':';
    }

    public function test_load_jobs_by_state_fails_on_stale_registry_references(): void
    {
        $index = $this->key('idx.completed');
        $stale = $this->newUuid();
        $kept = $this->newUuid();

        $this->redis->zAdd($index, 1, $stale);
        $this->redis->zAdd($index, 2, $kept);
        $this->putRegistry($kept, ['state' => 'completed']);

        $this->assertLuaError(
            'missing job registry',
            fn () => $this->evalLua('load_jobs_by_state', [$index], [$this->prefix, 0, 10])
        );

        $this->assertNotFalse($this->redis->zScore($index, $stale));
    }

    public function test_load_active_monitor_fails_on_stale_pid_map_entries(): void
    {
        $pidMap = $this->prefix . 'meta.pid_map';
        $stale = $this->newUuid();
        $kept = $this->newUuid();
        $this->redis->hSet($pidMap, '111', $stale);
        $this->redis->hSet($pidMap, '222', $kept);
        $this->putRegistry($kept, ['state' => 'running', 'pid' => '222']);

        $this->assertLuaError(
            'missing job registry',
            fn () => $this->evalLua('load_active_monitor', [$pidMap], [$this->prefix])
        );

        $this->assertSame($stale, $this->redis->hGet($pidMap, '111'));
        $this->assertSame($kept, $this->redis->hGet($pidMap, '222'));
    }

    public function test_push_load_batch_set_pid_and_release_round_trip(): void
    {
        $first = $this->newUuid();
        $second = $this->newUuid();
        $now = \time();

        $this->pushWithLua($second, $now, 9);
        $this->pushWithLua($first, $now, 1);

        $loaded = $this->evalLua(
            'load_batch',
            [$this->key('idx.pending'), $this->key('idx.running')],
            [$this->prefix, $now, 1]
        );

        $this->assertCount(1, $loaded);
        $job = \json_decode($loaded[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($first, $job['uuid']);
        $this->assertSame('running', $this->redis->hGet($this->registryKey($first), 'state'));
        $this->assertFalse($this->redis->zScore($this->key('idx.pending'), $first));
        $this->assertNotFalse($this->redis->zScore($this->key('idx.running'), $first));

        $this->assertSame(1, (int)$this->evalLua('set_pid', [$this->registryKey($first), $this->prefix . 'meta.pid_map'], [$first, '555', '123456']));
        $this->assertSame('555', $this->redis->hGet($this->registryKey($first), 'pid'));
        $this->assertSame($first, $this->redis->hGet($this->prefix . 'meta.pid_map', '555'));

        $this->assertSame(0, (int)$this->evalLua(
            'release',
            [$this->registryKey($first), $this->key('idx.running'), $this->key('idx.pending'), $this->prefix . 'meta.pid_map', $this->key('meta.sequence')],
            [$first, $this->queue, 'wrong-pid', $now]
        ));

        $this->assertSame(1, (int)$this->evalLua(
            'release',
            [$this->registryKey($first), $this->key('idx.running'), $this->key('idx.pending'), $this->prefix . 'meta.pid_map', $this->key('meta.sequence')],
            [$first, $this->queue, '555', $now]
        ));
        $this->assertSame('pending', $this->redis->hGet($this->registryKey($first), 'state'));
        $this->assertFalse($this->redis->hGet($this->prefix . 'meta.pid_map', '555'));
        $this->assertNotFalse($this->redis->zScore($this->key('idx.pending'), $first));
    }

    public function test_load_active_fails_when_state_is_missing(): void
    {
        $running = $this->newUuid();
        $pending = $this->newUuid();
        $this->putRegistry($running, ['state' => '', 'pid' => '111']);
        $this->putRegistry($pending, ['state' => '']);
        $this->redis->zAdd($this->key('idx.running'), 1, $running);
        $this->redis->zAdd($this->key('idx.pending'), 2, $pending);

        $this->assertLuaError(
            'missing required job field "state"',
            fn () => $this->evalLua('load_active', [$this->key('idx.pending'), $this->key('idx.running')], [$this->prefix, 0, 2])
        );
    }

    public function test_load_stuck_excludes_matching_pids(): void
    {
        $included = $this->newUuid();
        $excluded = $this->newUuid();
        $this->putRegistry($included, ['state' => 'running', 'pid' => '777']);
        $this->putRegistry($excluded, ['state' => 'running', 'pid' => '888']);
        $this->redis->zAdd($this->key('idx.running'), (\time() - 5) * 1000, $included);
        $this->redis->zAdd($this->key('idx.running'), (\time() - 5) * 1000, $excluded);

        $result = $this->evalLua('load_stuck', [$this->key('idx.running')], [$this->prefix, \time(), \json_encode(['888'], JSON_THROW_ON_ERROR)]);

        $this->assertSame([$included], \array_map(
            static fn (string $json): string => \json_decode($json, true, 512, JSON_THROW_ON_ERROR)['uuid'],
            $result
        ));
    }

    public function test_mark_cancel_requested_moves_running_job_and_ignores_pending_job(): void
    {
        $running = $this->newUuid();
        $pending = $this->newUuid();
        $this->putRegistry($running, ['state' => 'running', 'pid' => '999']);
        $this->putRegistry($pending, ['state' => 'pending']);
        $this->redis->zAdd($this->key('idx.running'), 123, $running);

        $result = $this->evalLua(
            'mark_cancel_requested',
            [$this->registryKey($running), $this->key('idx.running'), $this->key('idx.cancel_requested')],
            [$running, \time(), 'running', 'cancel_requested']
        );

        $this->assertSame(1, (int)$result[0]);
        $this->assertSame('999', $result[1]);
        $this->assertSame('cancel_requested', $this->redis->hGet($this->registryKey($running), 'state'));
        $this->assertFalse($this->redis->zScore($this->key('idx.running'), $running));
        $this->assertNotFalse($this->redis->zScore($this->key('idx.cancel_requested'), $running));

        $ignored = $this->evalLua(
            'mark_cancel_requested',
            [$this->registryKey($pending), $this->key('idx.running'), $this->key('idx.cancel_requested')],
            [$pending, \time(), 'running', 'cancel_requested']
        );
        $this->assertSame(0, (int)$ignored[0]);
        $this->assertSame('pending', $this->redis->hGet($this->registryKey($pending), 'state'));
    }

    public function test_cancel_lua_cancels_pending_and_requests_running(): void
    {
        $pending = $this->newUuid();
        $running = $this->newUuid();
        $this->putRegistry($pending, ['state' => 'pending']);
        $this->putRegistry($running, ['state' => 'running', 'pid' => '1234']);
        $this->redis->zAdd($this->key('idx.pending'), 1, $pending);
        $this->redis->zAdd($this->key('idx.running'), 2, $running);
        $this->redis->hSet($this->prefix . 'meta.pid_map', '1234', $running);

        $cancelled = $this->cancelWithLua($pending);
        $this->assertSame('cancelled', $cancelled[0]);
        $this->assertSame('cancelled', $this->redis->hGet($this->registryKey($pending), 'state'));
        $this->assertFalse($this->redis->zScore($this->key('idx.pending'), $pending));
        $this->assertNotFalse($this->redis->zScore($this->key('idx.cancelled'), $pending));

        $requested = $this->cancelWithLua($running);
        $this->assertSame('cancel_requested', $requested[0]);
        $this->assertSame('1234', $requested[1]);
        $this->assertSame('cancel_requested', $this->redis->hGet($this->registryKey($running), 'state'));
        $this->assertFalse($this->redis->zScore($this->key('idx.running'), $running));
        $this->assertNotFalse($this->redis->zScore($this->key('idx.cancel_requested'), $running));
    }

    public function test_delete_job_removes_indexes_telemetry_batches_and_pid_map(): void
    {
        $uuid = $this->newUuid();
        $pidMap = $this->prefix . 'meta.pid_map';
        $telemetryJobs = $this->prefix . 'telemetry.jobs';
        $completed = $this->key('idx.completed');
        $batchOne = $this->prefix . 'telemetry.batch.batch-one';
        $batchTwo = $this->prefix . 'telemetry.batch.batch-two';

        $this->putRegistry($uuid, ['state' => 'completed', 'pid' => '333']);
        $this->redis->zAdd($completed, 1, $uuid);
        $this->redis->hSet($pidMap, '333', $uuid);
        $this->redis->hSet($telemetryJobs, $uuid, \json_encode(['batch-one', 'batch-two'], JSON_THROW_ON_ERROR));
        $this->redis->rPush($batchOne, '{"event":"one"}');
        $this->redis->rPush($batchTwo, '{"event":"two"}');

        $result = $this->evalLua('delete_job', [$this->registryKey($uuid), $telemetryJobs, $pidMap], [$uuid, $this->prefix]);

        $this->assertSame(1, (int)$result);
        $this->assertSame(0, $this->redis->exists($this->registryKey($uuid)));
        $this->assertFalse($this->redis->zScore($completed, $uuid));
        $this->assertFalse($this->redis->hGet($pidMap, '333'));
        $this->assertFalse($this->redis->hGet($telemetryJobs, $uuid));
        $this->assertSame(0, $this->redis->exists($batchOne));
        $this->assertSame(0, $this->redis->exists($batchTwo));
    }

    public function test_delete_job_rejects_running_and_cancel_requested_jobs(): void
    {
        $telemetryJobs = $this->prefix . 'telemetry.jobs';
        $pidMap = $this->prefix . 'meta.pid_map';

        foreach (['running', 'cancel_requested'] as $state) {
            $uuid = $this->newUuid();
            $this->putRegistry($uuid, ['state' => $state]);

            $result = $this->evalLua('delete_job', [$this->registryKey($uuid), $telemetryJobs, $pidMap], [$uuid, $this->prefix]);

            $this->assertSame(0, (int)$result);
            $this->assertSame(1, $this->redis->exists($this->registryKey($uuid)));
        }
    }

    public function test_mark_cancelled_refuses_terminal_jobs(): void
    {
        $uuid = $this->newUuid();
        $completed = $this->key('idx.completed');
        $cancelled = $this->key('idx.cancelled');
        $this->putRegistry($uuid, ['state' => 'completed']);
        $this->redis->zAdd($completed, 1, $uuid);

        $result = $this->evalLua(
            'mark_cancelled',
            [
                $this->registryKey($uuid),
                $this->key('idx.pending'),
                $this->key('idx.running'),
                $this->key('idx.failed'),
                $completed,
                $cancelled,
                $this->key('idx.cancel_requested'),
                $this->prefix . 'meta.pid_map',
            ],
            [$uuid, \time(), 'too late', 60, 'completed', 'failed', 'cancelled']
        );

        $this->assertSame(0, (int)$result);
        $this->assertSame('completed', $this->redis->hGet($this->registryKey($uuid), 'state'));
        $this->assertNotFalse($this->redis->zScore($completed, $uuid));
        $this->assertFalse($this->redis->zScore($cancelled, $uuid));
    }

    public function test_mark_finished_expires_registry_and_clears_pid_map(): void
    {
        $uuid = $this->newUuid();
        $running = $this->key('idx.running');
        $completed = $this->key('idx.completed');
        $pidMap = $this->prefix . 'meta.pid_map';

        $this->putRegistry($uuid, ['state' => 'running', 'pid' => '444', 'created_at' => '2000']);
        $this->redis->zAdd($running, 123, $uuid);
        $this->redis->hSet($pidMap, '444', $uuid);

        $result = $this->evalLua(
            'mark_finished',
            [$this->registryKey($uuid), $running, $this->key('idx.cancel_requested'), $completed, $pidMap],
            [$uuid, 0, \time(), '', 60]
        );

        $this->assertSame(1, (int)$result);
        $this->assertSame('completed', $this->redis->hGet($this->registryKey($uuid), 'state'));
        $this->assertFalse($this->redis->zScore($running, $uuid));
        $this->assertNotFalse($this->redis->zScore($completed, $uuid));
        $this->assertFalse($this->redis->hGet($pidMap, '444'));
        $this->assertGreaterThan(0, $this->redis->ttl($this->registryKey($uuid)));
    }

    public function test_mark_finished_cleans_stale_finished_index_entries(): void
    {
        $uuid = $this->newUuid();
        $running = $this->key('idx.running');
        $completed = $this->key('idx.completed');
        $pidMap = $this->prefix . 'meta.pid_map';

        for ($i = 0; $i < 1001; $i++) {
            $this->redis->zAdd($completed, $i, 'stale-' . $i);
        }

        $this->putRegistry($uuid, ['state' => 'running', 'pid' => '444', 'created_at' => '2000']);
        $this->redis->zAdd($running, 123, $uuid);
        $this->redis->hSet($pidMap, '444', $uuid);

        $result = $this->evalLua(
            'mark_finished',
            [$this->registryKey($uuid), $running, $this->key('idx.cancel_requested'), $completed, $pidMap],
            [$uuid, 0, \time(), '', 60]
        );

        $this->assertSame(1, (int)$result);
        $this->assertSame('completed', $this->redis->hGet($this->registryKey($uuid), 'state'));
        $this->assertFalse($this->redis->zScore($running, $uuid));
        $this->assertNotFalse($this->redis->zScore($completed, $uuid));
        $this->assertFalse($this->redis->hGet($pidMap, '444'));
        $this->assertFalse($this->redis->zScore($completed, 'stale-0'));
        $this->assertFalse($this->redis->zScore($completed, 'stale-99'));
    }

    public function test_load_batch_fails_on_malformed_numeric_job_fields(): void
    {
        $uuid = $this->newUuid();
        $now = \time();
        $this->putRegistry($uuid, ['state' => 'pending', 'attempts' => 'nope']);
        $this->redis->zAdd($this->key('idx.pending'), $now * 1000000, $uuid);

        $this->assertLuaError(
            'missing or invalid required job field: attempts',
            fn () => $this->evalLua(
                'load_batch',
                [$this->key('idx.pending'), $this->key('idx.running')],
                [$this->prefix, $now, 1]
            )
        );
    }

    public function test_cancel_fails_when_created_at_is_missing(): void
    {
        $uuid = $this->newUuid();
        $this->putRegistry($uuid, ['state' => 'pending', 'created_at' => '']);
        $this->redis->zAdd($this->key('idx.pending'), 1, $uuid);

        $this->assertLuaError('missing required job field: created_at', fn () => $this->cancelWithLua($uuid, false));
    }

    public function test_release_fails_when_priority_is_missing(): void
    {
        $uuid = $this->newUuid();
        $this->putRegistry($uuid, ['state' => 'running', 'pid' => '555', 'priority' => '']);
        $this->redis->zAdd($this->key('idx.running'), 1, $uuid);

        $this->assertLuaError(
            'missing or invalid required job field: priority',
            fn () => $this->evalLua(
                'release',
                [$this->registryKey($uuid), $this->key('idx.running'), $this->key('idx.pending'), $this->prefix . 'meta.pid_map', $this->key('meta.sequence')],
                [$uuid, $this->queue, '555', \time()]
            )
        );
    }

    public function test_retry_by_uuid_and_retry_all_restore_failed_jobs_to_pending(): void
    {
        $one = $this->newUuid();
        $two = $this->newUuid();
        $three = $this->newUuid();
        foreach ([$one, $two, $three] as $uuid) {
            $this->putRegistry($uuid, ['state' => 'failed', 'attempts' => '3', 'exception' => '{"message":"boom"}']);
            $this->redis->zAdd($this->key('idx.failed'), 1, $uuid);
            $this->redis->expire($this->registryKey($uuid), 60);
        }

        $this->assertSame(1, (int)$this->evalLua('retry_by_uuid', [$this->registryKey($one)], [$one, $this->prefix, \time()]));
        $this->assertSame('pending', $this->redis->hGet($this->registryKey($one), 'state'));
        $this->assertSame('0', $this->redis->hGet($this->registryKey($one), 'attempts'));
        $this->assertSame(-1, $this->redis->ttl($this->registryKey($one)));
        $this->assertFalse($this->redis->zScore($this->key('idx.failed'), $one));
        $this->assertNotFalse($this->redis->zScore($this->key('idx.pending'), $one));

        $this->assertSame(2, (int)$this->evalLua('retry_all', [$this->key('idx.failed'), $this->key('idx.pending'), $this->key('meta.sequence')], [$this->prefix, \time()]));
        $this->assertSame('pending', $this->redis->hGet($this->registryKey($two), 'state'));
        $this->assertSame('pending', $this->redis->hGet($this->registryKey($three), 'state'));
        $this->assertSame(0, $this->redis->zCard($this->key('idx.failed')));
    }

    public function test_push_telemetry_deduplicates_batches_applies_ttl_and_loads_events(): void
    {
        $uuid = $this->newUuid();
        $telemetryJobs = $this->prefix . 'telemetry.jobs';
        $batchKey = $this->prefix . 'telemetry.batch.batch-a';
        $eventOne = \json_encode(['event_type_id' => 1, 'created_at' => \time(), 'message' => 'one'], JSON_THROW_ON_ERROR);
        $eventTwo = \json_encode(['event_type_id' => 2, 'created_at' => \time(), 'message' => 'two'], JSON_THROW_ON_ERROR);

        $this->assertSame(1, (int)$this->evalLua('push_telemetry', [$telemetryJobs, $batchKey], [$uuid, 'batch-a', $eventOne, 60]));
        $this->assertSame(1, (int)$this->evalLua('push_telemetry', [$telemetryJobs, $batchKey], [$uuid, 'batch-a', $eventTwo, 60]));

        $this->assertSame(['batch-a'], \json_decode((string)$this->redis->hGet($telemetryJobs, $uuid), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(2, $this->redis->lLen($batchKey));
        $this->assertGreaterThan(0, $this->redis->ttl($batchKey));

        $events = $this->evalLua('load_events', [$telemetryJobs], [$uuid, $this->prefix]);
        $this->assertSame('batch-a', $events[0][0]);
        $this->assertSame([$eventOne, $eventTwo], $events[0][1]);
        $this->assertSame([], $this->evalLua('load_events', [$telemetryJobs], [$this->newUuid(), $this->prefix]));
    }

    private function evalLua(string $script, array $keys, array $argv): mixed
    {
        $path = \dirname(__DIR__, 3) . '/engine/Atomic/Queue/Drivers/lua/' . $script . '.lua';
        $source = \file_get_contents($path);
        $this->assertIsString($source, "Unable to read Lua script {$script}.");

        $this->redis->clearLastError();
        return $this->redis->eval($source, \array_merge($keys, $argv), \count($keys));
    }

    private function assertLuaError(string $expectedMessage, callable $callback): void
    {
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $message = $e->getMessage() . "\n" . (string)$this->redis->getLastError();
            $this->assertStringContainsString($expectedMessage, $message);
            return;
        }

        $message = (string)$this->redis->getLastError();
        $this->assertFalse($result, "Expected Lua error containing: {$expectedMessage}");
        $this->assertStringContainsString($expectedMessage, $message);
    }

    private function pushWithLua(string $uuid, int $availableAt, int $priority): void
    {
        $payload = \json_encode([
            'handler' => 'Tests\\Engine\\Queue\\QueueCliFakeManager@retry',
            'data' => [],
            'uuid_batch' => $this->newUuid(),
        ], JSON_THROW_ON_ERROR);

        $result = $this->evalLua(
            'push',
            [$this->registryKey($uuid), $this->key('idx.pending'), $this->key('meta.sequence'), $this->prefix . 'meta.queues'],
            [$uuid, $availableAt, $priority, $this->queue, 3, 0, 10, 0, $availableAt, 'Tests\\Engine\\Queue\\QueueCliFakeManager@retry', $payload]
        );

        $this->assertSame(1, (int)$result);
    }

    private function cancelWithLua(string $uuid): array
    {
        return $this->evalLua(
            'cancel',
            [
                $this->registryKey($uuid),
                $this->prefix . 'meta.pid_map',
                $this->key('idx.pending'),
                $this->key('idx.running'),
                $this->key('idx.cancel_requested'),
                $this->key('idx.cancelled'),
            ],
            [
                $uuid,
                $this->prefix,
                \time(),
                'pending',
                'running',
                'cancel_requested',
                'completed',
                'failed',
                'cancelled',
            ]
        );
    }

    private function putRegistry(string $uuid, array $overrides = []): void
    {
        $data = \array_merge([
            'uuid' => $uuid,
            'queue' => $this->queue,
            'state' => 'pending',
            'priority' => '5',
            'max_attempts' => '3',
            'attempts' => '0',
            'timeout' => '10',
            'retry_delay' => '0',
            'available_at' => (string)\time(),
            'created_at' => (string)\time(),
            'updated_at' => (string)\time(),
            'pid' => '',
            'process_start_ticks' => '',
            'handler' => 'Tests\\Engine\\Queue\\QueueCliFakeManager@retry',
            'payload' => \json_encode([
                'handler' => 'Tests\\Engine\\Queue\\QueueCliFakeManager@retry',
                'data' => [],
                'uuid_batch' => $this->newUuid(),
            ], JSON_THROW_ON_ERROR),
        ], $overrides);

        $this->redis->hMSet($this->registryKey($uuid), $data);
    }

    private function registryKey(string $uuid): string
    {
        return $this->prefix . 'registry.' . $uuid;
    }

    private function key(string $suffix): string
    {
        return $this->prefix . $this->queue . '.' . $suffix;
    }
}
