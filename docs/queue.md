## Queue ##

Atomic provides a queue manager under `Engine\Atomic\Queue\Managers\Manager` with database and Redis drivers.

### Queue manager

```php
use Engine\Atomic\Queue\Managers\Manager;

$queue = new Manager('default');

$queue->push(
    [App\Jobs\SendEmail::class, 'handle'],
    ['email' => 'user@example.com', 'subject' => 'Hello']
);

$queue->push(
    [App\Jobs\SendEmail::class, 'handle'],
    ['user@example.com', 'Hello']
);

$queue->push(
    [App\Jobs\SendEmail::class, 'handle'],
    ['email' => 'user@example.com'],
    [
        'delay' => 60,
        'priority' => 1,
        'timeout' => 30,
        'max_attempts' => 3,
        'retry_delay' => 10,
        'ttl' => 3600,
    ]
);
```

`push()` signature:

```php
push(array $payload, array $data = [], array $options = [], string $uuid = ''): bool
```

Handler rules:

- handler format must be `[ClassName::class, 'method']`
- the class and method are validated before enqueueing
- indexed `data` is passed to the handler positionally
- associative `data` is matched to handler parameter names via reflection

Required queue options are:

- `delay`
- `priority`
- `timeout`
- `max_attempts`
- `retry_delay`
- `ttl`

If an option is omitted in `push()`, the manager fills it from the configured queue definition.

### Processing and lifecycle

The manager also exposes these runtime methods:

- `pop_batch()`
- `process_job(array $job)`
- `release(array $job, int $delay)`
- `mark_failed(array $job, \Throwable $exception)`
- `mark_completed(array $job)`
- `retry()`
- `retry_by_uuid(string $uuid)`
- `delete_job(string $uuid)`

Queue telemetry is emitted automatically through `TelemetryManager` when jobs are created, fetched, retried, completed, failed, or recovered.

### CLI usage

```bash
php atomic queue/db
php atomic queue/worker <queue_name>
php atomic queue/monitor
php atomic queue/retry
php atomic queue/retry <job_uuid>
php atomic queue/retry <queue_name>
php atomic queue/delete <job_uuid>
```

Test helpers exposed by `Engine\Atomic\App\System`:

```bash
php atomic queue/test success
php atomic queue/test failure
php atomic queue/test timeout

php atomic queue/test/monitor
php atomic queue/test/monitor default
```

`queue/test` enqueues `Engine\Atomic\Queue\Tests\Test::<method>` on the `default` queue.

`queue/test/monitor` seeds stuck or in-progress test jobs for the current queue driver so `queue/monitor` can be validated.
