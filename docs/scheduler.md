## Scheduler ##

The scheduler lives in `Engine\Atomic\Scheduler\Scheduler` and registers `Engine\Atomic\Scheduler\Event` instances.

### Schedule file

`Scheduler::register_schedule()` currently loads the application's schedule definition from:

```text
routes/schedule.php
```

Example:

```php
<?php
use Engine\Atomic\Scheduler\Scheduler;

$scheduler = Scheduler::instance();

$scheduler->call(function () {
    echo "cleanup\n";
})->daily_at('03:00')->description('Cleanup old logs');

$scheduler->call('App\Tasks\CacheWarmer->handle')
    ->hourly()
    ->without_overlapping()
    ->description('Warm cache');

$scheduler->exec('php atomic queue/monitor')
    ->every_five_minutes()
    ->description('Queue monitor');
```

### Registering tasks

```php
$scheduler->call(fn() => null);
$scheduler->call('App\Tasks\Report->handle');
$scheduler->call('App\Tasks\Report::run');
$scheduler->job(App\Tasks\Report::class, 'handle', ['daily']);
$scheduler->exec('php artisan something');
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
- `every_two_hours()`
- `every_three_hours()`
- `every_four_hours()`
- `every_six_hours()`
- `daily()`
- `daily_at('HH:MM')`
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

### Event controls

```php
$scheduler->call($task)
    ->when(fn() => app()->get('APP_ENV') === 'production')
    ->skip(fn() => false)
    ->before(fn($event) => null)
    ->after(fn($event) => null)
    ->on_success(fn($event) => null)
    ->on_failure(fn($exception) => null)
    ->without_overlapping(60)
    ->description('Example task');
```

Notes:

- `without_overlapping()` uses `Engine\Atomic\Mutex\Mutex`
- the overlap TTL argument is passed in seconds by the current implementation
- `run()` skips tasks that are not due unless `force_run` is set to `true`
- if `MAINTENANCE_MODE` is enabled, tasks are skipped unless the event explicitly allows maintenance mode

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

### Programmatic usage

```php
use Engine\Atomic\Scheduler\Scheduler;

$scheduler = Scheduler::instance();
$scheduler->register_schedule();

$due = $scheduler->due_events();
$results = $scheduler->run();
$all = $scheduler->summary();
```
