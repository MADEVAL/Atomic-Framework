## Scheduler ##

Atomic includes a cron-style task scheduler built around:

- `Engine\Atomic\Scheduler\Scheduler`
- `Engine\Atomic\Scheduler\Event`
- `Engine\Atomic\Scheduler\Runner`
- `Engine\Atomic\Scheduler\Worker`

Use it for recurring framework tasks such as cleanup jobs, reports, cache warmers, data imports, and maintenance commands.

### Quick start

Create `routes/schedule.php`:

```php
<?php
use Engine\Atomic\Scheduler\Scheduler;

$scheduler = Scheduler::instance();

$scheduler->call(function () {
    echo "cleanup\n";
})->daily()->at('03:00')->timezone('UTC')->description('Cleanup old logs');

$scheduler->call('App\Tasks\CacheWarmer->handle')
    ->hourly()
    ->without_overlapping(300)
    ->description('Warm cache');

$scheduler->exec('php atomic reports/send-digest')
    ->daily()
    ->at('08:00')
    ->description('Send report digest');
```

Install one system cron entry that invokes the scheduler every minute:

```bash
* * * * * cd /path/to/app && php atomic schedule/run >> storage/logs/scheduler.log 2>&1
```

The scheduler decides which registered tasks are due on each invocation.

### Execution model

`Scheduler::register_schedule()` loads the application's schedule definition from:

```text
routes/schedule.php
```

There are two ways to run scheduled tasks:

- `php atomic schedule/run` runs once, executes tasks due at that moment, and exits. This is the recommended production mode when driven by system cron.
- `php atomic schedule/work [seconds]` starts a long-running worker loop. It checks for due tasks every `seconds` interval, defaulting to `60`.

Use system cron for normal deployments because process managers, logs, restarts, and missed-process handling are usually clearer. Use `schedule/work` when a long-running process is easier for your environment, and run it under a supervisor.

### Registering tasks

`call()` accepts closures, callable arrays, static method strings, and instance method strings:

```php
$scheduler->call(fn() => null)->every_minute();
$scheduler->call('App\Tasks\Report->handle')->daily();
$scheduler->call('App\Tasks\Report::run')->daily()->at('08:00');
$scheduler->call([App\Tasks\Report::class, 'run'])->hourly();
```

`job()` builds a callable from a class and method. Non-static methods are run on a new instance:

```php
$scheduler->job(App\Tasks\Report::class, 'handle', ['daily'])
    ->daily()
    ->at('07:30')
    ->description('Daily report');
```

`exec()` runs a shell command and throws if the command exits with a non-zero status:

```php
$scheduler->exec('php atomic reports/send-digest')
    ->daily()
    ->at('08:00')
    ->description('Send report digest');
```

### Frequency methods

Available frequency helpers from `ManagesFrequencies` include:

- `every_minute()`
- `every_two_minutes()`
- `every_three_minutes()`
- `every_four_minutes()`
- `every_five_minutes()`
- `every_ten_minutes()`
- `every_fifteen_minutes()`
- `every_thirty_minutes()`
- `hourly()`
- `hourly_at(int|array $offset)`
- `at('HH:MM')`
- `every_two_hours()`
- `every_three_hours()`
- `every_four_hours()`
- `every_six_hours()`
- `daily()`
- `daily_at('HH:MM')` deprecated; use `daily()->at('HH:MM')`
- `twice_daily($first, $second)`
- `twice_daily_at($first, $second, $offset)`
- `weekly()`
- `weekly_on($dayOfWeek, 'HH:MM')`
- `monthly()`
- `monthly_on($dayOfMonth, 'HH:MM')`
- `twice_monthly($first, $second, 'HH:MM')`
- `quarterly()`
- `quarterly_on($dayOfQuarter, 'HH:MM')`
- `yearly()`
- `yearly_on($month, $dayOfMonth, 'HH:MM')`
- `cron('*/5 * * * *')`

Day filters:

- `weekdays()`
- `weekends()`
- `mondays()`
- `tuesdays()`
- `wednesdays()`
- `thursdays()`
- `fridays()`
- `saturdays()`
- `sundays()`
- `days(int|array|string $days)`
- `timezone(string|\DateTimeZone $timezone)`

Day-of-week values follow cron convention:

- `0` is Sunday
- `1` is Monday
- `6` is Saturday

`at('HH:MM')` sets only the cron minute and hour fields. It preserves the rest of the current schedule, so it can be chained after frequency helpers:

```php
$scheduler->call('App\Tasks\Digest::send')->daily()->at('03:00');
$scheduler->call('App\Tasks\Billing::run')->monthly()->at('09:30');
$scheduler->call('App\Tasks\Reminder::send')->weekly()->at('08:45');
```

### Custom cron expressions

Use `cron()` for a custom five-field expression:

```php
$scheduler->call('App\Tasks\Digest::send')
    ->cron('*/15 9-17 * * 1-5')
    ->description('Business-hours digest');
```

The supported field order is:

```text
minute hour day-of-month month day-of-week
```

Examples:

```text
* * * * *       Every minute
0 * * * *       Hourly
0 3 * * *       Daily at 03:00
*/5 * * * *     Every five minutes
0 9 * * 1       Mondays at 09:00
0 0 1 1 *       Every January 1 at midnight
```

