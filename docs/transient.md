## Transient ##

Atomic transients are temporary cached values backed by the configured cache layer.

Helpers:

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
- `database`

Explicit driver selection:

```php
set_transient('stats', $stats, 300, 'redis');
$stats = get_transient('stats', 'redis');
delete_transient('stats', 'redis');
```

### Important behavior

- TTL is required and must be greater than `0`
- `set_transient()` throws `InvalidArgumentException` for non-positive TTL
- when no driver is passed, Atomic uses `CacheManager::cascade()`
- `delete_all_transients()` can clear a single driver or all supported drivers

### Class usage

```php
use Engine\Atomic\Tools\Transient;

Transient::set('featured_posts', $posts, 600);
$posts = Transient::get('featured_posts');
Transient::delete('featured_posts');
```
