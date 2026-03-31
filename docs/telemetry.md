## Telemetry ##

Atomic ships a telemetry controller at `Engine\Atomic\App\Telemetry` for queue status, logs, dumps, hive inspection, and runtime diagnostics.

### Main endpoints

Current controller actions:

- `queue()` - renders the telemetry queue page
- `events()` - returns JSON event history for a queue job
- `dashboard()` - returns PHP, F3, Atomic, system, and DB info as JSON
- `hive()` - dumps the current hive as JSON
- `logs()` - returns parsed log lines from `atomic.log`
- `dump()` - returns a JSON dump file by `dump_id`

### Access control

If `TELEMETRY_ADMIN_ONLY` is enabled, `beforeroute()` redirects non-admin users to `/login`.

### Queue page filters

`queue()` reads these query params when present:

- `driver`
- `status`
- `queue`
- `uuid`
- `state`
- `date_from`
- `date_to`

### Job events API

`events()` requires:

- `driver`: `redis` or `database`
- `job_uuid`: valid UUID v4

Response shape:

```json
{
  "job_uuid": "uuid",
  "driver": "redis",
  "events": []
}
```

### Logs and dumps

`logs()` reads the tail of `LOGS/atomic.log` and tries to extract:

- timestamp
- level
- message
- optional `dump_id`

`dump()` reads JSON files from the `DUMPS` directory created by `Log::dump()` or `Log::dumpHive()`.
