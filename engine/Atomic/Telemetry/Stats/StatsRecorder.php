<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;

/**
 * Buffers observations in memory and flushes them once per request on shutdown,
 * after the response is sent. Failures are swallowed: a broken or unmigrated
 * stats store must never break a request or the error handler.
 */
final class StatsRecorder
{
    private const MAX_EVENTS_PER_REQUEST = 200;

    /**
     * Buffered rows that trigger an early flush. Request-scoped processes
     * rarely get near it; long-lived workers (queue, scheduler daemon) never
     * reach shutdown between jobs, so this keeps their buffers bounded.
     */
    private const MAX_BUFFERED_ROWS = 500;

    /**
     * Tag keys with unbounded cardinality (login identifiers, rate-limit
     * keys). Hourly counters keep them for in-range "top" lists, but the
     * never-trimmed lifetime totals keep only the metric aggregate.
     */
    private const HIGH_CARDINALITY_TAGS = ['id', 'key'];

    /** Spool cap (bytes) — beyond it new batches are dropped until drained. */
    private const MAX_SPOOL_BYTES = 8 * 1024 * 1024;

    private static ?self $instance = null;

    /** @var array<string, array{metric:string, tags:string, tag_hash:string, value:int}> */
    private array $counters = [];

    /** @var list<array{metric:string, tags:string, tag_hash:string, data:string, created_at:int}> */
    private array $events = [];

    /** @var array<string, array<string, mixed>> */
    private array $exception_groups = [];

    /** @var array<string, array<string, mixed>> */
    private array $rate_keys = [];

    /** @var array<string, array<string, mixed>> */
    private array $lockouts = [];

    /** @var array<string, array<string, mixed>> */
    private array $quota_scopes = [];

    private bool $shutdown_registered = false;

    private bool $after_response = false;

    /** Test seam replacing fastcgi_finish_request()/litespeed_finish_request(). */
    private static ?\Closure $response_finisher = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** An empty tag set means the metric as a whole. */
    public function record(string $metric, int $value = 1, array $tags = []): void
    {
        if ($value === 0 || !StatsConnection::enabled()) {
            return;
        }
        $this->register_shutdown();
        $tag_hash = TagHash::of($tags);
        $key = $metric . '|' . $tag_hash;
        if (isset($this->counters[$key])) {
            $this->counters[$key]['value'] += $value;
            return;
        }
        $this->counters[$key] = [
            'metric' => $metric,
            'tags' => TagHash::canonical_json($tags),
            'tag_hash' => $tag_hash,
            'value' => $value,
            'high_cardinality' => array_intersect_key($tags, array_flip(self::HIGH_CARDINALITY_TAGS)) !== [],
        ];
        $this->flush_if_full();
    }

