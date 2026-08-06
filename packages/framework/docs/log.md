## Log ##

The Atomic logger provides structured, channel-based logging with built-in output sanitization. All log output passes through the Redactor before being written, ensuring sensitive values are never persisted to disk.

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
Log::error('Something failed');
Log::channel('queue_worker')->info('Worker started');
```

### Dumps

Dumps are structured JSON snapshots written to a dedicated `dumps/` directory, separate from log files. Each dump is identified by a UUID and can be linked from a log line via `dump_id`. Dumps are only written when debug mode is active.

```php
Log::dump('label', ['key' => $value]);
Log::dump_hive(); // snapshot of the entire F3 hive
```

Dumps are retrievable through the telemetry UI and the `/telemetry/dumps/@dump_id` endpoint.
