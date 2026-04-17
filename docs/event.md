## Event ##

`Engine\Atomic\Event\Event` is a lightweight event bus backed by the F3 hive.

```php
use Engine\Atomic\Event\Event;

$events = Event::instance();

$events->on('user.login', function ($user, array &$context, array $event) {
    $context['seen'][] = $event['name'];
    return $user;
});

$user = $events->emit('user.login', $user);
```

### Listener signature

Listeners receive three arguments:

```php
function (mixed $payload, array &$context, array $event): mixed
```

`$event` contains:

- `name`: full event name
- `key`: current event key segment
- `options`: options passed when the listener was registered

### Priorities and options

```php
$events->on('post.publish', function ($post, array &$context, array $event) {
    return $post;
}, 5, ['source' => 'admin']);
```

Lower numeric priorities run first because the implementation sorts with `ksort()`.

### Hierarchical events

Events use dot notation and propagate through parent segments:

```php
$events->on('user', fn ($payload) => $payload);
$events->on('user.register', fn ($payload) => $payload);
$events->on('user.register.success', fn ($payload) => $payload);

$events->emit('user.register.success', $payload);
```

The emitter walks upward through the hierarchy, and `broadcast()` also traverses descendants for nested keys.

### Stop propagation

If `$hold` is left as `true`, returning `false` stops further processing for that branch.

```php
$events->on('form.validate', function ($data) {
    return empty($data['email']) ? false : $data;
});
```

### Object-local events

```php
$watcher = $events->watch($object);
$watcher->on('save', fn ($data) => $data);
$watcher->emit('save', ['id' => 1]);

$events->unwatch($object);
```

### Introspection

```php
if ($events->has('user.login')) {
    $registered = $events->get_registered_events();
}

$events->off('user.login');
```
