# Cache

Atomic cache stores use one TTL contract across the supported drivers.

## TTL semantics

`ttl` is expressed in seconds.

- `ttl > 0` means the entry expires after that many seconds.
- `ttl = 0` means the entry does not expire automatically.
- `ttl < 0` is normalized to `0`, so it also does not expire automatically.

Non-expiring means "not expired by Atomic's TTL handling". Entries can still disappear when the backing store evicts data, is flushed, is restarted without persistence, or when the cache namespace generation is invalidated.

## Generation keys

Cache drivers use a namespace generation key to make `reset()` cheap. A reset invalidates all entries by incrementing the generation, so old entries become invisible without scanning and deleting every old key immediately. This remains the recommended default for request handlers, workers, and normal application code.

For the Memcached driver, the generation key is written with:

```php
private const GEN_TTL = 0;
```

In Memcached, an expiration value of `0` means no automatic expiration. Atomic uses the same effective behavior for generation data in the other drivers:

- Redis stores the generation key without an expiration.
- DB stores the generation option with no expiration.
- Folder stores the generation in namespace metadata.

## Maintenance operations

Atomic exposes three cache maintenance operations with separate semantics:

| Operation | API | CLI | Meaning |
| --- | --- | --- | --- |
| Invalidate | `CacheStoreInterface::reset()` | `php atomic cache/invalidate` | Advance the namespace generation. Old entries become unreachable, but physical files/keys/rows may remain. |
| Clear | `PurgeableCacheStoreInterface::purge()` | `php atomic cache/clear` | Physically delete namespaced cache entries where the driver can safely enumerate them. |
| Prune | `PrunableCacheStoreInterface::prune()` | `php atomic cache/prune` | Delete expired/corrupt stale entries only. Valid cache entries are not cleared. |

`cache/clear` now means physical deletion where supported. Use `cache/invalidate` for the old generation-bump behavior.

| Driver | Invalidate | Clear | Prune |
| --- | --- | --- | --- |
| Folder | Yes; generation metadata is advanced. | Yes; shard `*.cache` files are removed while namespace metadata remains. | Yes; expired/corrupt `.cache` files are removed. |
| DB | Yes; generation option is advanced. | Yes; Atomic cache option rows for the namespace are removed across generations, including the generation key. | Yes; expired cache options across generations are removed. |
| Redis | Yes; generation key is advanced. | Yes; cursor-based `SCAN` is used with strict namespace matching. `KEYS`, `FLUSHDB`, and `FLUSHALL` are not used. | No; Redis TTL cleanup is handled by Redis. Use `cache/clear` for physical namespace cleanup. |
| Memcached | Yes; generation key is advanced. | No; Memcached cannot safely enumerate namespaced keys, and Atomic does not use `getAllKeys()` or global `flush()`. | No. |

`redis/clear` remains as a compatibility alias for Redis physical purge, but `cache/clear` is preferred.

## Expired-entry cleanup

Expired cache entries are cleaned differently depending on the driver.

| Driver | Expiry behavior | Physical cleanup |
| --- | --- | --- |
| Redis | Redis expires keys automatically when `ttl > 0`. | Redis removes expired keys. Atomic also deletes invalid or expired payloads if encountered on read. |
| Memcached | Memcached expires keys automatically when `ttl > 0`. | Memcached removes expired keys according to its normal expiration and eviction behavior. Atomic deletes invalid or expired payloads if encountered on read. |
| DB | Atomic checks the signed payload TTL on read. | Expired rows are deleted lazily on `get()` / `exists()`, or by calling `prune()`. |
| Folder | Atomic checks the signed payload TTL on read. | Expired files are deleted lazily on `get()` / `exists()`, by calling `prune()`, and by a small probabilistic cleanup pass during writes. |

`reset()` does not physically delete old keys, DB rows, or folder files. It only increments the namespace generation. Old entries stop being visible, but storage is reclaimed later through lazy cleanup, explicit pruning, or physical purge.

## Pruning DB and Folder caches

The DB and Folder drivers implement `PrunableCacheStoreInterface`, so they can be cleaned explicitly:

```php
use Engine\Atomic\Core\CacheManager;

$cache = CacheManager::instance()->db();
$cache->prune();

$cache = CacheManager::instance()->folder();
$cache->prune();
```

Use pruning for long-running applications or high-churn caches, especially when many entries are written with short TTLs.

## Scheduler cleanup task

Create or update `routes/schedule.php` and register a periodic pruning task:

```php
<?php

use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Scheduler\Scheduler;

$scheduler = Scheduler::instance();

$scheduler->call(function (): void {
    $cache = CacheManager::instance();

    $cache->db()->prune();
    $cache->folder()->prune();
})
    ->daily()
    ->at('03:00')
    ->without_overlapping(3600)
    ->description('Prune expired cache entries');
```

Run due scheduled tasks from system cron:

```cron
* * * * * cd /path/to/project && php atomic schedule/run
```

You can inspect scheduler configuration with:

```sh
php atomic schedule/list
php atomic schedule/test
```

For heavy cache churn, schedule pruning more often, for example hourly:

```php
$scheduler->call(function (): void {
    $cache = CacheManager::instance();

    $cache->db()->prune();
    $cache->folder()->prune();
})
    ->hourly()
    ->without_overlapping(3600)
    ->description('Prune expired cache entries');
```
