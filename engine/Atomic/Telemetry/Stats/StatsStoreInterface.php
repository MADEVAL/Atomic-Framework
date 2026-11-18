<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

interface StatsStoreInterface
{
    /** @param list<array{metric:string,tag_hash:string,tags:string,bucket_start:int,value:int}> $rows */
    public function increment_counters(array $rows): void;

    /** @param list<array{metric:string,tag_hash:string,tags:string,value:int}> $rows */
    public function upsert_totals(array $rows): void;

    /** @param list<array{metric:string,tag_hash:string,tags:string,data:string,created_at:int}> $rows */
    public function insert_events(array $rows): void;

    /** @param list<array<string,mixed>> $rows fingerprint-keyed group rows */
    public function upsert_exception_groups(array $rows): void;

    /** @param list<array<string,mixed>> $rows key_hash-keyed rate-limit key rows */
    public function upsert_rate_keys(array $rows): void;

    /** @param list<array<string,mixed>> $rows identifier_hash-keyed lockout rows */
    public function upsert_lockouts(array $rows): void;

    /** @param list<array<string,mixed>> $rows scope-keyed quota scope rows */
    public function upsert_quota_scopes(array $rows): void;

    /** @return array<int, int> bucket_start => summed value */
    public function series(string $metric, ?string $tag_hash, int $from, int $to): array;

    /** @return array<string, array<int, int>> tag_hash => (bucket_start => value) */
    public function series_by_tag(string $metric, int $from, int $to): array;

    /** @return list<array{key_name:string,strategy:string,rate_limit:int,window_seconds:int,first_seen:int,last_seen:int}> */
    public function recent_rate_keys(?int $since, int $limit): array;

    /** @return list<array{scope:string,tier:string,first_seen:int,last_seen:int}> */
    public function quota_scopes_list(?int $since, int $limit): array;

    public function lifetime(string $metric, ?string $tag_hash): int;

    /** @return list<array{tags:array,value:int}> */
    public function lifetime_by_tag(string $metric, int $limit = 50): array;

    /** @return list<array{tag_hash:string,tags:array,total:int}> */
    public function top_tags(string $metric, int $from, int $to, int $limit): array;

    /** @return list<array{tags:array,data:array,created_at:int}> */
    public function recent_events(string $metric, ?string $tag_hash, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function exception_groups(?int $since, int $limit): array;

    public function exception_groups_count(): int;

    /** @return array<string,mixed>|null */
    public function exception_group(string $fingerprint): ?array;

    public function trim_counters(int $before): int;

    public function trim_events(int $before): int;

    public function prune_rate_keys(int $before): int;

    public function prune_lockouts(int $before): int;
}
