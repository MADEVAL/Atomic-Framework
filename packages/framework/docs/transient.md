## Transient

Atomic transients are temporary cached values stored through Atomic cache
adapters. They are intended for short-lived data and always require a TTL.

### Helpers

```php
set_transient('my_api_results', $data, 3600);

$data = get_transient('my_api_results');
if ($data === false || $data === null) {
    $data = fetch_remote_data();
    set_transient('my_api_results', $data, 3600);
}

delete_transient('my_api_results');
delete_all_transients();
```

### Drivers

The `Transient` class supports:

- `redis`
- `memcached`
- `db`
- `folder`

The `db` driver stores payloads in the `options` table and requires a
configured database connection. The `folder` driver uses
`CACHE_CONFIG.path` as its root directory.

Explicit driver selection:

```php
set_transient('stats', $stats, 300, 'redis');
$stats = get_transient('stats', 'redis');
delete_transient('stats', 'redis');
```

### Default driver selection

When no driver is passed, `Transient` uses `CacheManager::store()`. That
respects `CACHE_CONFIG.default` when it is a supported driver; otherwise it
cascades through Redis, Memcached, then folder. The DB driver is only used
when explicitly configured or when passed into a transient call.

### Important behavior

- TTL is required and must be greater than `0`.
- `set_transient()` throws `InvalidArgumentException` for non-positive TTL.
- `get_transient()` returns `false` for missing or expired keys.
- `delete_all_transients()` uses adapter `reset()` and returns `true` when
    any driver reset succeeds.
- Transients use Atomic cache adapters, not Fat-Free's `\Cache` payload
    format.

### Class usage

```php
use Engine\Atomic\Tools\Transient;

Transient::set('featured_posts', $posts, 600);
$posts = Transient::get('featured_posts');
Transient::delete('featured_posts');
```