    /** Capped per request, so a runaway loop cannot balloon the buffer. */
    public function record_event(string $metric, array $tags, array $data): void
    {
        if (!StatsConnection::enabled() || count($this->events) >= self::MAX_EVENTS_PER_REQUEST) {
            return;
        }
        $this->register_shutdown();
        $this->events[] = [
            'metric' => $metric,
            'tags' => TagHash::canonical_json($tags),
            'tag_hash' => TagHash::of($tags),
            'data' => (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => time(),
        ];
    }

    public function record_exception_group(string $fingerprint, array $row): void
    {
        if (!StatsConnection::enabled()) {
            return;
        }
        $this->register_shutdown();
        $row['fingerprint'] = $fingerprint;
        $row['total'] = ($this->exception_groups[$fingerprint]['total'] ?? 0) + 1;
        $this->exception_groups[$fingerprint] = $row;
        $this->flush_if_full();
    }

    public function observe_rate_key(string $key, string $strategy, int $limit, int $window): void
    {
        if (!StatsConnection::enabled()) {
            return;
        }
        $this->register_shutdown();
        $this->rate_keys[TagHash::of(['k' => $key])] = [
            'key_hash' => TagHash::of(['k' => $key]),
            'key_name' => mb_substr($key, 0, 191),
            'strategy' => $strategy,
            'rate_limit' => $limit,
            'window_seconds' => $window,
            'first_seen' => time(),
            'last_seen' => time(),
        ];
    }

    public function observe_lockout(string $identifier, int $attempts, ?int $first_at, ?int $locked_until): void
    {
        if (!StatsConnection::enabled()) {
            return;
        }
        $this->register_shutdown();
        $this->lockouts[TagHash::of(['id' => $identifier])] = [
            'identifier_hash' => TagHash::of(['id' => $identifier]),
            'identifier' => mb_substr($identifier, 0, 191),
            'attempts' => $attempts,
            'first_at' => $first_at,
            'locked_until' => $locked_until,
            'updated_at' => time(),
        ];
    }

    public function observe_quota_scope(string $scope, string $tier): void
    {
        if (!StatsConnection::enabled()) {
            return;
        }
        $this->register_shutdown();
        $this->quota_scopes[$scope] = [
            'scope' => mb_substr($scope, 0, 191),
            'tier' => mb_substr($tier, 0, 64),
            'first_seen' => time(),
            'last_seen' => time(),
        ];
    }

    public function flush(): void
    {
        $batch = $this->take();
        $spooled = $this->read_spool();
        if ($batch === null && $spooled === []) {
            return;
        }
        $this->write(array_merge($spooled, $batch === null ? [] : [$batch]));
    }

    /**
     * Shutdown flush. A request that never opened the database (cached
     * pages, static-ish routes) must not pay for a MySQL connection just to
     * persist a few counters, so the batch is appended to a local spool file
     * instead and drained by the next flush that has a connection.
     */
    public function flush_on_shutdown(): void
    {
        // Run after every other shutdown handler (error pages, session
        // commit), then end the response before touching the database.
        if (!$this->after_response && $this->has_pending() && self::can_finish_response()) {
            $this->after_response = true;
            register_shutdown_function([$this, 'flush_after_response']);
            return;
        }
        $this->persist_on_shutdown();
    }

    /** Second shutdown pass: the client already has its response. */
    public function flush_after_response(): void
    {
        $this->after_response = false;
        self::finish_response();
        $this->persist_on_shutdown();
    }

    /** Replace the response finisher (tests); null restores the SAPI default. */
    public static function finish_response_using(?callable $finisher): void
    {
        self::$response_finisher = $finisher === null ? null : \Closure::fromCallable($finisher);
    }

    private static function can_finish_response(): bool
    {
        return self::$response_finisher !== null || (PHP_SAPI !== 'cli'
            && (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')));
    }

    private static function finish_response(): void
    {
        try {
            if (self::$response_finisher !== null) {
                (self::$response_finisher)();
            } elseif (PHP_SAPI === 'cli') {
                return;
            } elseif (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
        } catch (\Throwable $e) {
            // The stats write still happens; the client just waited for it.
        }
    }

    private function persist_on_shutdown(): void
    {
        if (!$this->has_pending() && !ConnectionManager::instance()->has_db()) {
            return;
        }
        if (!ConnectionManager::instance()->has_db()) {
            $batch = $this->take();
            if ($batch !== null && $this->append_spool($batch)) {
                return;
            }
            if ($batch !== null) {
                $this->write([$batch]);
            }
            return;
        }
        $this->flush();
    }

    public function flush_if_pending(): void
    {
        if (!$this->has_pending()) {
            return;
        }
        try {
            $this->flush();
        } catch (\Throwable $e) {
            Log::debug('[Stats] flush failed: ' . $e->getMessage());
        }
    }

    public function drain(): void
    {
        $spooled = $this->read_spool();
        if ($spooled !== []) {
            $this->write($spooled);
        }
    }

    public function has_pending(): bool
    {
        return $this->counters !== [] || $this->events !== [] || $this->exception_groups !== []
            || $this->rate_keys !== [] || $this->lockouts !== [] || $this->quota_scopes !== [];
    }

    /**
     * Counters carry their hour bucket, so a spooled batch lands in the right bucket later.
     *
     * @return array<string,mixed>|null
     */
    private function take(): ?array
    {
        if (!$this->has_pending()) {
            return null;
        }
        $now = time();
        $bucket = $now - ($now % 3600);
        $counters = [];
        foreach ($this->counters as $row) {
            $row['bucket_start'] = $bucket;
            $counters[] = $row;
        }
        $batch = [
            'counters' => $counters,
            'events' => $this->events,
            'exception_groups' => $this->exception_groups,
            'rate_keys' => $this->rate_keys,
            'lockouts' => $this->lockouts,
            'quota_scopes' => $this->quota_scopes,
        ];
        $this->reset();

        return $batch;
    }

    /** @param list<array<string,mixed>> $batches */
    private function write(array $batches): void
    {
        $counter_rows = [];
        $total_rows = [];
        $events = [];
        $exception_groups = [];
        $rate_keys = [];
        $lockouts = [];
        $quota_scopes = [];
        foreach ($batches as $batch) {
            foreach ((array)($batch['counters'] ?? []) as $row) {
                $counter_key = $row['metric'] . '|' . $row['tag_hash'] . '|' . $row['bucket_start'];
                if (isset($counter_rows[$counter_key])) {
                    $counter_rows[$counter_key]['value'] += $row['value'];
                } else {
                    $counter_rows[$counter_key] = [
                        'metric' => $row['metric'],
                        'tag_hash' => $row['tag_hash'],
                        'tags' => $row['tags'],
                        'bucket_start' => $row['bucket_start'],
                        'value' => $row['value'],
                    ];
                }
                $collapse = !empty($row['high_cardinality']);
                $total_key = $row['metric'] . '|' . ($collapse ? '' : $row['tag_hash']);
                if (isset($total_rows[$total_key])) {
                    $total_rows[$total_key]['value'] += $row['value'];
                    continue;
                }
                $total_rows[$total_key] = [
                    'metric' => $row['metric'],
                    'tag_hash' => $collapse ? '' : $row['tag_hash'],
                    'tags' => $collapse ? '' : $row['tags'],
                    'value' => $row['value'],
                ];
            }
            array_push($events, ...array_values((array)($batch['events'] ?? [])));
            foreach ((array)($batch['exception_groups'] ?? []) as $fingerprint => $row) {
                if (isset($exception_groups[$fingerprint])) {
                    $row['total'] = (int)($row['total'] ?? 1) + (int)($exception_groups[$fingerprint]['total'] ?? 1);
                    $row['first_seen'] = min((int)$row['first_seen'], (int)$exception_groups[$fingerprint]['first_seen']);
                }
                $exception_groups[$fingerprint] = $row;
            }
            $rate_keys = array_merge($rate_keys, (array)($batch['rate_keys'] ?? []));
            $lockouts = array_merge($lockouts, (array)($batch['lockouts'] ?? []));
            $quota_scopes = array_merge($quota_scopes, (array)($batch['quota_scopes'] ?? []));
        }

        try {
            $store = $this->store();
            $db = StatsConnection::db();
        } catch (\Throwable $e) {
            Log::debug('[Stats] flush failed: ' . $e->getMessage());
            return;
        }

        // One transaction = one commit (one log fsync) for the whole flush
        // instead of one per statement; each store still fails on its own.
        $own_transaction = $db !== null && !$db->trans();
        if ($own_transaction) {
            $db->begin();
        }
        $writes = [
            static fn () => $store->increment_counters(array_values($counter_rows)),
            static fn () => $store->upsert_totals(array_values($total_rows)),
            static fn () => $store->insert_events($events),
            static fn () => $store->upsert_exception_groups(array_values($exception_groups)),
            static fn () => $store->upsert_rate_keys(array_values($rate_keys)),
            static fn () => $store->upsert_lockouts(array_values($lockouts)),
            static fn () => $store->upsert_quota_scopes(array_values($quota_scopes)),
        ];
        foreach ($writes as $write) {
            try {
                $write();
            } catch (\Throwable $e) {
                Log::debug('[Stats] flush failed: ' . $e->getMessage());
            }
        }
        if ($own_transaction) {
            try {
                $db->commit();
            } catch (\Throwable $e) {
                Log::debug('[Stats] flush commit failed: ' . $e->getMessage());
            }
        }
    }

    private function spool_path(): ?string
    {
        $dir = (string)(App::instance()->get('TEMP') ?? '');
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }

        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'telemetry-stats.spool';
    }

    private function append_spool(array $batch): bool
    {
        $path = $this->spool_path();
        if ($path === null) {
            return false;
        }
        // Bounded: if nothing drains the spool, stop growing it.
        if (is_file($path) && (int)@filesize($path) > self::MAX_SPOOL_BYTES) {
            return true;
        }
        $line = json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return false;
        }

        return @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Read and truncate the spool under an exclusive lock, so appenders
     * (which take the same lock) never lose a line between read and truncate.
     *
     * @return list<array<string,mixed>>
     */
    private function read_spool(): array
    {
        $path = $this->spool_path();
        if ($path === null || !is_file($path) || (int)@filesize($path) === 0) {
            return [];
        }
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            return [];
        }
        $batches = [];
        try {
            if (!flock($handle, LOCK_EX)) {
                return [];
            }
            $contents = stream_get_contents($handle);
            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
            foreach (explode("\n", (string)$contents) as $line) {
                if ($line === '') {
                    continue;
                }
                $batch = json_decode($line, true);
                if (is_array($batch)) {
                    $batches[] = $batch;
                }
            }
        } finally {
            fclose($handle);
        }

        return $batches;
    }

    private function flush_if_full(): void
    {
        $buffered = count($this->counters) + count($this->events) + count($this->exception_groups)
            + count($this->rate_keys) + count($this->lockouts) + count($this->quota_scopes);
        if ($buffered >= self::MAX_BUFFERED_ROWS) {
            $this->flush();
        }
    }

    /** Test hook — drop all buffered state without flushing. */
    public function reset(): void
    {
        $this->counters = [];
        $this->events = [];
        $this->exception_groups = [];
        $this->rate_keys = [];
        $this->lockouts = [];
        $this->quota_scopes = [];
    }

    private function register_shutdown(): void
    {
        if ($this->shutdown_registered) {
            return;
        }
        $this->shutdown_registered = true;
        register_shutdown_function([$this, 'flush_on_shutdown']);
    }

    private function store(): StatsStoreInterface
    {
        return new Adapters\DB();
    }
}
