## Log ##

`Engine\Atomic\Core\Log` wraps F3 logging and only writes when `DEBUG_MODE` is enabled.

### Bootstrap

```php
use Engine\Atomic\Core\Log;

Log::init(\Base::instance());
```

`init()` configures:

- `DEBUG` based on `DEBUG_LEVEL`
- `DUMPS` directory under `LOGS/dumps/`
- the main log file `atomic.log`

### Levels

```php
Log::error('Something failed');
Log::warning('Retry scheduled');
Log::info('Worker started');
Log::debug('Payload received');
```

Supported methods:

- `emergency()`
- `alert()`
- `critical()`
- `error()`
- `warning()`
- `notice()`
- `info()`
- `debug()`

### Dumps

```php
$path = Log::dump('queue-job', ['uuid' => $uuid, 'payload' => $payload]);
$hivePath = Log::dumpHive();
```

These helpers write JSON files into the dumps directory and log the generated `dump_id`.

### Debug filtering

`DEBUG_LEVEL` maps to the maximum verbosity:

- `debug` or `info`: full debug logging
- `warning`: warnings and errors
- `error`: errors only

If `DEBUG_MODE` is false, no log messages or dumps are written.
