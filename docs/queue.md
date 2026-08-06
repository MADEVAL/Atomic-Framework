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
- `cancel(string $uuid)` Redis only
- `is_cancel_requested(string $uuid)` Redis only
- `mark_cancelled(array $job, ?string $reason = null)` Redis only
- `retry()`
- `retry_by_uuid(string $uuid)`
- `delete_job(string $uuid)`

Queue telemetry is emitted automatically through `TelemetryManager` when jobs are created, fetched, retried, completed, failed, recovered, cancellation-requested, or cancelled.

### Job states and telemetry

Queue job state values are:

- `pending`
- `running`
- `cancel_requested`
- `cancelled`
- `completed`
- `failed`

Use `state` as the standard queue job state field. UI/API filters should use `state`.

Telemetry state filters:

```text
?state=pending
?state=running
?state=cancel_requested
?state=cancelled
?state=completed
?state=failed
```

Redis stores every active and finished job in its registry with a `state` field and keeps state-specific sorted indexes. The database driver maps rows in the active jobs table to `pending` or `running`, rows in the failed table to `failed`, and rows in the completed table to `completed`. Database cancellation states are not available.

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
php atomic queue/test failed
php atomic queue/test timeout
php atomic queue/test cancel_requested
php atomic queue/test cancelled
php atomic queue/test all

php atomic queue/test/monitor
php atomic queue/test/monitor default
```

`queue/test` enqueues `Engine\Atomic\Queue\Tests\TestJob::<method>` on the
`default` queue.
Supported types: `success`, `failed`, `timeout`, `cancel_requested`, `cancelled`,
`all` (aliases: `completed`, `failure`, `cancel`, `canceled`, `request_cancelled`,
`request_canceled`).

`queue/test/monitor` seeds stuck or in-progress test jobs for the current queue
driver so `queue/monitor` can be validated.

### Job cancellation

Job cancellation is supported by the Redis queue driver. The database queue
driver throws a `RuntimeException` for cancellation APIs, and
`Manager::supports_cancel()` returns `false`.

Cancel by UUID:

```php
$cancelled_or_requested = $queue->cancel($uuid);
```

Flow:

- `pending` jobs move directly to `cancelled`.
- `running` jobs move to `cancel_requested`, the optional `cancel_handler` runs, the worker PID receives `SIGUSR1`, then the worker marks the job `cancelled`.
- `completed`, `failed`, and `cancelled` jobs are no-op.
- Cancelled jobs are never retried.

Cancellation is cooperative for running jobs. The framework requests cancellation and signals the worker, but PHP code that is blocked in I/O, an HTTP request, or a long CPU loop may not observe the signal until control returns to PHP. Long-running jobs should be split into chunks and should keep their own cleanup in `try`/`catch`/`finally` blocks.

State guarantees for running jobs:

- If the worker receives `SIGUSR1` and `JobCancelledException` reaches the worker, the worker marks the job `cancelled`.
- If job code catches `JobCancelledException`, cleans up, and rethrows it, the worker marks the job `cancelled`.
- If job code catches `JobCancelledException` and returns normally, `mark_completed()` still checks the queue state and converts the job to `cancelled` instead of `completed`.
- If a cancel-requested job becomes stuck, the monitor treats it as cancelled instead of retrying it.

Recommended local cleanup pattern:

```php
use Engine\Atomic\Queue\Exceptions\JobCancelledException;

class ImportJob
{
    public function handle(int $import_id): void
    {
        try {
            foreach ($this->chunks($import_id) as $chunk) {
                $this->process_chunk($chunk);
            }
        } catch (JobCancelledException $e) {
            $this->cleanup_local_state($import_id);
            throw $e;
        } finally {
            $this->close_open_resources();
        }
    }
}
```

Add optional cancel handler when pushing:

```php
$queue->push(
    [ImportJob::class, 'handle'],
    ['import_id' => 10],
    ['cancel_handler' => [ImportJob::class, 'cancel']]
);
```

Short form uses same class as main queued handler:

```php
$queue->push(
    [ImportJob::class, 'handle'],
    ['import_id' => 10],
    ['cancel_handler' => 'cancel']
);
```

Cancel handler receives original job data. If it accepts `$job`, add named `job` parameter.

`cancel_handler` runs in the process that calls `$queue->cancel($uuid)`, not inside the already-running worker job. Use it for external cleanup such as marking application state as cancelling, deleting shared temporary files, or calling a provider API to cancel remote work. Use `try`/`catch`/`finally` in the job handler for resources owned by the running worker process.

Local cancel (`$queue->cancel($uuid)`) updates Atomic queue state and signals local worker PID. Remote APIs owned by job code must be cancelled by your `cancel_handler` (for example, call provider API to stop remote import/transcode/task).

Queue options must be serializable. Closures are not allowed in `cancel_handler` or other queue options; use `[ClassName::class, 'method']` or `'method'`.