Invalid cron expressions are rejected by `Event::cron()` with `InvalidArgumentException`. `CronExpression::matches()` returns `false` for invalid expressions, and `CronExpression::get_next_run_date()` returns `null` for invalid expressions.

### Event controls

```php
$scheduler->call($task)
    ->when(fn() => app()->get('APP_ENV') === 'production')
    ->skip(fn() => false)
    ->before(fn($event) => null)
    ->after(fn($event) => null)
    ->on_success(fn($event) => null)
    ->on_failure(fn($exception) => null)
    ->without_overlapping(300)
    ->run_in_maintenance_mode()
    ->description('Example task');
```

`when()` adds a filter. The task runs only when all filters return `true`.

`skip()` adds a reject callback. The task is skipped when any reject callback returns `true`.

`before()` and `after()` callbacks receive the `Event`.

`on_success()` callbacks receive the `Event`.

`on_failure()` callbacks receive the thrown exception.

### Overlap locking

Use `without_overlapping()` when a task must not start while a previous run is still active:

```php
$scheduler->call('App\Tasks\ImportFeed->handle')
    ->every_ten_minutes()
    ->without_overlapping(900)
    ->description('Import feed');
```

The argument is a lock TTL in seconds. The scheduler uses `Engine\Atomic\Mutex\Mutex` and releases the lock after the task finishes. If the process dies before release, the TTL limits how long the lock can block future runs.

By default, the lock name is derived from the cron expression and callback description. You can inspect lock details through `Event::get_summary()`.

### Maintenance mode

When `MAINTENANCE_MODE` is enabled, scheduled tasks are skipped by default:

```php
$scheduler->call('App\Tasks\SendNewsletter::run')
    ->daily()
    ->at('09:00')
    ->description('Send newsletter');
```

Opt in only tasks that are safe during maintenance:

```php
$scheduler->call('App\Tasks\CleanupTempFiles::run')
    ->every_thirty_minutes()
    ->run_in_maintenance_mode()
    ->description('Cleanup temp files');
```

### CLI commands

```bash
php atomic schedule/run
php atomic schedule/work
php atomic schedule/work 30
php atomic schedule/list
php atomic schedule/test
php atomic schedule/test "*/5 * * * *"
php atomic schedule/help
```

Command behavior:

- `schedule/run` loads `routes/schedule.php`, runs due tasks, prints formatted task results, and exits.
- `schedule/work [seconds]` repeatedly checks and runs due tasks.
- `schedule/list` lists registered tasks, expressions, next run times, and whether each task is due now.
- `schedule/test` validates the full scheduler configuration.
- `schedule/test "expr"` validates one cron expression and shows upcoming run times.
- `schedule/help` prints command and API examples.

### Programmatic usage

```php
use Engine\Atomic\Scheduler\Scheduler;

$scheduler = Scheduler::instance();
$scheduler->register_schedule();

$due = $scheduler->due_events();
$results = $scheduler->run();
$summary = $scheduler->summary();
```

Force all registered events to run, even when they are not due:

```php
$results = $scheduler->run(true);
```

Use `Runner` when you want the CLI-style response structure:

```php
use Engine\Atomic\Scheduler\Runner;

$response = (new Runner($scheduler))->run_due_tasks();
```

The `Scheduler::run()` result is an array of task result rows:

```php
[
    [
        'id' => '...',
        'description' => 'Cleanup old logs',
        'expression' => '0 3 * * *',
        'started_at' => '2026-05-14 03:00:00',
        'success' => true,
        'output' => "cleanup\n",
        'error' => null,
        'duration' => 12.34,
        'finished_at' => '2026-05-14 03:00:00',
    ],
]
```

The `Runner::run_due_tasks()` response includes counts and summary data:

```php
[
    'due_count' => 1,
    'results' => [...],
    'summary' => [
        'successful' => 1,
        'failed' => 0,
    ],
]
```

### Introspection

Use `summary()` or `Event::get_summary()` to inspect registered events:

```php
[
    'id' => '...',
    'description' => 'Cleanup old logs',
    'expression' => '0 3 * * *',
    'next_run' => '2026-05-15 03:00:00',
    'is_due' => false,
    'without_overlapping' => true,
    'runs_in_maintenance_mode' => false,
    'mutex_name' => 'schedule-...',
    'mutex_ttl_minutes' => 300,
    'mutex_is_locked' => false,
]
```

Note: `mutex_ttl_minutes` is the current summary key name, but the stored value comes from `without_overlapping()` and is passed to the mutex layer as seconds.

### Troubleshooting

If no tasks run:

- Confirm `routes/schedule.php` exists and is readable.
- Run `php atomic schedule/list` to confirm tasks are registered.
- Run `php atomic schedule/test` to validate configured cron expressions.
- Check whether the task is due now with `schedule/list`.
- Check whether `MAINTENANCE_MODE` is enabled.
- Check `when()` and `skip()` callbacks.
- Check overlap locks if the task uses `without_overlapping()`.

If a custom cron expression fails:

- Use exactly five fields.
- Use numeric values, `*`, ranges, lists, and step values supported by the parser.
- Keep minute values between `0` and `59`.
- Keep hour values between `0` and `23`.
- Keep month values between `1` and `12`.
- Keep day-of-week values between `0` and `6`.

If `schedule/work` stops unexpectedly, run it under a process supervisor and check application logs for scheduler errors.
