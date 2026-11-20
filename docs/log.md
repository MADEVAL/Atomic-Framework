## Log ##

The Atomic logger provides structured, channel-based logging with built-in output sanitization. The Redactor masks recognized sensitive values before log entries are written.

### Channels

Logs are written to named channels, each backed by its own file. The framework ships five built-in channels covering distinct concerns:

| Channel | Purpose |
|---|---|
| `atomic` | General application activity (default) |
| `error` | Errors only |
| `auth` | Authentication events |
| `queue_worker` | Job execution activity |
| `queue_monitor` | Queue health and monitor events |

Each channel has its own minimum level. Messages below the channel's level are silently discarded. Channels are configured via env variables (`LOG_*_DRIVER`, `LOG_*_PATH`, `LOG_*_LEVEL`) or via `config/logging.php` depending on the active loader mode.

### Levels

Standard PSR-3 levels are supported: `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug`.

```php
Log::error('Tariff purchase failed for seller {seller_id}', [
    'seller_id' => $seller->_id,
    'tariff_id' => $tariff_id,
    'exception' => $e,
]);
Log::channel('queue_worker')->info('Worker started', ['worker_id' => $worker_id]);
```

Every level method accepts an optional context array on both the default logger and named channels. Atomic appends redacted context as single-line JSON and replaces matching `{key}` placeholders with redacted scalar values. A throwable under the `exception` key adds its class, message, file, line, and stack trace.

### Dumps

Dumps are structured JSON snapshots written to a dedicated `dumps/` directory, separate from log files. Each dump is identified by a UUID and can be linked from a log line via `dump_id`. Dumps are only written when debug mode is active. The built-in `LogCleanupJob` removes dump JSON files older than `LOG_DUMPS_MAX_DAYS` (30 days by default); set it to `0` to disable dump cleanup.

```php
Log::dump('label', ['key' => $value]);
Log::dump_hive(); // snapshot of the entire F3 hive
```

Dumps are retrievable through the telemetry UI and the `/telemetry/dumps/@dump_id` endpoint.
